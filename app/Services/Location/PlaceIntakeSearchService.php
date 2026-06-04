<?php

namespace App\Services\Location;

use App\Models\District;
use App\Models\Location;
use App\Models\State;
use App\Models\Taluka;
use App\Models\Village;
use Illuminate\Database\Eloquent\Builder;

/**
 * Intake birth/native place search: village, town-taluka, suburban, city/metro — MR/EN, SSOT formatter labels.
 */
final class PlaceIntakeSearchService
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private static array $searchCache = [];

    /** @var array<string, array<string, mixed>|null> */
    private static array $confidentMatchCache = [];

    /** @var array<string, list<int>> */
    private static array $districtIdCache = [];

    /** @var array<string, list<int>> */
    private static array $cityParentIdCache = [];

    public function __construct(
        private readonly LocationCompoundAddressParser $compoundParser,
        private readonly AddressHierarchySearch $hierarchySearch,
        private readonly LocationFormatterService $formatter,
        private readonly LocationService $locationService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $searchText, int $limit = 7): array
    {
        $searchText = trim($searchText);
        if ($searchText === '') {
            return [];
        }

        $cacheKey = mb_strtolower($searchText, 'UTF-8').'|'.$limit;
        if (array_key_exists($cacheKey, self::$searchCache)) {
            return self::$searchCache[$cacheKey];
        }

        $limit = max(1, $limit);
        $hints = $this->compoundParser->parseComponents($searchText);
        $simpleParts = $this->parseSimpleParts($searchText);
        $seen = [];
        $locations = [];

        $push = function (Location $loc) use (&$seen, &$locations, $limit): void {
            $id = (int) $loc->id;
            if ($id < 1 || isset($seen[$id]) || count($locations) >= $limit) {
                return;
            }
            $seen[$id] = true;
            $locations[] = $loc;
        };

        if ($this->shouldSearchTownTaluka($hints)) {
            $townName = trim((string) ($hints['taluka'] !== '' ? $hints['taluka'] : $hints['village']));
            foreach ($this->findTownTalukas($townName, $hints['district']) as $loc) {
                $push($loc);
            }
        }

        if ($this->shouldSearchVillage($hints)) {
            foreach ($this->findVillageLocations($hints, $limit) as $loc) {
                $push($loc);
            }
        }

        if (count($simpleParts) === 2) {
            [$first, $second] = $simpleParts;
            foreach ($this->findSuburbanInCity($first, $second) as $loc) {
                $push($loc);
            }
            foreach ($this->findTownTalukas($first, $second) as $loc) {
                $push($loc);
            }
        } elseif (count($simpleParts) === 1 && $hints['taluka'] === '' && $hints['district'] === '') {
            foreach ($this->findMetroOrCity($simpleParts[0]) as $loc) {
                $push($loc);
            }
        }

        $ranked = $this->rankLocations($locations, $hints, $searchText, $simpleParts);

        return self::$searchCache[$cacheKey] = $this->formatRows(array_slice($ranked, 0, $limit));
    }

    /**
     * One intake suggestion when biodata hierarchy (गाव+ता.+जि., town-taluka, suburban+city, metro) resolves uniquely.
     * Uses {@code addresses.type} + {@code tag} with {@see LocationFormatterService} display lines.
     *
     * @return array<string, mixed>|null
     */
    public function confidentMatch(string $searchText): ?array
    {
        $searchText = trim($searchText);
        if ($searchText === '') {
            return null;
        }

        $cacheKey = mb_strtolower($searchText, 'UTF-8');
        if (array_key_exists($cacheKey, self::$confidentMatchCache)) {
            return self::$confidentMatchCache[$cacheKey];
        }

        $hints = $this->compoundParser->parseComponents($searchText);
        $simpleParts = $this->parseSimpleParts($searchText);
        $pattern = $this->detectIntakePattern($hints, $simpleParts);

        if ($pattern === 'rural' && $this->hasFullRuralHints($hints)) {
            $resolved = $this->resolveUniqueRuralVillage($hints);
            if ($resolved !== null) {
                return self::$confidentMatchCache[$cacheKey] = ($this->formatRows([$resolved])[0] ?? null);
            }
        }

        if ($pattern === 'town_taluka') {
            $resolved = $this->resolveUniqueTownTaluka($hints);
            if ($resolved !== null) {
                return self::$confidentMatchCache[$cacheKey] = ($this->formatRows([$resolved])[0] ?? null);
            }
        }

        $rows = $this->search($searchText, 10);
        $candidates = $this->filterRowsForIntakePattern($rows, $pattern, $hints, $simpleParts);

        return self::$confidentMatchCache[$cacheKey] = $this->pickSingleConfidentRow($candidates, $hints, $rows);
    }

    /**
     * @param  array{village: string, taluka: string, district: string}  $hints
     * @param  list<string>  $simpleParts
     */
    private function detectIntakePattern(array $hints, array $simpleParts): string
    {
        $village = trim((string) ($hints['village'] ?? ''));
        $taluka = trim((string) ($hints['taluka'] ?? ''));
        $district = trim((string) ($hints['district'] ?? ''));

        if (count($simpleParts) === 1 && $taluka === '' && $district === '') {
            return 'metro';
        }

        if (count($simpleParts) === 2 && $taluka === '' && $district === '') {
            return 'suburban';
        }

        if ($district !== '' && $taluka !== '' && $village !== ''
            && $this->normalizeKey($village) === $this->normalizeKey($taluka)) {
            return 'town_taluka';
        }

        if ($district !== '' && $taluka !== '' && $village !== '') {
            return 'rural';
        }

        return 'unknown';
    }

    /**
     * @param  array{village: string, taluka: string, district: string}  $hints
     */
    private function hasFullRuralHints(array $hints): bool
    {
        return trim((string) ($hints['village'] ?? '')) !== ''
            && trim((string) ($hints['taluka'] ?? '')) !== ''
            && trim((string) ($hints['district'] ?? '')) !== '';
    }

    /**
     * @param  array{village: string, taluka: string, district: string}  $hints
     */
    private function resolveUniqueRuralVillage(array $hints): ?Location
    {
        $matches = [];
        foreach ($this->hierarchySearch->findCities($hints, 12) as $leaf) {
            $loc = Location::query()->with(['parent.parent.parent'])->find((int) $leaf->id);
            if ($loc === null || ! $this->locationFitsPattern($loc, 'rural')) {
                continue;
            }
            if (! $this->locationMatchesAdminHints($loc, $hints)) {
                continue;
            }
            if (! $this->villageNameMatchesHint($this->compactNamesForLocation($loc), (string) $hints['village'])) {
                continue;
            }
            $matches[] = $loc;
        }

        return $this->pickUniqueLocation($matches, (string) $hints['village']);
    }

    /**
     * @param  array{village: string, taluka: string, district: string}  $hints
     */
    private function resolveUniqueTownTaluka(array $hints): ?Location
    {
        $townName = trim((string) ($hints['taluka'] !== '' ? $hints['taluka'] : $hints['village']));
        $district = trim((string) ($hints['district'] ?? ''));
        if ($townName === '' || $district === '') {
            return null;
        }

        $query = Location::query()
            ->with(['parent.parent'])
            ->where('type', 'taluka')
            ->where(function (Builder $w): void {
                $w->where('tag', 'town')
                    ->orWhereRaw('LOWER(COALESCE(tag, "")) = ?', ['town']);
            })
            ->where(function (Builder $w) use ($townName): void {
                $this->applyGeoNameMatch($w, $townName);
            });

        $districtIds = $this->resolveDistrictIds($district);
        if ($districtIds !== []) {
            $query->whereIn('parent_id', $districtIds);
        } else {
            $query->whereHas('parent', function (Builder $dq) use ($district): void {
                $dq->where('type', 'district');
                $this->applyGeoNameMatch($dq, $district);
            });
        }

        $matches = [];
        foreach ($query->orderBy('name')->limit(8)->get() as $loc) {
            if ($this->locationMatchesAdminHints($loc, $hints)) {
                $matches[] = $loc;
            }
        }

        return $this->pickUniqueLocation($matches, $townName);
    }

    /**
     * @param  list<Location>  $locations
     */
    private function pickUniqueLocation(array $locations, string $villageHint): ?Location
    {
        if ($locations === []) {
            return null;
        }
        if (count($locations) === 1) {
            return $locations[0];
        }

        $scored = [];
        foreach ($locations as $loc) {
            $scored[] = [
                'loc' => $loc,
                'score' => $this->villageTokenMatchScore($this->compactNamesForLocation($loc), $villageHint),
            ];
        }
        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        if (($scored[0]['score'] ?? 0) < 1) {
            return null;
        }
        if (isset($scored[1]) && $scored[1]['score'] === $scored[0]['score']) {
            return null;
        }

        return $scored[0]['loc'];
    }

    private function locationFitsPattern(Location $loc, string $pattern): bool
    {
        $type = (string) ($loc->type ?? '');
        $tag = strtolower(trim((string) ($loc->category ?? '')));

        return match ($pattern) {
            'rural' => $type === 'village' && ($tag === '' || in_array($tag, ['rural', 'village'], true)),
            'town_taluka' => $type === 'taluka' && in_array($tag, ['town', 'taluka'], true),
            'suburban' => $type === 'suburb' && ($tag === '' || $tag === 'suburban'),
            'metro' => in_array($type, ['city', 'district'], true)
                && ($tag === '' || in_array($tag, ['metro', 'city', 'capital'], true)),
            default => true,
        };
    }

    /**
     * @param  array{village: string, taluka: string, district: string}  $hints
     */
    private function locationMatchesAdminHints(Location $loc, array $hints): bool
    {
        $this->locationService->ensureAncestorsLoaded($loc);
        $h = $this->locationService->fillHierarchyGaps($loc, $this->locationService->getFullHierarchy($loc));

        $talukaHint = trim((string) ($hints['taluka'] ?? ''));
        $districtHint = trim((string) ($hints['district'] ?? ''));

        if ($talukaHint !== '' && ! $this->adminUnitMatchesLocation($h['taluka'] ?? null, $talukaHint)) {
            return false;
        }

        if ($districtHint !== '' && ! $this->adminUnitMatchesLocation($h['district'] ?? null, $districtHint)) {
            return false;
        }

        return true;
    }

    private function adminUnitMatchesLocation(?Location $admin, string $hint): bool
    {
        if ($admin === null || trim($hint) === '') {
            return false;
        }

        foreach ([$admin->name_mr, $admin->name_en, $admin->name, $admin->localizedName()] as $name) {
            $n = $this->normalizeKey((string) $name);
            $h = $this->normalizeKey($hint);
            if ($n !== '' && ($n === $h || str_contains($n, $h) || str_contains($h, $n))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function compactNamesForLocation(Location $loc): array
    {
        return array_values(array_filter([
            $this->compactLookupKey((string) ($loc->name_mr ?? '')),
            $this->compactLookupKey((string) ($loc->name_en ?? '')),
            $this->compactLookupKey((string) ($loc->name ?? '')),
            $this->compactLookupKey($loc->localizedName()),
        ]));
    }

    private function compactLookupKey(string $value): string
    {
        return $this->normalizeKey($value);
    }

    private function villageNameMatchesHint(array $nameCompacts, string $villageHint): bool
    {
        $expected = $this->villageTokenCount($villageHint) * 10;
        if ($expected < 1) {
            return false;
        }

        foreach ($nameCompacts as $compact) {
            if ($compact === '') {
                continue;
            }
            if ($this->villageTokenMatchScore([$compact], $villageHint) >= $expected) {
                return true;
            }
        }

        return false;
    }

    private function villageTokenCount(string $villageHint): int
    {
        return count($this->villageNameTokens($villageHint));
    }

    /**
     * @param  list<string>  $nameCompacts
     */
    private function villageTokenMatchScore(array $nameCompacts, string $villageHint): int
    {
        $score = 0;
        foreach ($this->villageNameTokens($villageHint) as $token) {
            foreach ($nameCompacts as $compact) {
                if ($compact !== '' && (str_contains($compact, $token) || $this->tokenAppearsInCompactName($compact, $token))) {
                    $score += 10;
                    break;
                }
            }
        }

        return $score;
    }

    private function tokenAppearsInCompactName(string $locNameCompact, string $token): bool
    {
        if ($token === '' || $locNameCompact === '') {
            return false;
        }
        if (str_contains($locNameCompact, $token)) {
            return true;
        }

        $len = mb_strlen($token);
        for ($i = 0; $i <= mb_strlen($locNameCompact) - $len; $i++) {
            $slice = mb_substr($locNameCompact, $i, $len);
            similar_text($slice, $token, $pct);
            if ($pct >= 86.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{village: string, taluka: string, district: string}  $hints
     * @param  list<string>  $simpleParts
     * @return list<array<string, mixed>>
     */
    private function filterRowsForIntakePattern(array $rows, string $pattern, array $hints, array $simpleParts): array
    {
        if ($pattern === 'unknown') {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $loc = Location::query()->find((int) ($row['city_id'] ?? 0));
            if ($loc === null || ! $this->locationFitsPattern($loc, $pattern)) {
                continue;
            }
            if ($pattern === 'rural' && ! $this->hasFullRuralHints($hints)) {
                continue;
            }
            if ($pattern === 'rural' && ! $this->locationMatchesAdminHints($loc, $hints)) {
                continue;
            }
            if ($pattern === 'town_taluka' && ! $this->locationMatchesAdminHints($loc, $hints)) {
                continue;
            }
            if ($pattern === 'suburban' && count($simpleParts) >= 2) {
                if (! $this->villageNameMatchesHint($this->compactNamesForLocation($loc), $simpleParts[0])) {
                    continue;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  array{village: string, taluka: string, district: string}  $hints
     * @param  list<array<string, mixed>>  $searchOrder
     * @return array<string, mixed>|null
     */
    private function pickSingleConfidentRow(array $candidates, array $hints, array $searchOrder): ?array
    {
        if ($candidates === []) {
            return null;
        }
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        $rankById = [];
        foreach ($searchOrder as $index => $row) {
            $id = (int) ($row['city_id'] ?? 0);
            if ($id > 0) {
                $rankById[$id] = $index;
            }
        }

        $villageHint = (string) ($hints['village'] ?? '');
        $scored = [];
        foreach ($candidates as $row) {
            $loc = Location::query()->find((int) ($row['city_id'] ?? 0));
            $compacts = $loc !== null ? $this->compactNamesForLocation($loc) : [$this->compactLookupKey((string) ($row['city_name'] ?? ''))];
            $scored[] = [
                'row' => $row,
                'score' => $this->villageTokenMatchScore($compacts, $villageHint),
                'rank' => $rankById[(int) ($row['city_id'] ?? 0)] ?? PHP_INT_MAX,
            ];
        }

        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['rank'] <=> $b['rank']);

        $top = $scored[0];
        $runnerUp = $scored[1] ?? null;
        if (($top['score'] ?? 0) < 1) {
            return null;
        }
        if ($runnerUp !== null && ($runnerUp['score'] ?? 0) === ($top['score'] ?? 0)) {
            return null;
        }

        $expected = $this->villageTokenCount($villageHint) * 10;
        if ($expected >= 20 && ($top['score'] ?? 0) < $expected) {
            return null;
        }

        return $top['row'];
    }

    /**
     * @param  array{village: string, taluka: string, district: string}  $hints
     */
    private function shouldSearchTownTaluka(array $hints): bool
    {
        $village = trim((string) ($hints['village'] ?? ''));
        $taluka = trim((string) ($hints['taluka'] ?? ''));
        $district = trim((string) ($hints['district'] ?? ''));

        if ($district === '' || $taluka === '') {
            return false;
        }

        if ($village === '') {
            return true;
        }

        return $this->normalizeKey($village) === $this->normalizeKey($taluka);
    }

    /**
     * @param  array{village: string, taluka: string, district: string}  $hints
     */
    private function shouldSearchVillage(array $hints): bool
    {
        $village = trim((string) ($hints['village'] ?? ''));
        $taluka = trim((string) ($hints['taluka'] ?? ''));

        if ($village === '') {
            return false;
        }

        if ($taluka === '') {
            return true;
        }

        return $this->normalizeKey($village) !== $this->normalizeKey($taluka);
    }

    /**
     * @return list<Location>
     */
    private function findTownTalukas(string $townName, string $districtName): array
    {
        $townName = trim($townName);
        if ($townName === '') {
            return [];
        }

        $query = Taluka::query()->with(['district.state']);

        $query->where(function (Builder $w): void {
            $w->where('tag', 'town')
                ->orWhereRaw('LOWER(COALESCE(tag, "")) = ?', ['town']);
        });

        $query->where(function (Builder $w) use ($townName): void {
            $this->applyGeoNameMatch($w, $townName);
        });

        if ($districtName !== '') {
            $districtIds = $this->resolveDistrictIds($districtName);
            if ($districtIds !== []) {
                $query->whereIn('parent_id', $districtIds);
            } else {
                $query->whereHas('district', function (Builder $dq) use ($districtName): void {
                    $this->applyGeoNameMatch($dq, $districtName);
                });
            }
        }

        return $query->orderBy('name')->limit(10)->get()->all();
    }

    /**
     * @param  array{village: string, taluka: string, district: string}  $hints
     * @return list<Location>
     */
    private function findVillageLocations(array $hints, int $limit): array
    {
        $out = [];
        foreach ($this->hierarchySearch->findCities($hints, $limit) as $city) {
            $loc = Location::query()
                ->with(['parent.parent.parent'])
                ->find((int) $city->id);
            if ($loc !== null) {
                $out[] = $loc;
            }
        }

        return $out;
    }

    /**
     * @return list<Location>
     */
    private function findSuburbanInCity(string $suburb, string $cityName): array
    {
        $suburb = trim($suburb);
        $cityName = trim($cityName);
        if ($suburb === '' || $cityName === '') {
            return [];
        }

        $cityIds = $this->resolveCityParentIds($cityName);
        if ($cityIds === []) {
            return [];
        }

        return Location::query()
            ->with(['parent.parent'])
            ->where('type', 'suburb')
            ->where(function (Builder $w): void {
                $w->where('tag', 'suburban')
                    ->orWhereNull('tag')
                    ->orWhere('tag', '');
            })
            ->whereIn('parent_id', $cityIds)
            ->where(function (Builder $w) use ($suburb): void {
                $this->applyGeoNameMatch($w, $suburb);
            })
            ->orderBy('name')
            ->limit(15)
            ->get()
            ->all();
    }

    /**
     * @return list<Location>
     */
    private function findMetroOrCity(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        return Location::query()
            ->with(['parent'])
            ->whereIn('type', ['city', 'district'])
            ->where(function (Builder $w) use ($name): void {
                $this->applyGeoNameMatch($w, $name);
            })
            ->orderByRaw("CASE WHEN type = 'district' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->limit(12)
            ->get()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function parseSimpleParts(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        if (preg_match('/\b(ता\.|जि\.|तालुका|जिल्हा)\b/iu', $text)) {
            return [];
        }

        if (str_contains($text, ',')) {
            $parts = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/u', $text) ?: [])));

            return count($parts) >= 1 && count($parts) <= 3 ? array_slice($parts, 0, 2) : [];
        }

        $words = array_values(array_filter(preg_split('/\s+/u', $text) ?: []));
        if (count($words) === 2) {
            return [$words[0], $words[1]];
        }
        if (count($words) === 1) {
            return [$words[0]];
        }

        return [];
    }

    /**
     * @param  list<Location>  $locations
     * @param  array{village: string, taluka: string, district: string}  $hints
     * @return list<Location>
     */
    /**
     * @param  list<string>  $simpleParts
     */
    private function rankLocations(array $locations, array $hints, string $searchText, array $simpleParts = []): array
    {
        if ($locations === []) {
            return [];
        }

        $districtKey = $this->normalizeKey((string) ($hints['district'] ?? ''));
        $talukaKey = $this->normalizeKey((string) ($hints['taluka'] ?? ''));
        $villageKey = $this->normalizeKey((string) ($hints['village'] ?? ''));
        $searchKey = $this->normalizeKey($searchText);
        $simpleFirst = isset($simpleParts[0]) ? $this->normalizeKey($simpleParts[0]) : '';
        $simpleSecond = isset($simpleParts[1]) ? $this->normalizeKey($simpleParts[1]) : '';

        $scored = [];
        foreach ($locations as $idx => $loc) {
            $this->locationService->ensureAncestorsLoaded($loc);
            $h = $this->locationService->fillHierarchyGaps($loc, $this->locationService->getFullHierarchy($loc));

            $score = 0;
            $locName = $this->normalizeKey($loc->localizedName());
            $districtName = $this->normalizeKey((string) (($h['district'] ?? null)?->localizedName() ?? ''));
            $talukaName = $this->normalizeKey((string) (($h['taluka'] ?? null)?->localizedName() ?? ''));

            if ($loc->type === 'taluka') {
                $score += 50;
            }
            if ($loc->type === 'village' && $villageKey !== '' && $villageKey !== $talukaKey) {
                $score += 60;
            }
            if ($loc->type === 'taluka' && $simpleFirst !== '' && $simpleSecond !== '') {
                if ($locName === $simpleFirst || str_contains($locName, $simpleFirst)) {
                    $score += 120;
                }
                if ($districtName === $simpleSecond || str_contains($districtName, $simpleSecond)) {
                    $score += 80;
                }
            }
            if ($loc->type === 'taluka' && $talukaKey !== '' && $villageKey === $talukaKey) {
                $score += 150;
            }
            if (count($simpleParts) === 1 && $simpleFirst !== '' && $locName === $simpleFirst) {
                $score += ($loc->type === 'district' || $loc->type === 'city') ? 200 : 0;
            }
            if ($districtKey !== '' && ($districtName === $districtKey || str_contains($districtName, $districtKey))) {
                $score += 40;
            }
            if ($talukaKey !== '' && ($talukaName === $talukaKey || str_contains($talukaName, $talukaKey))) {
                $score += 30;
            }
            if ($loc->type === 'village' && $villageKey !== '' && $this->compactNamesMatch($locName, $villageKey)) {
                $score += 160;
            } else {
                $villageTokens = $this->villageNameTokens((string) ($hints['village'] ?? ''));
                if ($loc->type === 'village' && count($villageTokens) >= 2) {
                    $matchedTokens = 0;
                    foreach ($villageTokens as $token) {
                        if ($token !== '' && (str_contains($locName, $token) || $this->compactNamesMatch($locName, $token))) {
                            $matchedTokens++;
                        }
                    }
                    if ($matchedTokens === count($villageTokens)) {
                        $score += 130;
                    } elseif ($matchedTokens > 0) {
                        $score -= 50;
                    }
                } elseif ($villageKey !== '' && $locName !== '' && ($locName === $villageKey || str_contains($locName, $villageKey))) {
                    $score += 35;
                }
            }

            $scored[] = ['loc' => $loc, 'score' => $score, 'idx' => $idx];
        }

        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['idx'] <=> $b['idx']);

        return array_map(static fn (array $item) => $item['loc'], $scored);
    }

    /**
     * @param  list<Location>  $locations
     * @return array<int, array<string, mixed>>
     */
    private function formatRows(array $locations): array
    {
        $rows = [];
        foreach ($locations as $loc) {
            $h = $this->locationService->getFullHierarchy($loc);
            $h = $this->locationService->fillHierarchyGaps($loc, $h);
            $taluka = $h['taluka'] ?? null;
            $district = $h['district'] ?? null;
            $state = $h['state'] ?? ($district?->parent ?? null);

            $rows[] = [
                'city_id' => (int) $loc->id,
                'id' => (int) $loc->id,
                'name' => $loc->localizedName(),
                'city_name' => $loc->localizedName(),
                'taluka_id' => $taluka ? (int) $taluka->id : ($loc->type === 'taluka' ? (int) $loc->id : 0),
                'taluka_name' => $taluka?->localizedName() ?? '',
                'district_id' => $district ? (int) $district->id : 0,
                'district_name' => $district?->localizedName() ?? '',
                'state_id' => $state instanceof Location ? (int) $state->id : 0,
                'state_name' => $state instanceof Location ? $state->localizedName() : '',
                'display_label' => $this->formatter->formatForLocation($loc),
            ];
        }

        return $rows;
    }

    /**
     * @return list<int>
     */
    private function resolveDistrictIds(string $district): array
    {
        $cacheKey = mb_strtolower(trim($district), 'UTF-8');
        if (array_key_exists($cacheKey, self::$districtIdCache)) {
            return self::$districtIdCache[$cacheKey];
        }

        return self::$districtIdCache[$cacheKey] = District::query()
            ->where(function (Builder $w) use ($district): void {
                $this->applyGeoNameMatch($w, $district);
            })
            ->limit(20)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Parent ids for suburban search (city / district / state rows).
     *
     * @return list<int>
     */
    private function resolveCityParentIds(string $cityName): array
    {
        $cacheKey = mb_strtolower(trim($cityName), 'UTF-8');
        if (array_key_exists($cacheKey, self::$cityParentIdCache)) {
            return self::$cityParentIdCache[$cacheKey];
        }

        $ids = Location::query()
            ->whereIn('type', ['city', 'district'])
            ->where(function (Builder $w) use ($cityName): void {
                $this->applyGeoNameMatch($w, $cityName);
            })
            ->limit(15)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids !== []) {
            return self::$cityParentIdCache[$cacheKey] = $ids;
        }

        $stateIds = State::query()
            ->where(function (Builder $w) use ($cityName): void {
                $this->applyGeoNameMatch($w, $cityName);
            })
            ->limit(3)
            ->pluck('id')
            ->all();

        if ($stateIds === []) {
            return self::$cityParentIdCache[$cacheKey] = [];
        }

        return self::$cityParentIdCache[$cacheKey] = Location::query()
            ->where('type', 'district')
            ->whereIn('parent_id', $stateIds)
            ->limit(20)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  Builder<\App\Models\Location>  $query
     */
    private function applyGeoNameMatch(Builder $query, string $needle): void
    {
        $needle = trim($needle);
        if ($needle === '') {
            return;
        }

        $likeLower = '%'.mb_strtolower($needle, 'UTF-8').'%';
        $likeRaw = '%'.$needle.'%';
        $compact = $this->normalizeKey($needle);
        $compactLike = $compact !== '' ? '%'.$compact.'%' : null;

        $query->where(function (Builder $w) use ($likeLower, $likeRaw, $compactLike): void {
            $w->whereRaw('LOWER(COALESCE(name, "")) LIKE ?', [$likeLower])
                ->orWhereRaw('LOWER(COALESCE(name_en, "")) LIKE ?', [$likeLower])
                ->orWhereRaw('COALESCE(name_mr, "") LIKE ?', [$likeRaw]);
            if ($compactLike !== null) {
                $w->orWhereRaw(
                    'REPLACE(REPLACE(REPLACE(LOWER(COALESCE(name_mr, name, "")), " ", ""), "-", ""), ".", "") LIKE ?',
                    [$compactLike]
                )->orWhereRaw(
                    'REPLACE(REPLACE(REPLACE(LOWER(COALESCE(name_en, name, "")), " ", ""), "-", ""), ".", "") LIKE ?',
                    [$compactLike]
                );
            }
        });
    }

    private function normalizeKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[\s\-–—\.]+/u', '', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return list<string>
     */
    private function compactNamesMatch(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        similar_text($a, $b, $pct);

        return $pct >= 88.0;
    }

    /**
     * @return list<string>
     */
    private function villageNameTokens(string $village): array
    {
        $village = trim($village);
        if ($village === '') {
            return [];
        }

        $parts = preg_split('/[\s\-–—]+/u', $village) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $key = $this->normalizeKey((string) $part);
            if ($key !== '' && mb_strlen($key) >= 2) {
                $tokens[] = $key;
            }
        }

        return array_values(array_unique($tokens));
    }
}
