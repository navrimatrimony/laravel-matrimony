<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\District;
use App\Models\LocationAlias;
use App\Models\State;
use App\Models\Taluka;
use App\Services\Location\LocationNormalizationService;
use Illuminate\Console\Command;

class VerifyGeoIntegrityCommand extends Command
{
    protected $signature = 'location:verify-geo-integrity
        {--path= : Target cities JSON path (default: database/seeders/data/geo/cities.json)}
        {--strict : Return non-zero exit code on integrity issues}';

    protected $description = 'Verify cities.json SSOT integrity against DB hierarchy and aliases';

    public function handle(LocationNormalizationService $normalizer): int
    {
        $path = (string) ($this->option('path') ?: base_path('database/seeders/data/geo/cities.json'));
        $strict = (bool) $this->option('strict');

        $rows = $this->readJsonArray($path);
        $stateNameByCode = $this->stateNameByCode();

        $processed = 0;
        $valid = 0;
        $invalidStructure = 0;
        $duplicateKeys = 0;
        $missingHierarchy = 0;
        $missingAliasBindings = 0;

        $seen = [];

        foreach ($rows as $row) {
            $processed++;
            $stateCode = trim((string) ($row['statecode'] ?? ''));
            $districtName = trim((string) ($row['district'] ?? ''));
            $talukaName = trim((string) ($row['taluka'] ?? ''));
            $cityName = trim((string) ($row['name'] ?? ''));
            $aliases = is_array($row['aliases'] ?? null) ? $row['aliases'] : [];

            if ($stateCode === '' || $districtName === '' || $talukaName === '' || $cityName === '') {
                $invalidStructure++;

                continue;
            }

            $entryKey = mb_strtolower(implode('|', [$stateCode, $districtName, $talukaName, $cityName]), 'UTF-8');
            if (isset($seen[$entryKey])) {
                $duplicateKeys++;
            } else {
                $seen[$entryKey] = true;
            }

            $stateName = $stateNameByCode[$this->key($stateCode)] ?? null;
            if ($stateName === null) {
                $missingHierarchy++;

                continue;
            }

            $state = State::query()
                ->whereRaw('LOWER(TRIM(name)) = ?', [$this->key($stateName)])
                ->first();
            if ($state === null) {
                $missingHierarchy++;

                continue;
            }

            $district = District::query()
                ->where('parent_id', (int) $state->id)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$this->key($districtName)])
                ->first();
            if ($district === null) {
                $missingHierarchy++;

                continue;
            }

            $taluka = Taluka::query()
                ->where('parent_id', (int) $district->id)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$this->key($talukaName)])
                ->first();
            if ($taluka === null) {
                $missingHierarchy++;

                continue;
            }

            $city = City::query()
                ->where('parent_id', (int) $taluka->id)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$this->key($cityName)])
                ->first();
            if ($city === null) {
                $missingHierarchy++;

                continue;
            }

            foreach ($aliases as $aliasValue) {
                if (! is_scalar($aliasValue)) {
                    continue;
                }
                $normalizedAlias = $normalizer->mergeKeyFromRaw((string) $aliasValue);
                if ($normalizedAlias === '') {
                    continue;
                }

                $bound = LocationAlias::query()
                    ->where('location_id', (int) $city->id)
                    ->where('normalized_alias', $normalizedAlias)
                    ->where('is_active', true)
                    ->exists();

                if (! $bound) {
                    $missingAliasBindings++;
                }
            }

            $valid++;
        }

        $this->line('Path: '.$path);
        $this->line('Processed: '.$processed);
        $this->line('Valid: '.$valid);
        $this->line('Invalid structure: '.$invalidStructure);
        $this->line('Duplicate keys in JSON: '.$duplicateKeys);
        $this->line('Missing hierarchy in DB: '.$missingHierarchy);
        $this->line('Missing alias bindings in DB: '.$missingAliasBindings);

        $hasIssues = $invalidStructure > 0 || $duplicateKeys > 0 || $missingHierarchy > 0 || $missingAliasBindings > 0;
        if (! $hasIssues) {
            $this->info('Geo integrity verified: JSON and DB are consistent.');

            return self::SUCCESS;
        }

        $this->warn('Geo integrity issues found.');

        return $strict ? self::FAILURE : self::SUCCESS;
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
     * @return array<string, string> lower(statecode) => state name
     */
    private function stateNameByCode(): array
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
            $map[$this->key($code)] = $name;
        }

        return $map;
    }

    private function key(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
