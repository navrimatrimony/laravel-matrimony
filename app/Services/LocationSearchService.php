<?php

namespace App\Services;

use App\Models\City;
use App\Models\CityAlias;
use App\Models\District;
use App\Models\State;

class LocationSearchService
{
    private const MAX_RESULTS = 20;

    /**
     * @return array{results: array<int, array{city_id: int, city_name: string, taluka_name: string, district_name: string, state_name: string}>, context_detected: array|null}
     */
    public function search(string $query): array
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
            return [
                'results' => $this->searchByPincode($q),
                'context_detected' => $this->detectContext($query),
            ];
        }

        $maxResults = self::MAX_RESULTS;
        $seen = [];
        $rows = [];

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
            return [
                'results' => array_slice($rows, 0, $maxResults),
                'context_detected' => $this->detectContext($query),
            ];
        }

        $context = $this->detectContext($query);
        return [
            'results' => array_slice(array_values($rows), 0, $maxResults),
            'context_detected' => $context,
        ];
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
     * @param City $city
     * @return array{city_id: int, city_name: string, taluka_id: int, taluka_name: string, district_id: int, district_name: string, state_id: int, state_name: string, country_id: int}
     */
    private function formatRow(City $city): array
    {
        $taluka = $city->taluka;
        $district = $taluka?->district;
        $state = $district?->state;

        return [
            'city_id' => (int) $city->id,
            'city_name' => $city->name ?? '',
            'taluka_id' => $taluka ? (int) $taluka->id : 0,
            'taluka_name' => $taluka->name ?? '',
            'district_id' => $district ? (int) $district->id : 0,
            'district_name' => $district->name ?? '',
            'state_id' => $state ? (int) $state->id : 0,
            'state_name' => $state->name ?? '',
            'country_id' => $state ? (int) $state->country_id : 0,
        ];
    }
}
