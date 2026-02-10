<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase-3 Day-6: Per-profile field lock. Uses profile_field_locks table.
 */
class ProfileFieldLockService
{
    public static function getLocksForProfile(MatrimonyProfile $profile): Collection
    {
        return collect(DB::table('profile_field_locks')->where('profile_id', $profile->id)->get());
    }

    public static function isLocked(MatrimonyProfile $profile, string $fieldKey): bool
    {
        return DB::table('profile_field_locks')
            ->where('profile_id', $profile->id)
            ->where('field_key', $fieldKey)
            ->exists();
    }

    public static function assertNotLocked(MatrimonyProfile $profile, array $fields, $actor): void
    {
        // Admins can override locked fields (SSOT: Day-6 governance rules)
        if ($actor instanceof \App\Models\User && $actor->is_admin === true) {
            return;
        }

        $lockedFields = [];
        foreach ($fields as $fieldKey) {
            if (self::isLocked($profile, $fieldKey)) {
                $lockedFields[$fieldKey] = ["Field \"{$fieldKey}\" is locked and cannot be modified."];
            }
        }

        if (!empty($lockedFields)) {
            throw ValidationException::withMessages($lockedFields);
        }
    }

    public static function applyLocks(MatrimonyProfile $profile, array $fields, string $type, $actor): void
    {
        $lockedBy = $actor && is_object($actor) && isset($actor->id) ? $actor->id : null;
        $lockedAt = now();

        foreach ($fields as $fieldKey) {
            if (self::isLocked($profile, $fieldKey)) {
                continue;
            }
            DB::table('profile_field_locks')->insert([
                'profile_id' => $profile->id,
                'field_key' => $fieldKey,
                'field_type' => $type,
                'locked_by' => $lockedBy,
                'locked_at' => $lockedAt,
                'created_at' => $lockedAt,
                'updated_at' => $lockedAt,
            ]);
        }
    }

    /**
     * Day-7: Remove lock from a specific field (admin override with reason).
     */
    public static function removeLock(MatrimonyProfile $profile, string $fieldKey): bool
    {
        return DB::table('profile_field_locks')
            ->where('profile_id', $profile->id)
            ->where('field_key', $fieldKey)
            ->delete() > 0;
    }
}
