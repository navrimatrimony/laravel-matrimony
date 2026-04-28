<?php

namespace App\Services;

use App\Models\City;
use App\Models\CityAlias;
use App\Models\District;
use App\Models\State;
use App\Models\Village;
use App\Services\Location\LocationDisplayFormatter;

/**
 * Location search: village/city + taluka/district, pincode, single-query prefix/partial and Marathi.
 * Engine frozen 2026-03 — no further feature changes unless requested.
 */
class LocationSearchService
{
    private const MAX_RESULTS = 20;

    public function __construct(
        private readonly LocationDisplayFormatter $locationDisplayFormatter
    ) {}

    /**
     * @return array{results: array<int, array{city_id: int, city_name: string, taluka_name: string, district_name: string, state_name: string}>, context_detected: array|null}
     */
    public function search(
        string $query,
        array $preferredStateIds = [],
        array $preferredDistrictIds = [],
        bool $applyAmbiguousRanking = false
    ): array
    {
        $q = strtolower(trim($query));
        $queryTrimmed = trim($query);
        if ($q === '') {
            return ['results' => [], 'context_detected' => null];
        }

        if (strlen($queryTrimmed) === 6 && ctype_digit($queryTrimmed)) {
            $cities = City::query()
                ->with($this->cityWithRelations())
                ->where('pincode', $queryTrimmed)
                ->limit(20)
                ->get();
            if ($cities->isNotEmpty()) {
                return [
                    'results' => $cities->map(fn ($city) => $this->formatRow($city))->values()->all(),
                    'context_detected' => null,
                ];
            }
        }

        if (strlen($q) === 6 && ctype_digit($q)) {
            $results = $this->searchByPincode($q);

            return [
                'results' => $results,
                'context_detected' => $this->detectContext($query),
            ];
        }

        $maxResults = self::MAX_RESULTS;
        $seen = [];
        $rows = [];

        // Multi-token: "village taluka" or "village district" — match village/city name + taluka or district name
        $tokens = array_values(array_filter(preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY)));
        if (count($tokens) >= 2) {
            $namePart = $tokens[0];
            $placePart = implode(' ', array_slice($tokens, 1));
            $multiRows = $this->searchByVillageAndPlace($namePart, $placePart, $maxResults);
            foreach ($multiRows as $row) {
                $cid = $row['city_id'] ?? 0;
                if ($cid && ! isset($seen[$cid])) {
                    $seen[$cid] = true;
                    $rows[] = $row;
                }
            }
            if (count($rows) >= $maxResults) {
                $rows = $this->applyRankingPipeline($rows, $q, $preferredStateIds, $preferredDistrictIds, $applyAmbiguousRanking);

                return [
                    'results' => array_slice($rows, 0, $maxResults),
                    'context_detected' => $this->detectContext($query),
                ];
            }
        }

        $cityPrefix = City::query()
            ->with($this->cityWithRelations())
            ->where('name', 'like', $q.'%')
            ->orderBy('name')
            ->limit($maxResults)
            ->get();
        foreach ($cityPrefix as $city) {
            $seen[$city->id] = true;
            $rows[] = $this->formatRow($city);
        }
        if (count($rows) >= $maxResults) {
            $rows = $this->applyRankingPipeline($rows, $q, $preferredStateIds, $preferredDistrictIds, $applyAmbiguousRanking);

            return [
                'results' => array_slice($rows, 0, $maxResults),
                'context_detected' => $this->detectContext($query),
            ];
        }

        $cityPartial = City::query()
            ->with($this->cityWithRelations())
            ->where('name', 'like', '%'.$q.'%')
            ->whereNotIn('id', array_keys($seen))
            ->orderBy('name')
            ->limit($maxResults)
            ->get();
        foreach ($cityPartial as $city) {
            $seen[$city->id] = true;
            $rows[] = $this->formatRow($city);
        }
        if (count($rows) >= $maxResults) {
            $rows = $this->applyRankingPipeline($rows, $q, $preferredStateIds, $preferredDistrictIds, $applyAmbiguousRanking);

            return [
                'results' => array_slice($rows, 0, $maxResults),
                'context_detected' => $this->detectContext($query),
            ];
        }

