<?php

namespace App\Services\Location;

use App\Models\City;
use App\Models\Location;
use Illuminate\Support\Facades\Schema;

/**
 * Best-effort bridge from legacy {@see City} rows to unified {@see Location} when names align in hierarchy.
 */
class CityToLocationResolverService
{
    /**
     * Resolve a canonical Location for alias sync when open-place suggestions are approved against a City.
     */
    public function findLocationForCity(City $city): ?Location
    {
        if (! Schema::hasTable(Location::geoTable())) {
            return null;
        }

        $city->loadMissing(['taluka.district.state']);

        $needle = mb_strtolower(trim((string) $city->name), 'UTF-8');
        if ($needle === '') {
            return null;
        }

        $stateName = mb_strtolower(trim((string) ($city->taluka?->district?->state?->name ?? '')), 'UTF-8');
        $districtName = mb_strtolower(trim((string) ($city->taluka?->district?->name ?? '')), 'UTF-8');

        $candidates = Location::query()
            ->with('parent')
            ->whereIn('type', ['city', 'suburb', 'village'])
            ->whereRaw('LOWER(TRIM(name)) = ?', [$needle])
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $filtered = $candidates->filter(function (Location $loc) use ($stateName, $districtName): bool {
            $chain = $this->ancestorNames($loc);

            if ($stateName !== '' && isset($chain['state']) && mb_strtolower($chain['state']) !== $stateName) {
                return false;
            }

            if ($districtName !== '' && isset($chain['district']) && mb_strtolower($chain['district']) !== $districtName) {
                return false;
            }

            return true;
        });

        return $filtered->count() === 1 ? $filtered->first() : null;
    }

    /**
     * @return array{state?: string, district?: string, taluka?: string}
     */
    private function ancestorNames(Location $location): array
    {
        $out = [];
        $id = $location->parent_id;
        $guard = 0;
        while ($id !== null && $guard < 16) {
            $current = Location::query()->whereKey($id)->first();
            if ($current === null) {
                break;
            }
            $type = (string) $current->type;
            if ($type === 'state' && ! isset($out['state'])) {
                $out['state'] = (string) $current->name;
            }
            if ($type === 'district' && ! isset($out['district'])) {
                $out['district'] = (string) $current->name;
            }
            if ($type === 'taluka' && ! isset($out['taluka'])) {
                $out['taluka'] = (string) $current->name;
            }
            $id = $current->parent_id;
            $guard++;
        }

        return $out;
    }
}
