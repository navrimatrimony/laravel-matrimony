<?php

namespace App\Services\Profile;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical self residence (current address type) lives only in {@code profile_addresses}.
 * Used when {@code matrimony_profiles.location_id} / {@code address_line} columns are absent.
 */
final class ProfileCanonicalResidenceService
{
    private const CACHE_KEY_CURRENT_TYPE_ID = 'master_address_types_id_current';

    private const CACHE_TTL_SECONDS = 3600;

    public static function forgetCachedMasters(): void
    {
        Cache::forget(self::CACHE_KEY_CURRENT_TYPE_ID);
    }

    public static function currentAddressTypeId(): ?int
    {
        if (! Schema::hasTable('master_address_types')) {
            return null;
        }

        return Cache::remember(self::CACHE_KEY_CURRENT_TYPE_ID, self::CACHE_TTL_SECONDS, static function (): ?int {
            $id = DB::table('master_address_types')->where('key', 'current')->value('id');

            return $id !== null ? (int) $id : null;
        });
    }

    public static function locationLeafId(int $profileId): ?int
    {
        $tid = self::currentAddressTypeId();
        if ($tid === null || ! Schema::hasTable('profile_addresses')) {
            return null;
        }
        $col = Schema::hasColumn('profile_addresses', 'location_id') ? 'location_id' : 'city_id';
        $cid = DB::table('profile_addresses')
            ->where('profile_id', $profileId)
            ->where('address_scope', 'self')
            ->where('address_type_id', $tid)
            ->value($col);

        return $cid !== null && (int) $cid > 0 ? (int) $cid : null;
    }

    public static function addressLineRaw(int $profileId): ?string
    {
        $tid = self::currentAddressTypeId();
        if ($tid === null || ! Schema::hasTable('profile_addresses')) {
            return null;
        }
        $line = DB::table('profile_addresses')
            ->where('profile_id', $profileId)
            ->where('address_scope', 'self')
            ->where('address_type_id', $tid)
            ->value('address_line');
        if ($line === null) {
            return null;
        }
        $t = trim((string) $line);

        return $t !== '' ? $t : null;
    }

    /**
     * Upsert the single self + "current" address row (wizard / mutation / model accessors).
     *
     * @param  mixed  $addressLine  null clears when {@code $touchLine} is true
     */
    public static function upsertSelfCurrent(
        int $profileId,
        ?int $cityId,
        mixed $addressLine,
        bool $touchCity,
        bool $touchLine,
    ): void {
        if (! Schema::hasTable('profile_addresses')) {
            return;
        }
        $tid = self::currentAddressTypeId();
        if ($tid === null) {
            return;
        }

        $row = DB::table('profile_addresses')
            ->where('profile_id', $profileId)
            ->where('address_scope', 'self')
            ->where('address_type_id', $tid)
            ->first();

        $now = now();

        $lineNormalized = null;
        if ($touchLine) {
            if ($addressLine !== null && trim((string) $addressLine) !== '') {
                $lineNormalized = mb_substr(trim((string) $addressLine), 0, 255);
            } else {
                $lineNormalized = null;
            }
        }

        $cityNormalized = null;
        if ($touchCity) {
            $cityNormalized = ($cityId !== null && (int) $cityId > 0) ? (int) $cityId : null;
        }

        $locCol = Schema::hasColumn('profile_addresses', 'location_id') ? 'location_id' : 'city_id';

        if ($row) {
            $upd = ['updated_at' => $now];
            if ($touchCity) {
                $upd[$locCol] = $cityNormalized;
            }
            if ($touchLine) {
                $upd['address_line'] = $lineNormalized;
            }
            if (count($upd) > 1) {
                DB::table('profile_addresses')->where('id', $row->id)->update($upd);
            }

            return;
        }

        if (! $touchCity && ! $touchLine) {
            return;
        }

        $insert = [
            'profile_id' => $profileId,
            'address_scope' => 'self',
            'address_type_id' => $tid,
            'address_line' => $touchLine ? $lineNormalized : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $insert[$locCol] = $touchCity ? $cityNormalized : null;
        DB::table('profile_addresses')->insert($insert);
    }
}