        $aliasPrefix = CityAlias::query()
            ->where('is_active', true)
            ->where('normalized_alias', 'like', $q.'%')
            ->with([
                'city.taluka.district.state.country',
                'city.parentCity',
                'city.displayMeta',
            ])
            ->orderBy('normalized_alias')
            ->limit($maxResults)
            ->get();
        foreach ($aliasPrefix as $alias) {
            $city = $alias->city;
            if (! $city || isset($seen[$city->id])) {
                continue;
            }
            $seen[$city->id] = true;
            $rows[] = $this->formatRow($city);
        }
        if (count($rows) >= $maxResults) {
            $rows = $this->applyRankingPipeline($rows, $q, $preferredStateIds, $preferredDistrictIds, $applyAmbiguousRanking);

            return [
                'results' => array_slice($rows, 0, $maxResults),
                'context_detected' => $this->detectContext($query),
            ];
        }

        $aliasPartial = CityAlias::query()
            ->where('is_active', true)
            ->where('normalized_alias', 'like', '%'.$q.'%')
            ->with([
                'city.taluka.district.state.country',
                'city.parentCity',
                'city.displayMeta',
            ])
            ->orderBy('normalized_alias')
            ->limit($maxResults)
            ->get();
        foreach ($aliasPartial as $alias) {
            $city = $alias->city;
            if (! $city || isset($seen[$city->id])) {
                continue;
            }
            $seen[$city->id] = true;
            $rows[] = $this->formatRow($city);
        }
        if (count($rows) >= $maxResults) {
            $rows = $this->applyRankingPipeline($rows, $q, $preferredStateIds, $preferredDistrictIds, $applyAmbiguousRanking);

            return [
                'results' => array_slice($rows, 0, $maxResults),
                'context_detected' => $this->detectContext($query),
            ];
        }

        $locale = app()->getLocale();

        if ($locale === 'mr') {
            $this->appendVillageLocaleMatches($rows, $seen, $q, $maxResults);
        }

        $context = $this->detectContext($query);
        $rows = $this->applyRankingPipeline($rows, $q, $preferredStateIds, $preferredDistrictIds, $applyAmbiguousRanking);

