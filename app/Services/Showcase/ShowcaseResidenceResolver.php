<?php

namespace App\Services\Showcase;

use App\Models\City;
use App\Models\District;
use App\Models\Location;
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
            $geo = Location::geoTable();
            $inDistrict = City::query()
                ->join($geo.' as taluka', function ($join) use ($geo): void {
                    $join->on('taluka.id', '=', $geo.'.parent_id')->where('taluka.type', '=', 'taluka');
                })
                ->where($geo.'.id', $cityId)
                ->where('taluka.parent_id', $districtId)
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
            $geo = Location::geoTable();
            $match = City::query()
                ->join($geo.' as taluka', function ($join) use ($geo): void {
                    $join->on('taluka.id', '=', $geo.'.parent_id')->where('taluka.type', '=', 'taluka');
                })
                ->where('taluka.parent_id', $districtId)
                ->whereRaw('LOWER(TRIM('.$geo.'.name)) = ?', [mb_strtolower($name)])
                ->orderByDesc($geo.'.population')
                ->value($geo.'.id');
            if ($match) {
                return (int) $match;
            }
        }

        $geo = Location::geoTable();
        $bestPop = City::query()
            ->join($geo.' as taluka', function ($join) use ($geo): void {
                $join->on('taluka.id', '=', $geo.'.parent_id')->where('taluka.type', '=', 'taluka');
            })
            ->where('taluka.parent_id', $districtId)
            ->orderByRaw('COALESCE('.$geo.'.population, 0) DESC')
            ->orderByDesc($geo.'.id')
            ->value($geo.'.id');

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

        $geo = Location::geoTable();
        $id = City::query()
            ->join($geo.' as taluka', function ($join) use ($geo): void {
                $join->on('taluka.id', '=', $geo.'.parent_id')->where('taluka.type', '=', 'taluka');
            })
            ->where('taluka.parent_id', $districtId)
            ->whereNotNull($geo.'.population')
            ->where($geo.'.population', '>=', $minPopulation)
            ->orderByDesc($geo.'.population')
            ->orderByDesc($geo.'.id')
            ->value($geo.'.id');

        if ($id) {
            return (int) $id;
        }

        return $this->fromDistrictSeat($request);
    }
}
