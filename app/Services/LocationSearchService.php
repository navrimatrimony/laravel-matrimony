<?php

namespace App\Services;

use App\Models\City;
use App\Models\CityAlias;
use App\Models\District;
use App\Models\State;
use App\Models\Village;

/**
 * Location search: village/city + taluka/district, pincode, single-query prefix/partial and Marathi.
 * Engine frozen 2026-03 — no further feature changes unless requested.
 */
class LocationSearchService
{
    private const MAX_RESULTS = 20;

    /**
     * @return array{results: array<int, array{city_id: int, city_name: string, taluka_name: string, district_name: string, state_name: string}>, context_detected: array|null}
     */
    public function search(string $query, array $preferredStateIds = [], array $preferredDistrictIds = []): array
    {
        $q = strtolower(trim($query));
        $queryTrimmed = trim($query);
        if ($q === '') {
            return ['results' => [], 'context_detected' => null];
        }

        if (strlen($queryTrimmed) === 6 && ctype_digit($queryTrimmed)) {
            $cities = City::query()
                ->with(['taluka.district.state.country'])
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
                $rows = $this->boostResults($rows, $preferredStateIds, $preferredDistrictIds);

                return [
                    'results' => array_slice($rows, 0, $maxResults),
                    'context_detected' => $this->detectContext($query),
                ];
            }
        }

        $cityPrefix = City::query()
            ->with(['taluka.district.state'])
            ->where('name', 'like', $q . '%')
            ->orderBy('name')
            ->limit($maxResults)
            ->get();
        foreach ($cityPrefix as $city) {
            $seen[$city->id] = true;
            $rows[] = $this->formatRow($city);
        }
        if (count($rows) >= $maxResults) {
            $rows = $this->boostResults($rows, $preferredStateIds, $preferredDistrictIds);

            return [
                'results' => array_slice($rows, 0, $maxResults),
                'context_detected' => $this->detectContext($query),
            ];
        }

        $cityPartial = City::query()
            ->with(['taluka.district.state'])
            ->where('name', 'like', '%' . $q . '%')
            ->whereNotIn('id', array_keys($seen))
            ->orderBy('name')
            ->limit($maxResults)
            ->get();
        foreach ($cityPartial as $city) {
            $seen[$city->id] = true;
            $rows[] = $this->formatRow($city);
        }
        if (count($rows) >= $maxResults) {
            $rows = $this->boostResults($rows, $preferredStateIds, $preferredDistrictIds);

            return [
                'results' => array_slice($rows, 0, $maxResults),
                'context_detected' => $this->detectContext($query),
            ];
        }

        $aliasPrefix = CityAlias::query()
            ->where('is_active', true)
            ->where('normalized_alias', 'like', $q . '%')
            ->with(['city.taluka.district.state'])
            ->orderBy('normalized_alias')
            ->limit($maxResults)
            ->get();
        foreach ($aliasPrefix as $alias) {
            $city = $alias->city;
            if (!$city || isset($seen[$city->id])) {
                continue;
            }
            $seen[$city->id] = true;
            $rows[] = $this->formatRow($city);
        }
        if (count($rows) >= $maxResults) {
            $rows = $this->boostResults($rows, $preferredStateIds, $preferredDistrictIds);

            return [
                'results' => array_slice($rows, 0, $maxResults),
                'context_detected' => $this->detectContext($query),
            ];
        }

        $aliasPartial = CityAlias::query()
            ->where('is_active', true)
            ->where('normalized_alias', 'like', '%' . $q . '%')
            ->with(['city.taluka.district.state'])
            ->orderBy('normalized_alias')
            ->limit($maxResults)
            ->get();
        foreach ($aliasPartial as $alias) {
            $city = $alias->city;
            if (!$city || isset($seen[$city->id])) {
                continue;
            }
            $seen[$city->id] = true;
            $rows[] = $this->formatRow($city);
        }
        if (count($rows) >= $maxResults) {
            $rows = $this->boostResults($rows, $preferredStateIds, $preferredDistrictIds);

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
        $rows = $this->boostResults($rows, $preferredStateIds, $preferredDistrictIds);

        return [
            'results' => array_slice(array_values($rows), 0, $maxResults),
            'context_detected' => $context,
        ];
    }

    /**
     * For Marathi locale, also search by villages.name_mr so that Marathi queries (e.g. "विटा")
     * return correct matches. We then map them back to their mirrored City rows.
     *
     * @param array<int, array> $rows
     * @param array<int, bool> $seen  city_id => true
     */
    private function appendVillageLocaleMatches(array &$rows, array &$seen, string $q, int $maxResults): void
    {
        if (mb_strlen($q) < 2 || count($rows) >= $maxResults) {
            return;
        }

        $remaining = $maxResults - count($rows);

        $villages = Village::query()
            ->whereNotNull('name_mr')
            ->whereRaw('name_mr LIKE ?', [$q . '%'])
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

            $key = $village->taluka_id . '|' . strtolower(trim((string) $village->name_en));
            if (isset($cityCache[$key])) {
                $city = $cityCache[$key];
            } else {
                $city = City::query()
                    ->with(['taluka.district.state'])
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
        $nameLike = '%' . strtolower($namePart) . '%';
        $placeLike = '%' . strtolower($placePart) . '%';
        $cities = City::query()
            ->with(['taluka.district.state'])
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
            ->with(['taluka.district.state'])
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
     * @param array<int, array{district_id?: int, state_id?: int}> $rows
     * @param array<int, int> $preferredStateIds
     * @param array<int, int> $preferredDistrictIds
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
     * @param City $city
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
            $cacheKey = $city->taluka_id . '|' . strtolower(trim((string) $city->name));
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

        return [
            'city_id' => (int) $city->id,
            'city_name' => $cityName,
            'taluka_id' => $taluka ? (int) $taluka->id : 0,
            'taluka_name' => $locale === 'mr' && $taluka && $taluka->name_mr ? $taluka->name_mr : ($taluka->name ?? ''),
            'district_id' => $district ? (int) $district->id : 0,
            'district_name' => $locale === 'mr' && $district && $district->name_mr ? $district->name_mr : ($district->name ?? ''),
            'state_id' => $state ? (int) $state->id : 0,
            'state_name' => $locale === 'mr' && $state && $state->name_mr ? $state->name_mr : ($state->name ?? ''),
            'country_id' => $state ? (int) $state->country_id : 0,
        ];
    }
}