        return [
            'results' => array_slice(array_values($rows), 0, $maxResults),
            'context_detected' => $context,
        ];
    }

    /**
     * For Marathi locale, also search by villages.name_mr so that Marathi queries (e.g. "विटा")
     * return correct matches. We then map them back to their mirrored City rows.
     *
     * @param  array<int, array>  $rows
     * @param  array<int, bool>  $seen  city_id => true
     */
    private function appendVillageLocaleMatches(array &$rows, array &$seen, string $q, int $maxResults): void
    {
        if (mb_strlen($q) < 2 || count($rows) >= $maxResults) {
            return;
        }

        $remaining = $maxResults - count($rows);

        $villages = Village::query()
            ->whereNotNull('name_mr')
            ->whereRaw('name_mr LIKE ?', [$q.'%'])
            ->limit($remaining * 3)
            ->get();

        if ($villages->isEmpty()) {
            return;
        }

        static $cityCache = [];

        foreach ($villages as $village) {
            if (count($rows) >= $maxResults) {
                break;
            }

            $key = $village->taluka_id.'|'.strtolower(trim((string) $village->name_en));
            if (isset($cityCache[$key])) {
                $city = $cityCache[$key];
            } else {
                $city = City::query()
                    ->with($this->cityWithRelations())
                    ->where('taluka_id', $village->taluka_id)
                    ->whereRaw('LOWER(name) = ?', [strtolower(trim((string) $village->name_en))])
                    ->first();
                $cityCache[$key] = $city;
            }

            if (! $city || isset($seen[$city->id])) {
                continue;
            }

            $taluka = $city->taluka;
            $district = $taluka?->district;
            $state = $district?->state;

            $rows[] = [
                'city_id' => (int) $city->id,
                'city_name' => $village->name_mr ?: ($city->name ?? ''),
                'taluka_id' => $taluka ? (int) $taluka->id : 0,
                'taluka_name' => $taluka->name ?? '',
                'district_id' => $district ? (int) $district->id : 0,
                'district_name' => $district->name ?? '',
                'state_id' => $state ? (int) $state->id : 0,
                'state_name' => $state->name ?? '',
                'country_id' => $state ? (int) $state->country_id : 0,
            ];

            $seen[$city->id] = true;
        }
    }

    /**
     * @return array{district_id: int, district_name: string, state_id: int, state_name: string}|array{state_id: int, state_name: string}|null
     */
    private function detectContext(string $query): ?array
    {
        $normalized = strtolower(trim($query));
        if ($normalized === '') {
            return null;
        }
        $tokens = array_filter(preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY));
        if ($tokens === []) {
            return null;
        }

        foreach ($tokens as $token) {
            $district = District::query()
                ->whereRaw('LOWER(name) = ?', [$token])
                ->with('state')
                ->first();
            if ($district !== null) {
                $state = $district->state;

                return [
                    'district_id' => (int) $district->id,
                    'district_name' => $district->name ?? '',
                    'state_id' => $state ? (int) $state->id : 0,
                    'state_name' => $state->name ?? '',
                    'country_id' => $state ? (int) $state->country_id : 0,
                ];
            }
        }

        foreach ($tokens as $token) {
            $state = State::query()
                ->whereRaw('LOWER(name) = ?', [$token])
                ->first();
            if ($state !== null) {
                return [
                    'state_id' => (int) $state->id,
                    'state_name' => $state->name ?? '',
                    'country_id' => (int) $state->country_id,
                ];
            }
        }

        return null;
    }

    /**
     * @return array<int, array{city_id: int, city_name: string, taluka_name: string, district_name: string, state_name: string}>
     */
    private function searchByVillageAndPlace(string $namePart, string $placePart, int $limit): array
    {
        $nameLike = '%'.strtolower($namePart).'%';
        $placeLike = '%'.strtolower($placePart).'%';
        $cities = City::query()
            ->with($this->cityWithRelations())
            ->whereRaw('LOWER(name) LIKE ?', [$nameLike])
            ->whereHas('taluka', function ($qb) use ($placeLike) {
                $qb->where(function ($t) use ($placeLike) {
                    $t->whereRaw('LOWER(talukas.name) LIKE ?', [$placeLike])
                        ->orWhereRaw('LOWER(COALESCE(talukas.name_mr, "")) LIKE ?', [$placeLike])
                        ->orWhereHas('district', function ($d) use ($placeLike) {
                            $d->whereRaw('LOWER(districts.name) LIKE ?', [$placeLike])
                                ->orWhereRaw('LOWER(COALESCE(districts.name_mr, "")) LIKE ?', [$placeLike]);
                        });
                });
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $rows = [];
        foreach ($cities as $city) {
            $rows[] = $this->formatRow($city);
        }

        return $rows;
    }

    /**
     * @return array<int, array{city_id: int, city_name: string, taluka_name: string, district_name: string, state_name: string}>
     */
    private function searchByPincode(string $pincode): array
    {
        $cityIds = City::query()
            ->join('profile_addresses', 'cities.id', '=', 'profile_addresses.city_id')
            ->where('profile_addresses.postal_code', $pincode)
            ->distinct()
            ->limit(self::MAX_RESULTS)
            ->pluck('cities.id');

        if ($cityIds->isEmpty()) {
            return [];
        }

        $cities = City::query()
            ->with($this->cityWithRelations())
            ->whereIn('id', $cityIds)
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get();

        $rows = [];
        foreach ($cities as $city) {
            $rows[] = $this->formatRow($city);
        }

        return $rows;
    }

    /**
     * Re-rank results so that matches in preferred districts/states appear first.
     *
     * @param  array<int, array{district_id?: int, state_id?: int}>  $rows
     * @param  array<int, int>  $preferredStateIds
     * @param  array<int, int>  $preferredDistrictIds
     * @return array<int, array>
     */
    private function boostResults(array $rows, array $preferredStateIds, array $preferredDistrictIds): array
    {
        if ($rows === [] || ($preferredStateIds === [] && $preferredDistrictIds === [])) {
            return $rows;
        }

        $preferredStateIds = array_map('intval', $preferredStateIds);
        $preferredDistrictIds = array_map('intval', $preferredDistrictIds);

        $scored = [];
        foreach ($rows as $idx => $row) {
            $score = 0;
            $districtId = isset($row['district_id']) ? (int) $row['district_id'] : 0;
            $stateId = isset($row['state_id']) ? (int) $row['state_id'] : 0;

            if ($districtId && in_array($districtId, $preferredDistrictIds, true)) {
                $score += 4;
            }
            if ($stateId && in_array($stateId, $preferredStateIds, true)) {
                $score += 2;
            }

            $scored[] = ['row' => $row, 'score' => $score, 'idx' => $idx];
        }

        usort($scored, static function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $a['idx'] <=> $b['idx'];
            }

            return $b['score'] <=> $a['score'];
        });

        return array_map(static fn ($item) => $item['row'], $scored);
    }

    /**
     * Unified ranking pipeline for location suggestions.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $preferredStateIds
     * @param  array<int, int>  $preferredDistrictIds
     * @return array<int, array<string, mixed>>
     */
    private function applyRankingPipeline(
        array $rows,
        string $normalizedQuery,
        array $preferredStateIds,
        array $preferredDistrictIds,
        bool $applyAmbiguousRanking
    ): array {
        $rows = $this->boostResults($rows, $preferredStateIds, $preferredDistrictIds);
        $rows = $this->rankForUserIntent($rows, $normalizedQuery);
        if ($applyAmbiguousRanking) {
            $rows = $this->rankForAmbiguousInput($rows, $normalizedQuery);
        }

        return $rows;
    }

    /**
     * Harmonized user-intent ranking. Keeps query collection untouched and ranks post-fetch.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rankForUserIntent(array $rows, string $query): array
    {
        $queryKey = $this->rankKey($query);
        if ($rows === [] || $queryKey === '') {
            return $rows;
        }

        $cityIds = array_values(array_unique(array_map(static fn ($row) => (int) ($row['city_id'] ?? 0), $rows)));
        $cityIds = array_values(array_filter($cityIds, static fn (int $id) => $id > 0));
        if ($cityIds === []) {
            return $rows;
        }

        $cities = City::query()
            ->with(['taluka.district.state', 'displayMeta'])
            ->whereIn('id', $cityIds)
            ->get(['id', 'name', 'taluka_id', 'parent_city_id']);

        $signalByCityId = [];
        $talukaIds = [];
        $cityNameKeys = [];
        foreach ($cities as $city) {
            $cityNameKey = $this->rankKey((string) $city->name);
            $talukaNameKey = $this->rankKey((string) ($city->taluka?->name ?? ''));
            $stateNameKey = $this->rankKey((string) ($city->taluka?->district?->state?->name ?? ''));
            $signalByCityId[(int) $city->id] = [
                'is_district_hq' => (bool) ($city->displayMeta?->is_district_hq ?? false),
                'is_taluka_hq' => ($cityNameKey !== '' && $cityNameKey === $talukaNameKey),
                'has_parent_city' => $city->parent_city_id !== null,
                'state_name_key' => $stateNameKey,
                'taluka_id' => (int) ($city->taluka_id ?? 0),
                'city_name_key' => $cityNameKey,
                'is_village' => false,
            ];
            if ((int) ($city->taluka_id ?? 0) > 0) {
                $talukaIds[] = (int) $city->taluka_id;
            }
            if ($cityNameKey !== '') {
                $cityNameKeys[] = $cityNameKey;
            }
        }

        $aliasExactCityIds = CityAlias::query()
            ->whereIn('city_id', $cityIds)
            ->where('is_active', true)
            ->whereIn('normalized_alias', [$queryKey, str_replace(' ', '', $queryKey)])
            ->pluck('city_id')
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $aliasExactMap = array_fill_keys($aliasExactCityIds, true);

        $talukaIds = array_values(array_unique(array_filter($talukaIds, static fn (int $id) => $id > 0)));
        $cityNameKeys = array_values(array_unique(array_filter($cityNameKeys, static fn (string $v) => $v !== '')));
        if ($talukaIds !== [] && $cityNameKeys !== []) {
            $villages = Village::query()
                ->whereIn('taluka_id', $talukaIds)
                ->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(name_en))'), $cityNameKeys)
                ->get(['taluka_id', 'name_en']);
            foreach ($villages as $village) {
                $k = ((int) $village->taluka_id).'|'.$this->rankKey((string) $village->name_en);
                foreach ($signalByCityId as $cid => $sig) {
                    if (($sig['taluka_id'] ?? 0) === (int) $village->taluka_id
                        && ($sig['city_name_key'] ?? '') === $this->rankKey((string) $village->name_en)) {
                        $signalByCityId[$cid]['is_village'] = true;
                    }
                }
            }
        }

        $sameNameCounts = [];
        foreach ($rows as $row) {
            $nameKey = $this->rankKey((string) ($row['name'] ?? $row['city_name'] ?? ''));
            if ($nameKey !== '') {
                $sameNameCounts[$nameKey] = ($sameNameCounts[$nameKey] ?? 0) + 1;
            }
        }

        $scored = [];
        foreach ($rows as $idx => $row) {
            $cityId = (int) ($row['city_id'] ?? 0);
            $name = (string) ($row['name'] ?? $row['city_name'] ?? '');
            $nameKey = $this->rankKey($name);
            $score = 0;

            // A) String quality
            if ($nameKey !== '' && $nameKey === $queryKey) {
                $score += 100;
            } elseif ($nameKey !== '' && str_starts_with($nameKey, $queryKey)) {
                $score += 70;
            } elseif ($nameKey !== '' && str_contains($nameKey, $queryKey)) {
                $score += 50;
            }
            if (isset($aliasExactMap[$cityId])) {
                $score += 90;
            }
            $lenDiff = abs(mb_strlen($nameKey, 'UTF-8') - mb_strlen($queryKey, 'UTF-8'));
            $score += max(5, 15 - ($lenDiff * 2));

            // B/C/D) Admin/meta + administrative relevance + geography hints.
            $sig = $signalByCityId[$cityId] ?? null;
            $isDistrictHq = (bool) ($sig['is_district_hq'] ?? false);
            $isTalukaHq = (bool) ($sig['is_taluka_hq'] ?? false);
            $hasParent = (bool) ($sig['has_parent_city'] ?? false);
            $isVillage = (bool) ($sig['is_village'] ?? false);
            if ($isDistrictHq) {
                $score += 80;
            }
            if ($isTalukaHq) {
                $score += 60;
            }
            if ($hasParent) {
                $score += 40;
            }
            if ($isVillage) {
                $score -= 20;
            }
            if (($sig['state_name_key'] ?? '') === 'maharashtra') {
                $score += 10;
            }

            // Same-name disambiguation.
            if (($sameNameCounts[$nameKey] ?? 0) > 1) {
                if ($isDistrictHq) {
                    $score += 20;
                } elseif ($isTalukaHq) {
                    $score += 15;
                }
                if ($hasParent) {
                    $score += 12;
                }
                if (! $isVillage) {
                    $score += 8;
                }
            }

            $scored[] = [
                'row' => $row,
                'score' => $score,
                'idx' => $idx,
                'name_len' => mb_strlen($nameKey, 'UTF-8'),
                'district_id' => (int) ($row['district_id'] ?? PHP_INT_MAX),
                'taluka_id' => (int) ($row['taluka_id'] ?? PHP_INT_MAX),
                'city_id' => $cityId > 0 ? $cityId : PHP_INT_MAX,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            if ($a['name_len'] !== $b['name_len']) {
                return $a['name_len'] <=> $b['name_len'];
            }
            if ($a['district_id'] !== $b['district_id']) {
                return $a['district_id'] <=> $b['district_id'];
            }
            if ($a['taluka_id'] !== $b['taluka_id']) {
                return $a['taluka_id'] <=> $b['taluka_id'];
            }
            if ($a['city_id'] !== $b['city_id']) {
                return $a['city_id'] <=> $b['city_id'];
            }
            return $a['idx'] <=> $b['idx'];
        });

        return array_map(static fn (array $item) => $item['row'], $scored);
    }

    private function rankKey(string $value): string
    {
        $v = mb_strtolower(trim($value), 'UTF-8');
        $v = trim((string) (preg_replace('/\s+city$/u', '', $v) ?? $v));
        $v = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $v) ?? $v;
        $v = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $v) ?? $v;

        return trim((string) (preg_replace('/\s+/u', ' ', $v) ?? $v));
    }

    /**
     * Ranking tweak for ambiguous text: exact city first, district-hint next.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rankForAmbiguousInput(array $rows, string $normalizedQuery): array
    {
        $normalizedQuery = strtolower(trim($normalizedQuery));
        if ($rows === [] || $normalizedQuery === '') {
            return $rows;
        }

        $tokens = array_values(array_filter(preg_split('/\s+/', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY)));
        $districtHint = count($tokens) >= 2 ? (string) end($tokens) : '';

        $scored = [];
        foreach ($rows as $idx => $row) {
            $score = 0;
            $city = strtolower(trim((string) ($row['city_name'] ?? '')));
            $district = strtolower(trim((string) ($row['district_name'] ?? '')));

            if ($city !== '' && $city === $normalizedQuery) {
                $score += 20;
            } elseif ($city !== '' && str_starts_with($city, $normalizedQuery)) {
                $score += 10;
            }

            if ($districtHint !== '' && $district !== '' && str_contains($district, $districtHint)) {
                $score += 8;
            }

            $scored[] = ['row' => $row, 'score' => $score, 'idx' => $idx];
        }

        usort($scored, static function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $a['idx'] <=> $b['idx'];
            }

            return $b['score'] <=> $a['score'];
        });

        return array_map(static fn ($item) => $item['row'], $scored);
    }

    /**
     * Public wrapper for GPS/canonical flows — same shape as manual typeahead rows.
     *
     * @return array{city_id: int, city_name: string, taluka_id: int, taluka_name: string, district_id: int, district_name: string, state_id: int, state_name: string, country_id: int}
     */
    public function canonicalPayloadFromCity(City $city): array
    {
        return $this->formatRow($city);
    }

    /**
     * @return array{city_id: int, city_name: string, taluka_id: int, taluka_name: string, district_id: int, district_name: string, state_id: int, state_name: string, country_id: int}
     */
    private function formatRow(City $city): array
    {
        $taluka = $city->taluka;
        $district = $taluka?->district;
        $state = $district?->state;

        $locale = app()->getLocale();
        $cityName = $city->name ?? '';
        if ($locale === 'mr') {
            static $villageCache = [];
            $cacheKey = $city->taluka_id.'|'.strtolower(trim((string) $city->name));
            if (array_key_exists($cacheKey, $villageCache)) {
                $cached = $villageCache[$cacheKey];
                if ($cached !== null) {
                    $cityName = $cached;
                }
            } else {
                $match = Village::query()
                    ->where('taluka_id', $city->taluka_id)
                    ->whereRaw('LOWER(name_en) = ?', [strtolower(trim((string) $city->name))])
                    ->first();
                $villageCache[$cacheKey] = $match && $match->name_mr ? $match->name_mr : null;
                if ($match && $match->name_mr) {
                    $cityName = $match->name_mr;
                }
            }
        }

        $displayLabel = $this->locationDisplayFormatter->formatCityLine($city);

        return [
            'id' => (int) $city->id,
            'city_id' => (int) $city->id,
            'name' => $cityName,
            'city_name' => $cityName,
            'taluka_id' => $taluka ? (int) $taluka->id : 0,
            'taluka_name' => $locale === 'mr' && $taluka && $taluka->name_mr ? $taluka->name_mr : ($taluka->name ?? ''),
            'district_id' => $district ? (int) $district->id : 0,
            'district_name' => $locale === 'mr' && $district && $district->name_mr ? $district->name_mr : ($district->name ?? ''),
            'state_id' => $state ? (int) $state->id : 0,
            'state_name' => $locale === 'mr' && $state && $state->name_mr ? $state->name_mr : ($state->name ?? ''),
            'country_id' => $state ? (int) $state->country_id : 0,
            'display_label' => $displayLabel,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function cityWithRelations(): array
    {
        return ['taluka.district.state.country', 'parentCity', 'displayMeta'];
    }
}
