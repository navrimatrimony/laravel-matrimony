<?php

namespace App\Support\Location;

use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * District {@code addresses.id} values (hierarchy=district) implied by real members' residence.
 * SSOT: self + address type {@code current} in {@code profile_addresses.location_id} (leaf {@code addresses.id}); legacy {@code matrimony_profiles.district_id} when present.
 *
 * @internal Used by showcase autofill / admin bulk policy — keep in sync with residence rules.
 */
final class NonShowcaseResidenceDistrictIds
{
    /**
     * @return list<int>
     */
    public static function all(): array
    {
        if (Schema::hasColumn('matrimony_profiles', 'district_id')) {
            return MatrimonyProfile::query()
                ->whereNonShowcase()
                ->whereNotNull('district_id')
                ->pluck('district_id')
                ->map(fn ($v) => (int) $v)
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if (Schema::hasColumn('matrimony_profiles', 'location_id')) {
            $locationIds = MatrimonyProfile::query()
                ->whereNonShowcase()
                ->whereNotNull('location_id')
                ->pluck('location_id')
                ->map(fn ($v) => (int) $v)
                ->filter()
                ->unique()
                ->values()
                ->all();
        } else {
            $typeId = ProfileCanonicalResidenceService::currentAddressTypeId();
            if ($typeId === null || ! Schema::hasTable('profile_addresses')) {
                return [];
            }
            $leafCol = Schema::hasColumn('profile_addresses', 'location_id') ? 'pa.location_id' : 'pa.city_id';
            $locationIds = DB::table('profile_addresses as pa')
                ->join('matrimony_profiles as mp', 'mp.id', '=', 'pa.profile_id')
                ->where(function ($q) {
                    $q->where('mp.is_showcase', false)->orWhereNull('mp.is_showcase');
                })
                ->where('pa.address_scope', 'self')
                ->where('pa.address_type_id', $typeId)
                ->whereNotNull($leafCol)
                ->pluck($leafCol)
                ->map(fn ($v) => (int) $v)
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if ($locationIds === []) {
            return [];
        }

        $geoTable = Location::geoTable();
        $nodeById = [];
        $pending = $locationIds;

        while ($pending !== []) {
            $missing = array_values(array_filter(array_unique($pending), static fn (int $id) => ! array_key_exists($id, $nodeById)));
            if ($missing === []) {
                break;
            }

            $rows = DB::table($geoTable)
                ->whereIn('id', $missing)
                ->select('id', 'parent_id', 'hierarchy')
                ->get();

            $pending = [];
            foreach ($missing as $id) {
                $nodeById[$id] = null;
            }

            foreach ($rows as $row) {
                $id = (int) ($row->id ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $parentId = isset($row->parent_id) ? (int) $row->parent_id : null;
                $nodeById[$id] = (object) [
                    'id' => $id,
                    'parent_id' => $parentId && $parentId > 0 ? $parentId : null,
                    'hierarchy' => strtolower((string) ($row->hierarchy ?? '')),
                ];
                if ($parentId && $parentId > 0 && ! array_key_exists($parentId, $nodeById)) {
                    $pending[] = $parentId;
                }
            }
        }

        $districtIds = [];
        foreach ($locationIds as $locationId) {
            $cursor = (int) $locationId;
            $guard = 0;
            while ($cursor > 0 && $guard < 20) {
                $guard++;
                $node = $nodeById[$cursor] ?? null;
                if (! is_object($node)) {
                    break;
                }
                if (($node->hierarchy ?? '') === 'district') {
                    $districtIds[] = (int) $node->id;
                    break;
                }
                $cursor = (int) ($node->parent_id ?? 0);
            }
        }

        return array_values(array_unique(array_filter($districtIds, static fn (int $id) => $id > 0)));
    }
}
