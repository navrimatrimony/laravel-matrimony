<?php

namespace App\Services\Showcase;

use App\Models\City;
use App\Models\District;
use App\Models\Taluka;
use Illuminate\Http\Request;

/**
 * Picks a residence city_id for auto-showcase using admin-ordered fallbacks (search city → district hub → min population).
 */
class ShowcaseResidenceResolver
{
    public function resolveCityId(Request $request): ?int
    {
        $order = AutoShowcaseSettings::residenceFallbackOrder();
        $minPop = AutoShowcaseSettings::minPopulationThreshold();

        foreach ($order as $mode) {
            if ($mode === 'search_city') {
                $id = $this->fromSearchCity($request);
                if ($id !== null) {
                    return $id;
                }
            } elseif ($mode === 'district_seat') {
                $id = $this->fromDistrictSeat($request);
                if ($id !== null) {
                    return $id;
                }
            } elseif ($mode === 'min_population') {
                $id = $this->fromMinPopulation($request, $minPop);
                if ($id !== null) {
                    return $id;
                }
            }
        }

        return null;
    }

    private function fromSearchCity(Request $request): ?int
    {
        if (! $request->filled('city_id')) {
            return null;
        }

        $cityId = (int) $request->input('city_id');
        $exists = City::query()->whereKey($cityId)->exists();
        if (! $exists) {
            return null;
        }

        if ($request->filled('district_id')) {
            $districtId = (int) $request->district_id;
            $inDistrict = City::query()
                ->join('talukas', 'talukas.id', '=', 'cities.taluka_id')
                ->where('cities.id', $cityId)
                ->where('talukas.district_id', $districtId)
                ->exists();
            if (! $inDistrict) {
                return null;
            }
        }

        return $cityId;
    }

    private function resolveDistrictId(Request $request): ?int
    {
        if ($request->filled('district_id')) {
            return (int) $request->district_id;
        }
        if ($request->filled('taluka_id')) {
            return (int) (Taluka::query()->whereKey((int) $request->taluka_id)->value('district_id') ?? 0) ?: null;
        }
        if ($request->filled('city_id')) {
            $city = City::query()->with('taluka')->find((int) $request->city_id);

            return $city?->taluka?->district_id;
        }

        return null;
    }

    private function fromDistrictSeat(Request $request): ?int
    {
        $districtId = $this->resolveDistrictId($request);
        if ($districtId === null) {
            return null;
        }

        $district = District::query()->find($districtId);
        if (! $district) {
            return null;
        }

        $name = trim((string) $district->name);
        if ($name !== '') {
            $match = City::query()
                ->join('talukas', 'talukas.id', '=', 'cities.taluka_id')
                ->where('talukas.district_id', $districtId)
                ->whereRaw('LOWER(TRIM(cities.name)) = ?', [mb_strtolower($name)])
                ->orderByDesc('cities.population')
                ->value('cities.id');
            if ($match) {
                return (int) $match;
            }
        }

        $bestPop = City::query()
            ->join('talukas', 'talukas.id', '=', 'cities.taluka_id')
            ->where('talukas.district_id', $districtId)
            ->orderByRaw('COALESCE(cities.population, 0) DESC')
            ->orderByDesc('cities.id')
            ->value('cities.id');

        return $bestPop ? (int) $bestPop : null;
    }

    private function fromMinPopulation(Request $request, int $minPopulation): ?int
    {
        $districtId = $this->resolveDistrictId($request);
        if ($districtId === null) {
            return null;
        }

        if ($minPopulation <= 0) {
            return $this->fromDistrictSeat($request);
        }

        $id = City::query()
            ->join('talukas', 'talukas.id', '=', 'cities.taluka_id')
            ->where('talukas.district_id', $districtId)
            ->whereNotNull('cities.population')
            ->where('cities.population', '>=', $minPopulation)
            ->orderByDesc('cities.population')
            ->orderByDesc('cities.id')
            ->value('cities.id');

        if ($id) {
            return (int) $id;
        }

        return $this->fromDistrictSeat($request);
    }
}
