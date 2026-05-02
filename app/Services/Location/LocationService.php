<?php

namespace App\Services\Location;

use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\Pincode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class LocationService
{
    /**
     * Return upward chain from immediate parent to root.
     *
     * @return array<int, Location>
     */
    public function getAncestors(Location $location): array
    {
        $ancestors = [];
        if (! $location->relationLoaded('parent')) {
            $location->load('parent');
        }
        $current = $location->parent;

        while ($current !== null) {
            $ancestors[] = $current;
            if (! $current->relationLoaded('parent')) {
                $current->load('parent');
            }
            $current = $current->parent;
        }

        return $ancestors;
    }

    /**
     * Return ordered hierarchy from state -> district -> taluka -> place.
     *
     * @return array<string, Location|null>
     */
    public function getFullHierarchy(Location $location): array
    {
        $state = null;
        $district = null;
        $taluka = null;
        $city = null;

        $lineage = array_reverse($this->getAncestors($location)); // root -> ... -> parent

        foreach ($lineage as $node) {
            if ($node->type === 'state') {
                $state = $node;
            } elseif ($node->type === 'district') {
                $district = $node;
            } elseif ($node->type === 'taluka') {
                $taluka = $node;
            } elseif ($node->type === 'city') {
                $city = $node;
            }
        }

        return [
            'state' => $state,
            'district' => $district,
            'taluka' => $taluka,
            'city' => $city,
            'place' => $location,
        ];
    }

    public function getDisplayLabel(Location $location): string
    {
        $hierarchy = $this->getFullHierarchy($location);
        $districtName = $this->localizedLocationName($hierarchy['district']);
        $talukaName = $this->localizedLocationName($hierarchy['taluka']);
        $stateName = $this->localizedLocationName($hierarchy['state']);
        $cityName = $this->localizedLocationName($hierarchy['city']);
        $placeName = $this->localizedLocationName($location);

        if ($location->type === 'village') {
            return $this->joinDistinctParts([$placeName, $talukaName, $districtName, $stateName]);
        }

        if ($location->type === 'suburb') {
            // Suburbs often sit under a city; taluka/district disambiguate duplicate names (e.g. multiple "Wakad").
            return $this->joinDistinctParts([$placeName, $cityName, $talukaName, $districtName, $stateName]);
        }

        if ($location->type === 'city') {
            return $this->joinDistinctParts([$placeName, $stateName]);
        }

        if ($location->type === 'district') {
            return $this->joinDistinctParts([$placeName, $stateName]);
        }

        return $placeName;
    }

    /**
     * Full ancestor trail for typeahead search — every row shows place → … → state so duplicate names are distinguishable.
     * Skips {@code country} to avoid noisy labels; uses {@see joinDistinctParts} for consecutive duplicate names.
     */
    private function searchResultDisplayLabel(Location $location): string
    {
        $parts = [];
        $cur = $location;
        $guard = 0;

        while ($cur !== null && $guard < 24) {
            $t = (string) ($cur->type ?? '');
            if ($t !== 'country') {
                $nm = $this->localizedLocationName($cur);
                if ($nm !== null && $nm !== '') {
                    $parts[] = $nm;
                }
            }

            if ($cur->parent_id === null) {
                break;
            }

            if (! $cur->relationLoaded('parent')) {
                $cur->load('parent');
            }

            $cur = $cur->parent;
            $guard++;
        }

        return $this->joinDistinctParts($parts);
    }

    private function localizedLocationName(?Location $location): ?string
    {
        if ($location === null) {
            return null;
        }

        if (app()->getLocale() === 'mr' && filled($location->name_mr)) {
            return trim((string) $location->name_mr);
        }

        return trim((string) $location->name);
    }

    public function normalizeInput(string $input): string
    {
        $normalized = mb_strtolower(trim($input), 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * Text search over unified geo SSOT ({@see Location} / {@code addresses}): {@code name}, {@code slug},
     * {@code name_mr} when present; {@code pincode} when column exists (3–8 digits from input); optional {@code location_aliases}.
     * Requires {@code is_active} truthy.
     *
     * @return array<int, array{id:int,name:string,type:string,display_label:string}>
     */
    public function search(string $query): array
    {
        $normalized = $this->normalizeInput($query);
        if ($normalized === '') {
            return [];
        }

        $like = '%'.$normalized.'%';

        $geo = Location::geoTable();

        $digitsOnly = preg_replace('/\D+/u', '', $normalized);

        $locations = Location::query()
            ->with('parent')
            ->where('is_active', true)
            ->where(function ($q) use ($normalized, $like, $geo, $digitsOnly) {
                $q->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like);
                if (Schema::hasColumn($geo, 'name_mr')) {
                    $q->orWhere('name_mr', 'like', $like);
                }
                if (Schema::hasColumn($geo, 'pincode') && strlen($digitsOnly) >= 3 && strlen($digitsOnly) <= 8) {
                    if (strlen($digitsOnly) === 6) {
                        $q->orWhere('pincode', $digitsOnly);
                    } else {
                        $q->orWhere('pincode', 'like', $digitsOnly.'%');
                    }
                }
                if (Schema::hasTable('location_aliases')) {
                    $q->orWhereExists(function ($sub) use ($normalized, $like, $geo) {
                        $sub->selectRaw('1')
                            ->from('location_aliases')
                            ->whereColumn('location_aliases.location_id', $geo.'.id')
                            ->where(function ($a) use ($normalized, $like) {
                                $a->where('location_aliases.normalized_alias', 'like', $like)
                                    ->orWhere('location_aliases.normalized_alias', $normalized);
                            });
                    });
                }
            })
            ->when(
                strlen($digitsOnly) === 6 && Schema::hasColumn($geo, 'pincode'),
                static function ($q) use ($digitsOnly): void {
                    $q->orderByRaw('CASE WHEN pincode = ? THEN 0 ELSE 1 END', [$digitsOnly]);
                }
            )
            ->orderByRaw(
                "CASE
                    WHEN LOWER(TRIM(name)) = ? THEN 0
                    WHEN LOWER(TRIM(name)) LIKE ? THEN 1
                    WHEN LOWER(TRIM(name)) LIKE ? THEN 2
                    ELSE 3
                END",
                [$normalized, $normalized.'%', '%'.$normalized.'%']
            )
            ->orderByRaw(
                "CASE type
                    WHEN 'city' THEN 0
                    WHEN 'district' THEN 1
                    WHEN 'suburb' THEN 2
                    WHEN 'taluka' THEN 3
                    WHEN 'village' THEN 4
                    WHEN 'state' THEN 5
                    WHEN 'country' THEN 6
                    ELSE 7
                END"
            )
            ->orderBy('name')
            ->limit(25)
            ->get();

        $this->hydrateParentChain($locations);

        return $locations->map(function (Location $location): array {
            return [
                'id' => (int) $location->id,
                'name' => (string) $location->name,
                'type' => (string) $location->type,
                'display_label' => $this->searchResultDisplayLabel($location),
            ];
        })->values()->all();
    }

    /**
     * @return array<int, array{id:int,name:string,type:string,display_label:string,distance_km:float}>
     */
    public function getNearbyLocations(int $locationId, int $radiusKm = 10, ?string $type = null): array
    {
        $radiusKm = max(1, $radiusKm);

        $source = Pincode::query()
            ->where('place_id', $locationId)
            ->where('is_primary', true)
            ->first();

        // Fallback when primary pincode is missing.
        if ($source === null) {
            $source = Pincode::query()
                ->where('place_id', $locationId)
                ->orderBy('id')
                ->first();
        }

        if ($source === null) {
            Log::info('Nearby search skipped due to missing coordinates', [
                'location_id' => $locationId,
                'reason' => 'source_pincode_missing',
            ]);
            return [];
        }

        if ($source->latitude === null || $source->longitude === null) {
            Log::info('Nearby search skipped due to missing coordinates', [
                'location_id' => $locationId,
                'reason' => 'source_coordinates_missing',
                'source_pincode_id' => $source->id,
            ]);

            return [];
        }

        $sourceLat = (float) $source->latitude;
        $sourceLng = (float) $source->longitude;

        $candidates = Pincode::query()
            ->select(['place_id', 'latitude', 'longitude'])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('place_id', '!=', $locationId)
            ->get();

        $distanceByLocation = [];
        foreach ($candidates as $candidate) {
            if ($candidate->place_id === null) {
                continue;
            }

            $distance = $this->haversineDistanceKm(
                $sourceLat,
                $sourceLng,
                (float) $candidate->latitude,
                (float) $candidate->longitude
            );

            if ($distance > $radiusKm) {
                continue;
            }

            $candidateLocationId = (int) $candidate->place_id;
            if (! isset($distanceByLocation[$candidateLocationId]) || $distance < $distanceByLocation[$candidateLocationId]) {
                $distanceByLocation[$candidateLocationId] = $distance;
            }
        }

        if ($distanceByLocation === []) {
            return [];
        }

        $locationsQuery = Location::query()
            ->with('parent')
            ->whereIn('id', array_keys($distanceByLocation))
            ->where('is_active', true);

        if ($type !== null && $type !== '') {
            $locationsQuery->where('type', $type);
        }

        $locations = $locationsQuery->get();

        $this->hydrateParentChain($locations);

        return $locations
            ->map(function (Location $location) use ($distanceByLocation): array {
                $distance = $distanceByLocation[(int) $location->id] ?? null;

                return [
                    'id' => (int) $location->id,
                    'name' => (string) $location->name,
                    'type' => (string) $location->type,
                    'display_label' => $this->getDisplayLabel($location),
                    'distance_km' => $distance !== null ? round((float) $distance, 2) : 0.0,
                ];
            })
            ->sortBy('distance_km')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{profile_id:int,name:string,location_id:int,location_label:string,distance_km:float}>
     */
    public function getNearbyProfiles(int $locationId, int $radiusKm = 10, ?string $type = null): array
    {
        $nearbyLocations = $this->getNearbyLocations($locationId, $radiusKm, $type);
        if ($nearbyLocations === []) {
            return [];
        }

        $locationById = [];
        foreach ($nearbyLocations as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $locationById[$id] = $row;
        }

        if ($locationById === []) {
            return [];
        }

        $locationIds = array_keys($locationById);
        $profiles = MatrimonyProfile::query()
            ->select(['id', 'full_name', 'location_id'])
            ->whereIn('location_id', $locationIds)
            ->get();

        return $profiles
            ->map(function (MatrimonyProfile $profile) use ($locationById): ?array {
                $locationId = (int) ($profile->location_id ?? 0);
                if ($locationId <= 0 || ! isset($locationById[$locationId])) {
                    return null;
                }

                $locationRow = $locationById[$locationId];

                return [
                    'profile_id' => (int) $profile->id,
                    'name' => (string) ($profile->full_name ?? ''),
                    'location_id' => $locationId,
                    'location_label' => (string) ($locationRow['display_label'] ?? ''),
                    'distance_km' => (float) ($locationRow['distance_km'] ?? 0.0),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string|null>  $parts
     */
    private function joinParts(array $parts): string
    {
        return $this->joinDistinctParts($parts);
    }

    /**
     * Like {@see joinParts} but drops consecutive duplicate labels (case-insensitive) so trails stay readable.
     *
     * @param  array<int, string|null>  $parts
     */
    private function joinDistinctParts(array $parts): string
    {
        $clean = [];
        foreach ($parts as $part) {
            $value = trim((string) ($part ?? ''));
            if ($value === '') {
                continue;
            }
            $last = $clean[count($clean) - 1] ?? null;
            if ($last !== null && mb_strtolower($last, 'UTF-8') === mb_strtolower($value, 'UTF-8')) {
                continue;
            }
            $clean[] = $value;
        }

        return implode(', ', $clean);
    }

    private function haversineDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    /**
     * Preload parent chain in small batches and attach loaded relations to avoid N+1 reads.
     *
     * @param  \Illuminate\Support\Collection<int, Location>  $locations
     */
    private function hydrateParentChain($locations): void
    {
        if ($locations->isEmpty()) {
            return;
        }

        $known = [];
        foreach ($locations as $location) {
            $known[(int) $location->id] = $location;
        }

        $pendingParentIds = [];
        foreach ($locations as $location) {
            if ($location->parent_id !== null && ! isset($known[(int) $location->parent_id])) {
                $pendingParentIds[(int) $location->parent_id] = true;
            }
        }

        while ($pendingParentIds !== []) {
            $batchIds = array_keys($pendingParentIds);
            $pendingParentIds = [];

            $parents = Location::query()
                ->with('parent')
                ->whereIn('id', $batchIds)
                ->get();

            foreach ($parents as $parent) {
                $known[(int) $parent->id] = $parent;
            }

            foreach ($parents as $parent) {
                if ($parent->parent_id !== null && ! isset($known[(int) $parent->parent_id])) {
                    $pendingParentIds[(int) $parent->parent_id] = true;
                }
            }
        }

        foreach ($known as $node) {
            if ($node->parent_id !== null && isset($known[(int) $node->parent_id])) {
                $node->setRelation('parent', $known[(int) $node->parent_id]);
            }
        }
    }
}

