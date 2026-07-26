<?php

namespace App\Services\Profile;

use App\Support\SchemaPresence;
use Illuminate\Support\Facades\DB;

/**
 * Self-scoped {@code profile_addresses} rows by {@code master_address_types.key} (e.g. {@code native}, {@code work}).
 * Replaces legacy parallel columns on {@code matrimony_profiles} when those columns are dropped.
 */
final class ProfileTypedSelfAddressService
{
    public static function masterTypeId(string $key): ?int
    {
        if (! SchemaPresence::hasTable('master_address_types')) {
            return null;
        }
        $id = DB::table('master_address_types')->where('key', $key)->value('id');

        return $id !== null ? (int) $id : null;
    }

    public static function addressLineForSelfType(int $profileId, string $typeKey): ?string
    {
        if (! SchemaPresence::hasTable('profile_addresses')) {
            return null;
        }
        if (! SchemaPresence::hasColumn('profile_addresses', 'address_line')) {
            return null;
        }
        $tid = self::masterTypeId($typeKey);
        if ($tid === null) {
            return null;
        }
        $v = DB::table('profile_addresses')
            ->where('profile_id', $profileId)
            ->where('address_scope', 'self')
            ->where('address_type_id', $tid)
            ->value('address_line');
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s !== '' ? $s : null;
    }

    /**
     * Persist free-form place text on a self scoped typed row ({@code address_line}); does not replace the geo leaf FK.
     */
    public static function upsertSelfTypedAddressLine(int $profileId, string $typeKey, ?string $addressLine): void
    {
        if (! SchemaPresence::hasTable('profile_addresses')) {
            return;
        }
        if (! SchemaPresence::hasColumn('profile_addresses', 'address_line')) {
            return;
        }
        $tid = self::masterTypeId($typeKey);
        if ($tid === null) {
            return;
        }
        $leafCol = SchemaPresence::hasColumn('profile_addresses', 'location_id') ? 'location_id' : 'city_id';
        $normalized = ($addressLine !== null && trim($addressLine) !== '')
            ? mb_substr(trim($addressLine), 0, 255)
            : null;

        $row = DB::table('profile_addresses')
            ->where('profile_id', $profileId)
            ->where('address_scope', 'self')
            ->where('address_type_id', $tid)
            ->first();

        $now = now();
        if ($row) {
            DB::table('profile_addresses')->where('id', $row->id)->update([
                'address_line' => $normalized,
                'updated_at' => $now,
            ]);

            return;
        }
        if ($normalized === null) {
            return;
        }

        DB::table('profile_addresses')->insert([
            'profile_id' => $profileId,
            'address_scope' => 'self',
            'address_type_id' => $tid,
            'address_line' => $normalized,
            $leafCol => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function locationLeafIdForSelfType(int $profileId, string $typeKey): ?int
    {
        if (! SchemaPresence::hasTable('profile_addresses')) {
            return null;
        }
        $tid = self::masterTypeId($typeKey);
        if ($tid === null) {
            return null;
        }
        $col = SchemaPresence::hasColumn('profile_addresses', 'location_id') ? 'location_id' : 'city_id';
        $v = DB::table('profile_addresses')
            ->where('profile_id', $profileId)
            ->where('address_scope', 'self')
            ->where('address_type_id', $tid)
            ->value($col);
        if ($v === null || (int) $v <= 0) {
            return null;
        }

        return (int) $v;
    }

    public static function upsertSelfTypedLeaf(int $profileId, string $typeKey, ?int $leafId): void
    {
        if (! SchemaPresence::hasTable('profile_addresses')) {
            return;
        }
        $tid = self::masterTypeId($typeKey);
        if ($tid === null) {
            return;
        }
        $col = SchemaPresence::hasColumn('profile_addresses', 'location_id') ? 'location_id' : 'city_id';
        $leaf = ($leafId !== null && (int) $leafId > 0) ? (int) $leafId : null;

        $row = DB::table('profile_addresses')
            ->where('profile_id', $profileId)
            ->where('address_scope', 'self')
            ->where('address_type_id', $tid)
            ->first();

        $now = now();
        if ($row) {
            DB::table('profile_addresses')->where('id', $row->id)->update([
                $col => $leaf,
                'updated_at' => $now,
            ]);

            return;
        }
        if ($leaf === null) {
            return;
        }
        DB::table('profile_addresses')->insert([
            'profile_id' => $profileId,
            'address_scope' => 'self',
            'address_type_id' => $tid,
            'address_line' => null,
            $col => $leaf,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
