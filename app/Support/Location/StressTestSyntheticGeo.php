<?php

namespace App\Support\Location;

use App\Models\City;
use App\Models\District;
use App\Models\MatrimonyProfile;
use App\Models\Taluka;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Identifies location rows created by {@see \Database\Seeders\LocationStressTestSeeder}
 * (Gujarat/Karnataka/Madhya Pradesh numbered districts, Taluka-d-t, Village-d-t-v).
 */
final class StressTestSyntheticGeo
{
    private const STRESS_STATE_NAMES = ['Gujarat', 'Karnataka', 'Madhya Pradesh'];

    /** @return list<string> */
    public static function stressDistrictNames(): array
    {
        $names = [];
        foreach (self::STRESS_STATE_NAMES as $stateName) {
            for ($d = 1; $d <= 5; $d++) {
                $names[] = $stateName.'-'.$d;
            }
        }

        return $names;
    }

    /** @return Collection<int, int> district ids */
    public static function stressDistrictIds(): Collection
    {
        return District::query()
            ->whereHas('state', fn ($q) => $q->whereIn('name', self::STRESS_STATE_NAMES))
            ->whereIn('name', self::stressDistrictNames())
            ->pluck('id');
    }

    /** @return Collection<int, int> taluka ids under stress districts */
    public static function stressTalukaIds(Collection $districtIds): Collection
    {
        if ($districtIds->isEmpty()) {
            return collect();
        }

        return Taluka::query()
            ->whereIn('parent_id', $districtIds)
            ->where('name', 'like', 'Taluka-%')
            ->pluck('id');
    }

    /** @return Collection<int, int> city ids under stress talukas or Village-% names */
    public static function stressCityIds(Collection $talukaIds): Collection
    {
        return City::query()
            ->where(function ($q) use ($talukaIds) {
                $q->where('name', 'like', 'Village-%');
                if ($talukaIds->isNotEmpty()) {
                    $q->orWhereIn('parent_id', $talukaIds);
                }
            })
            ->pluck('id');
    }

    /**
     * Profiles whose current / birth / native hierarchy touches stress-test geo.
     *
     * @return Collection<int, MatrimonyProfile>
     */
    public static function profilesTouchingStressGeo(): Collection
    {
        $districtIds = self::stressDistrictIds();
        if ($districtIds->isEmpty()) {
            return collect();
        }

        $talukaIds = self::stressTalukaIds($districtIds);
        $cityIds = self::stressCityIds($talukaIds);

        $districtIdList = $districtIds->all();

        return MatrimonyProfile::query()
            ->where(function ($q) use ($districtIds, $districtIdList, $talukaIds, $cityIds) {
                $leafCol = Schema::hasColumn('profile_addresses', 'location_id') ? 'profile_addresses.location_id' : 'profile_addresses.city_id';
                $started = false;
                $pushIn = static function ($q, bool &$started, string $col, $ids): void {
                    if ($started) {
                        $q->orWhereIn($col, $ids);

                        return;
                    }
                    $q->{$method}($col, $ids);
                    $started = true;
                };
                if (Schema::hasColumn('matrimony_profiles', 'native_district_id')) {
                    $pushIn($q, $started, 'native_district_id', $districtIds);
                }
                if ($talukaIds->isNotEmpty() && Schema::hasColumn('matrimony_profiles', 'native_taluka_id')) {
                    $pushIn($q, $started, 'native_taluka_id', $talukaIds);
                }
                if ($cityIds->isNotEmpty()) {
                    if (Schema::hasColumn('matrimony_profiles', 'location_id')) {
                        $pushIn($q, $started, 'location_id', $cityIds);
                    }
                    $pushIn($q, $started, 'birth_city_id', $cityIds);
                    if (Schema::hasColumn('matrimony_profiles', 'native_city_id')) {
                        $pushIn($q, $started, 'native_city_id', $cityIds);
                    }
                    if ($started) {
                        $q->orWhereExists(function ($sub) use ($cityIds, $leafCol): void {
                            $sub->selectRaw('1')
                                ->from('profile_addresses')
                                ->whereColumn('profile_addresses.profile_id', 'matrimony_profiles.id')
                                ->whereIn($leafCol, $cityIds);
                        });
                    } else {
                        $q->whereExists(function ($sub) use ($cityIds, $leafCol): void {
                            $sub->selectRaw('1')
                                ->from('profile_addresses')
                                ->whereColumn('profile_addresses.profile_id', 'matrimony_profiles.id')
                                ->whereIn($leafCol, $cityIds);
                        });
                        $started = true;
                    }
                }
                if ($started) {
                    $q->orWhereExists(function ($sub) use ($districtIdList): void {
                        $sub->selectRaw('1')
                            ->from('profile_preferred_districts')
                            ->whereColumn('profile_preferred_districts.profile_id', 'matrimony_profiles.id')
                            ->whereIn('profile_preferred_districts.district_id', $districtIdList);
                    });
                } else {
                    $q->whereExists(function ($sub) use ($districtIdList): void {
                        $sub->selectRaw('1')
                            ->from('profile_preferred_districts')
                            ->whereColumn('profile_preferred_districts.profile_id', 'matrimony_profiles.id')
                            ->whereIn('profile_preferred_districts.district_id', $districtIdList);
                    });
                }
            })
            ->get();
    }
}
