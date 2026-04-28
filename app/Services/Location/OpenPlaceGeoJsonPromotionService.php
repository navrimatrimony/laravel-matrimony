<?php

namespace App\Services\Location;

use App\Models\CityAlias;
use App\Models\LocationOpenPlaceSuggestion;
use Illuminate\Support\Facades\Schema;

class OpenPlaceGeoJsonPromotionService
{
    /**
     * @return array{
     *   scanned:int,
     *   promoted:int,
     *   skipped:int,
     *   invalid:int,
     *   path:string,
     *   manifest_path:string,
     *   count:int,
     *   checksum:string,
     *   version:string
     * }
     */
    public function promoteToGeoJson(string $targetPath, bool $dryRun = false): array
    {
        $entries = $this->readJsonArray($targetPath);
        $existingIndex = $this->buildEntryIndex($entries);
        $stateCodeMap = $this->loadStateCodeMap();
        $manifestPath = dirname($targetPath).DIRECTORY_SEPARATOR.'geo_manifest.json';

        $scanned = 0;
        $promoted = 0;
        $skipped = 0;
        $invalid = 0;
        $processedSuggestionIds = [];

        $rows = LocationOpenPlaceSuggestion::query()
            ->with(['resolvedCity.taluka.district.state', 'resolvedCity'])
            ->where('status', 'approved')
            ->whereNotNull('resolved_city_id')
            ->whereNull('merged_into_suggestion_id')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $scanned++;
            $city = $row->resolvedCity;
            $state = $city?->taluka?->district?->state;
            $district = $city?->taluka?->district;
            $taluka = $city?->taluka;
            if ($city === null || $state === null || $district === null || $taluka === null) {
                $invalid++;
                continue;
            }

            $stateName = trim((string) ($state->name ?? ''));
            $stateCode = $stateCodeMap[$this->key($stateName)] ?? null;
            if ($stateCode === null || $stateCode === '') {
                $invalid++;
                continue;
            }

            $cityName = trim((string) ($city->name ?? ''));
            $districtName = trim((string) ($district->name ?? ''));
            $talukaName = trim((string) ($taluka->name ?? ''));
            if ($cityName === '' || $districtName === '' || $talukaName === '') {
                $invalid++;
                continue;
            }

            $entryKey = $this->entryKey($stateCode, $districtName, $talukaName, $cityName);
            $aliases = $this->collectAliasesForCity((int) $city->id);

            if (isset($existingIndex[$entryKey])) {
                $idx = $existingIndex[$entryKey];
                $before = is_array($entries[$idx]['aliases'] ?? null) ? $entries[$idx]['aliases'] : [];
                $mergedAliases = $this->mergeAliases($before, $aliases);
                $entries[$idx]['aliases'] = $mergedAliases;
                $skipped++;
                $processedSuggestionIds[] = (int) $row->id;
                continue;
            }

            $entries[] = [
                'name' => $cityName,
                'statecode' => $stateCode,
                'district' => $districtName,
                'taluka' => $talukaName,
                'aliases' => $aliases,
            ];
            $existingIndex[$entryKey] = count($entries) - 1;
            $promoted++;
            $processedSuggestionIds[] = (int) $row->id;
        }

        $entries = $this->sortEntries($entries);
        $citiesJson = $this->encodeJson($entries);
        $checksum = 'sha256:'.hash('sha256', $citiesJson);
        $version = $this->nextVersion($manifestPath);
        $manifest = $this->buildManifest($version, count($entries), $checksum);

        if (! $dryRun) {
            $this->writeString($targetPath, $citiesJson);
            $this->writeString($manifestPath, $this->encodeJson($manifest));
            $this->markPromotedIfColumnExists($processedSuggestionIds);
        }

        return [
            'scanned' => $scanned,
            'promoted' => $promoted,
            'skipped' => $skipped,
            'invalid' => $invalid,
            'path' => $targetPath,
            'manifest_path' => $manifestPath,
            'count' => count($entries),
            'checksum' => $checksum,
            'version' => $version,
        ];
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function markPromotedIfColumnExists(array $ids): void
    {
        if ($ids === [] || ! Schema::hasColumn('location_open_place_suggestions', 'promoted_at')) {
            return;
        }

        LocationOpenPlaceSuggestion::query()
            ->whereIn('id', $ids)
            ->update(['promoted_at' => now()]);
    }

