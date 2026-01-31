<?php

namespace App\Services;

use App\Models\FieldRegistry;
use App\Models\MatrimonyProfile;
use App\Models\ProfileFieldLock;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Phase-3 Day-6: Field locking after human edit.
 * Authority: Admin > User > Matchmaker > OCR/System. Lock applies per (profile, field).
 * Day-6.3: Authority-aware — equal or higher authority can edit locked fields.
 */
class ProfileFieldLockService
{
    private const RANK_ADMIN = 1;
    private const RANK_USER = 2;
    private const RANK_MATCHMAKER = 3;
    private const RANK_SYSTEM = 99;
    public static function applyLock(MatrimonyProfile $profile, string $fieldKey, string $fieldType, User $user): void
    {
        $registry = FieldRegistry::where('field_key', $fieldKey)->first();
        if (!$registry || !($registry->lock_after_user_edit ?? true)) {
            return;
        }

        ProfileFieldLock::updateOrCreate(
            [
                'profile_id' => $profile->id,
                'field_key' => $fieldKey,
            ],
            [
                'field_type' => $fieldType,
                'locked_by' => $user->id,
                'locked_at' => now(),
            ]
        );
    }

    public static function applyLocks(MatrimonyProfile $profile, array $fieldKeys, string $fieldType, User $user): void
    {
        foreach ($fieldKeys as $key) {
            static::applyLock($profile, $key, $fieldType, $user);
        }
    }

    public static function isLocked(MatrimonyProfile $profile, string $fieldKey): bool
    {
        return ProfileFieldLock::where('profile_id', $profile->id)
            ->where('field_key', $fieldKey)
            ->exists();
    }

    public static function getLockInfo(MatrimonyProfile $profile, string $fieldKey): ?array
    {
        $lock = ProfileFieldLock::where('profile_id', $profile->id)
            ->where('field_key', $fieldKey)
            ->with('lockedByUser')
            ->first();

        if (!$lock) {
            return null;
        }

        return [
            'locked' => true,
            'locked_by' => $lock->locked_by,
            'locked_at' => $lock->locked_at,
            'locked_by_name' => $lock->lockedByUser?->name ?? '—',
        ];
    }

    public static function getLocksForProfile(MatrimonyProfile $profile): array
    {
        $locks = ProfileFieldLock::where('profile_id', $profile->id)
            ->with('lockedByUser')
            ->get();

        $out = [];
        foreach ($locks as $lock) {
            $out[$lock->field_key] = [
                'locked' => true,
                'locked_by' => $lock->locked_by,
                'locked_at' => $lock->locked_at?->format('Y-m-d H:i'),
                'locked_by_name' => $lock->lockedByUser?->name ?? '—',
            ];
        }
        return $out;
    }

    /**
     * Returns authority rank for a user. Lower = higher authority.
     * Admin (1) > User (2) > Matchmaker (3) > System/OCR (99)
     */
    public static function getAuthorityRank(?User $user): int
    {
        if ($user === null) {
            return static::RANK_SYSTEM;
        }
        if ($user->is_admin ?? false) {
            return static::RANK_ADMIN;
        }
        // Placeholder: matchmaker role not yet in User model
        return static::RANK_USER;
    }

    /**
     * Throws ValidationException if any field is locked AND actor has lower authority than locker.
     * Equal or higher authority can edit. OCR/System (null actor) cannot edit locked fields.
     *
     * @param  User|null  $actor  Current editor (null = OCR/System)
     */
    public static function assertNotLocked(MatrimonyProfile $profile, array $fieldKeys, ?User $actor = null): void
    {
        $actorRank = static::getAuthorityRank($actor);

        $locked = [];
        $locks = ProfileFieldLock::where('profile_id', $profile->id)
            ->whereIn('field_key', $fieldKeys)
            ->with('lockedByUser')
            ->get()
            ->keyBy('field_key');

        foreach ($fieldKeys as $key) {
            $lock = $locks->get($key);
            if (!$lock) {
                continue;
            }
            $lockerUser = $lock->lockedByUser;
            $lockerRank = $lockerUser ? static::getAuthorityRank($lockerUser) : static::RANK_SYSTEM;

            // Allow if actor has equal or higher authority (lower or equal rank number)
            if ($actorRank <= $lockerRank) {
                continue;
            }
            $locked[] = $key;
        }

        if (!empty($locked)) {
            throw ValidationException::withMessages([
                'fields' => ['Cannot overwrite locked fields: ' . implode(', ', $locked) . '.'],
            ]);
        }
    }
}
