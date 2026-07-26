<?php

namespace App\Services\Profile;

use App\Support\SchemaPresence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Canonical self residence (current address type) lives only in {@code profile_addresses}.
 * Used when {@code matrimony_profiles.location_id} / {@code address_line} columns are absent.
 */
final class ProfileCanonicalResidenceService
{
    private const CACHE_KEY_CURRENT_TYPE_ID = 'master_address_types_id_current';

    private const CACHE_TTL_SECONDS = 3600;

    /**
     * Per-process memo in front of the CACHE lookup, not only in front of the DB.
     *
     * The production cache store is `database`, so every `Cache::remember()` here is itself a
     * `select * from cache where key in (?)` — a measured 950 of them in one suggestions request, all
     * asking for the same immutable master id. The cache still owns cross-process sharing; this owns
     * the "do not ask 950 times inside one process" part.
     *
     * `false` means "resolved, and the answer is null" — distinct from "not resolved yet".
     */
    private static int|false|null $currentTypeIdMemo = null;

    /**
     * Per-process memo for the canonical residence leaf, keyed by profile id.
     *
     * `matrimony_profiles.location_id` is a virtual attribute that resolves through this class, and a
     * matching run reads it for the seeker and every candidate in both directions — 897 identical
     * single-row lookups in one production request. The row can only change through
     * {@see upsertSelfCurrent()}, which drops the memo for that profile.
     *
     * @var array<int, int|null>
     */
    private static array $locationLeafMemo = [];

    /** @var array<int, string|null> */
    private static array $addressLineMemo = [];

    public static function forgetCachedMasters(): void
    {
        Cache::forget(self::CACHE_KEY_CURRENT_TYPE_ID);
        self::$currentTypeIdMemo = null;
    }

    /**
     * Drop the per-process row memos. Only needed when `profile_addresses` is written to behind this
     * service's back (bulk import, raw SQL, a test fixture); ordinary writes go through
     * {@see upsertSelfCurrent()}, which invalidates the touched profile itself.
     */
    public static function flushRuntimeCaches(): void
    {
        self::$locationLeafMemo = [];
        self::$addressLineMemo = [];
    }

    public static function forgetProfile(int $profileId): void
    {
        unset(self::$locationLeafMemo[$profileId], self::$addressLineMemo[$profileId]);
    }

    private static function hasAddressTypesTable(): bool
    {
        return SchemaPresence::hasTable('master_address_types');
    }

    private static function hasProfileAddressesTable(): bool
    {
        return SchemaPresence::hasTable('profile_addresses');
    }

    private static function locationColumn(): string
    {
        return SchemaPresence::hasColumn('profile_addresses', 'location_id') ? 'location_id' : 'city_id';
    }

    public static function currentAddressTypeId(): ?int
    {
        if (self::$currentTypeIdMemo !== null) {
            return self::$currentTypeIdMemo === false ? null : self::$currentTypeIdMemo;
        }

        if (! self::hasAddressTypesTable()) {
            return null;
        }

        $resolved = Cache::remember(self::CACHE_KEY_CURRENT_TYPE_ID, self::CACHE_TTL_SECONDS, static function (): ?int {
            $id = DB::table('master_address_types')->where('key', 'current')->value('id');

            return $id !== null ? (int) $id : null;
        });

        $resolved = $resolved !== null ? (int) $resolved : null;
        self::$currentTypeIdMemo = $resolved ?? false;

        return $resolved;
    }

    public static function locationLeafId(int $profileId): ?int
    {
        if (array_key_exists($profileId, self::$locationLeafMemo)) {
            return self::$locationLeafMemo[$profileId];
        }

        $tid = self::currentAddressTypeId();
        if ($tid === null || ! self::hasProfileAddressesTable()) {
            return null;
        }
        $col = self::locationColumn();
        $cid = DB::table('profile_addresses')
            ->where('profile_id', $profileId)
            ->where('address_scope', 'self')
            ->where('address_type_id', $tid)
            ->value($col);

        return self::$locationLeafMemo[$profileId] = ($cid !== null && (int) $cid > 0) ? (int) $cid : null;
    }

    public static function addressLineRaw(int $profileId): ?string
    {
        if (array_key_exists($profileId, self::$addressLineMemo)) {
            return self::$addressLineMemo[$profileId];
        }

        $tid = self::currentAddressTypeId();
        if ($tid === null || ! self::hasProfileAddressesTable()) {
            return null;
        }
        $line = DB::table('profile_addresses')
            ->where('profile_id', $profileId)
            ->where('address_scope', 'self')
            ->where('address_type_id', $tid)
            ->value('address_line');
        if ($line === null) {
            return self::$addressLineMemo[$profileId] = null;
        }
        $t = trim((string) $line);

        return self::$addressLineMemo[$profileId] = ($t !== '' ? $t : null);
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
        if (! self::hasProfileAddressesTable()) {
            return;
        }
        $tid = self::currentAddressTypeId();
        if ($tid === null) {
            return;
        }

        // This is the only writer of the row the read memos describe — invalidate before writing so an
        // exception mid-write cannot leave a stale value behind.
        self::forgetProfile($profileId);

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

        $locCol = self::locationColumn();

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