    /**
     * @return array<int, string>
     */
    private function collectAliasesForCity(int $cityId): array
    {
        $aliases = CityAlias::query()
            ->where('city_id', $cityId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['alias_name', 'normalized_alias']);

        $out = [];
        foreach ($aliases as $alias) {
            foreach ([(string) $alias->alias_name, (string) $alias->normalized_alias] as $value) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
                $k = $this->key($value);
                if (! isset($out[$k])) {
                    $out[$k] = $value;
                }
            }
        }

        return array_values($out);
    }

    /**
     * @param  array<int, string>  $base
     * @param  array<int, string>  $incoming
     * @return array<int, string>
     */
    private function mergeAliases(array $base, array $incoming): array
    {
        $out = [];
        foreach (array_merge($base, $incoming) as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $k = $this->key($value);
            if (! isset($out[$k])) {
                $out[$k] = $value;
            }
        }

        $vals = array_values($out);
        usort($vals, fn (string $a, string $b) => strnatcasecmp($a, $b));

        return $vals;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, int>
     */
    private function buildEntryIndex(array $entries): array
    {
        $out = [];
        foreach ($entries as $idx => $entry) {
            $stateCode = trim((string) ($entry['statecode'] ?? ''));
            $district = trim((string) ($entry['district'] ?? ''));
            $taluka = trim((string) ($entry['taluka'] ?? ''));
            $name = trim((string) ($entry['name'] ?? ''));
            if ($stateCode === '' || $district === '' || $taluka === '' || $name === '') {
                continue;
            }
            $out[$this->entryKey($stateCode, $district, $taluka, $name)] = $idx;
        }

        return $out;
    }

    private function entryKey(string $stateCode, string $district, string $taluka, string $name): string
    {
        return implode('|', [
            $this->key($stateCode),
            $this->key($district),
            $this->key($taluka),
            $this->key($name),
        ]);
    }

    private function key(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }

    /**
     * @return array<string, string>
     */
    private function loadStateCodeMap(): array
    {
        $path = base_path('database/seeders/data/geo/states.json');
        $rows = $this->readJsonArray($path);
        $map = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['statenameenglish'] ?? $row['name'] ?? ''));
            $code = trim((string) ($row['statecode'] ?? ''));
            if ($name === '' || $code === '') {
                continue;
            }
            $map[$this->key($name)] = $code;
        }

        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readJsonArray(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static fn ($row) => is_array($row)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function sortEntries(array $entries): array
    {
        foreach ($entries as $idx => $entry) {
            $aliases = is_array($entry['aliases'] ?? null) ? $entry['aliases'] : [];
            $aliases = array_values(array_filter(array_map(static fn ($v) => trim((string) $v), $aliases), static fn ($v) => $v !== ''));
            $aliases = array_values(array_unique($aliases));
            usort($aliases, fn (string $a, string $b) => strnatcasecmp($a, $b));
            $entries[$idx]['aliases'] = $aliases;
        }

        usort($entries, function (array $a, array $b): int {
            $ak = [
                strtolower(trim((string) ($a['statecode'] ?? ''))),
                strtolower(trim((string) ($a['district'] ?? ''))),
                strtolower(trim((string) ($a['taluka'] ?? ''))),
                strtolower(trim((string) ($a['name'] ?? ''))),
            ];
            $bk = [
                strtolower(trim((string) ($b['statecode'] ?? ''))),
                strtolower(trim((string) ($b['district'] ?? ''))),
                strtolower(trim((string) ($b['taluka'] ?? ''))),
                strtolower(trim((string) ($b['name'] ?? ''))),
            ];
            return $ak <=> $bk;
        });

        return array_values($entries);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildManifest(string $version, int $count, string $checksum): array
    {
        return [
            'version' => $version,
            'generated_at' => now()->toIso8601String(),
            'files' => [
                'cities.json' => [
                    'count' => $count,
                    'checksum' => $checksum,
                ],
            ],
        ];
    }

    private function nextVersion(string $manifestPath): string
    {
        $today = now()->format('Y-m-d');
        $seq = 1;
        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            $prev = is_array($manifest) ? (string) ($manifest['version'] ?? '') : '';
            if (preg_match('/^(\d{4}-\d{2}-\d{2})-(\d{2})$/', $prev, $m) === 1 && $m[1] === $today) {
                $seq = ((int) $m[2]) + 1;
            }
        }

        return sprintf('%s-%02d', $today, $seq);
    }

    /**
     * @param  array<int, array<string, mixed>>|array<string, mixed>  $payload
     */
    private function encodeJson(array $payload): string
    {
        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
    }

    private function writeString(string $path, string $content): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $content);
    }
}

