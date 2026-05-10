<?php

namespace App\Services\Showcase;

use App\Models\City;
use App\Models\District;
use App\Models\Location;
use App\Models\Taluka;
use Illuminate\Http\Request;

/**
 * Picks a residence city_id for auto-showcase using admin-ordered fallbacks
 * (search city → district seat name match → tagged eligible cities by admin tag order).
 */
class ShowcaseResidenceResolver
{
    public function resolveCityId(Request $request): ?int
    {
        $order = AutoShowcaseSettings::residenceFallbackOrder();
        $cityTags = ShowcaseAddressEligibility::citySqlTagsFromAdminTags(ShowcaseAddressEligibility::globalTags());

        foreach ($order as $mode) {
            if ($mode === 'search_city') {
                $id = $this->fromSearchCity($request, $cityTags);
                if ($id !== null) {
                    return $id;
                }
            } elseif ($mode === 'district_seat') {
                $id = $this->fromDistrictSeat($request, $cityTags);
                if ($id !== null) {
                    return $id;
                }
            } elseif ($mode === 'tagged_city') {
                $id = $this->fromTaggedCity($request, $cityTags);
                if ($id !== null) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $cityTags  metro|capital only
     */
    private function fromSearchCity(Request $request, array $cityTags): ?int
    {
        if (! $request->filled('city_id')) {
            return null;
        }

        $cityId = (int) $request->input('city_id');
        $geo = Location::geoTable();
        $ok = City::query()
            ->from($geo.' as city')
            ->where('city.id', $cityId)
            ->where('city.type', 'city')
            ->whereIn('city.tag', $cityTags)
            ->exists();
        if (! $ok) {
            return null;
        }

        if ($request->filled('district_id')) {
            $districtId = (int) $request->district_id;
            $inDistrict = City::query()
                ->from($geo.' as city')
                ->join($geo.' as taluka', function ($join): void {
                    $join->on('taluka.id', '=', 'city.parent_id')->where('taluka.type', '=', 'taluka');
                })
                ->where('city.id', $cityId)
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
            return (int) (Taluka::query()->whereKey((int) $request->taluka_id)->value('parent_id') ?? 0) ?: null;
        }
        if ($request->filled('city_id')) {
            $city = City::query()->with('taluka')->find((int) $request->city_id);

            return $city?->taluka?->district_id;
        }

        return null;
    }

    /**
     * @param  list<string>  $cityTags
     */
    private function fromDistrictSeat(Request $request, array $cityTags): ?int
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
                ->from($geo.' as city')
                ->join($geo.' as taluka', function ($join): void {
                    $join->on('taluka.id', '=', 'city.parent_id')->where('taluka.type', '=', 'taluka');
                })
                ->where('taluka.parent_id', $districtId)
                ->where('city.type', 'city')
                ->whereIn('city.tag', $cityTags)
                ->whereRaw('LOWER(TRIM(city.name)) = ?', [mb_strtolower($name)])
                ->orderByDesc('city.id')
                ->value('city.id');
            if ($match) {
                return (int) $match;
            }
        }

        return $this->fromTaggedCity($request, $cityTags);
    }

    /**
     * Prefer capital, then metro, then highest id among eligible cities in the district.
     *
     * @param  list<string>  $cityTags
     */
    private function fromTaggedCity(Request $request, array $cityTags): ?int
    {
        $districtId = $this->resolveDistrictId($request);
        if ($districtId === null) {
            return null;
        }

        $geo = Location::geoTable();
        $tagOrder = "CASE city.tag WHEN 'capital' THEN 2 WHEN 'metro' THEN 1 ELSE 0 END";

        $id = City::query()
            ->from($geo.' as city')
            ->join($geo.' as taluka', function ($join): void {
                $join->on('taluka.id', '=', 'city.parent_id')->where('taluka.type', '=', 'taluka');
            })
            ->where('taluka.parent_id', $districtId)
            ->where('city.type', 'city')
            ->whereIn('city.tag', $cityTags)
            ->orderByRaw($tagOrder.' DESC')
            ->orderByDesc('city.id')
            ->value('city.id');

        return $id ? (int) $id : null;
    }
}
