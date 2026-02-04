<?php

namespace App\Services;

use App\Models\ConflictRecord;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Phase-3 Day-5: Authority-based conflict resolution.
 * Authority order (locked): Admin > User > Matchmaker > OCR/System
 * OCR/System never resolve. Resolved conflicts are immutable.
 */
class ConflictResolutionService
{
    private const RANK_ADMIN = 1;
    private const RANK_USER = 2;
    private const RANK_MATCHMAKER = 3;
    private const RANK_SYSTEM = 99;

    public static function approveConflict(ConflictRecord $record, User $resolver, string $reason): void
    {
        static::resolve($record, $resolver, $reason, 'APPROVED');
    }

    public static function rejectConflict(ConflictRecord $record, User $resolver, string $reason): void
    {
        static::resolve($record, $resolver, $reason, 'REJECTED');
    }

    public static function overrideConflict(ConflictRecord $record, User $resolver, string $reason): void
    {
        static::resolve($record, $resolver, $reason, 'OVERRIDDEN');
    }

    private static function resolve(ConflictRecord $record, User $resolver, string $reason, string $newStatus): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'resolution_reason' => ['Resolution reason is required and cannot be empty.'],
            ]);
        }

        if ($record->resolution_status !== 'PENDING') {
            throw ValidationException::withMessages([
                'conflict' => ['Conflict is already resolved and cannot be modified.'],
            ]);
        }

        $resolverRank = static::getAuthorityRank($resolver);
        if ($resolverRank >= static::RANK_SYSTEM) {
            throw ValidationException::withMessages([
                'resolver' => ['OCR/System cannot perform human resolution.'],
            ]);
        }

        // Guard: Prevent data deletion - APPROVED/OVERRIDDEN cannot have NULL/empty new_value
        if ($newStatus === 'APPROVED' || $newStatus === 'OVERRIDDEN') {
            if (!static::isValidNewValue($record->new_value)) {
                throw ValidationException::withMessages([
                    'conflict' => ['Cannot approve or override conflict with empty value. Data deletion is not allowed. Please reject this conflict instead.'],
                ]);
            }
            static::applyResolutionToProfile($record, $resolver);
        }

        $record->update([
            'resolution_status' => $newStatus,
            'resolved_by' => $resolver->id,
            'resolved_at' => now(),
            'resolution_reason' => $reason,
        ]);
    }

    /**
     * Apply conflict resolution to profile: update field with new_value.
     * Respects locks, records history, and handles CORE vs EXTENDED fields.
     */
    private static function applyResolutionToProfile(ConflictRecord $record, User $resolver): void
    {
        $profile = MatrimonyProfile::find($record->profile_id);
        if (!$profile) {
            throw ValidationException::withMessages([
                'conflict' => ['Profile not found for conflict resolution.'],
            ]);
        }

        $fieldKey = $record->field_name;
        $fieldType = $record->field_type;
        $newValue = $record->new_value;

        // Check field locks (authority-aware)
        ProfileFieldLockService::assertNotLocked($profile, [$fieldKey], $resolver);

        // Apply update based on field type
        if ($fieldType === 'CORE') {
            // CORE field: update profile directly
            // Record history before update
            $oldValue = static::getCurrentFieldValue($profile, $fieldKey, $fieldType);
            $changedBy = ($resolver->is_admin ?? false)
                ? FieldValueHistoryService::CHANGED_BY_ADMIN
                : FieldValueHistoryService::CHANGED_BY_USER;
            
            FieldValueHistoryService::record(
                $profile->id,
                $fieldKey,
                'CORE',
                $oldValue,
                $newValue,
                $changedBy
            );
            
            $updateData = [$fieldKey => $newValue];
            $profile->update($updateData);
        } else {
            // EXTENDED field: use ExtendedFieldService (handles history internally)
            ExtendedFieldService::saveValuesForProfile(
                $profile,
                [$fieldKey => $newValue],
                $resolver
            );
        }
    }

    /**
     * Validate that new_value is non-empty (prevents data deletion).
     * Returns false for: null, empty string, whitespace-only strings.
     */
    private static function isValidNewValue($newValue): bool
    {
        if ($newValue === null) {
            return false;
        }
        if (!is_string($newValue)) {
            // Non-string values (e.g., numbers) are considered valid
            return true;
        }
        // Empty string or whitespace-only strings are invalid
        return trim($newValue) !== '';
    }

    /**
     * Get current value of a field (CORE or EXTENDED) for history recording.
     */
    private static function getCurrentFieldValue(MatrimonyProfile $profile, string $fieldKey, string $fieldType): ?string
    {
        if ($fieldType === 'CORE') {
            if ($fieldKey === 'gender') {
                $value = $profile->getAttribute('gender') ?? $profile->user?->gender ?? null;
            } else {
                $value = $profile->getAttribute($fieldKey);
            }
            return $value === null || $value === '' ? null : (string) $value;
        }

        // EXTENDED
        $extendedValues = ExtendedFieldService::getValuesForProfile($profile);
        $value = $extendedValues[$fieldKey] ?? null;
        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Authority order: Admin (1) > User (2) > Matchmaker (3) > System/OCR (99)
     */
    private static function getAuthorityRank(User $user): int
    {
        if ($user->is_admin ?? false) {
            return static::RANK_ADMIN;
        }
        return static::RANK_USER;
    }

    public static function canResolve(ConflictRecord $record, User $user): bool
    {
        if ($record->resolution_status !== 'PENDING') {
            return false;
        }
        $rank = static::getAuthorityRank($user);
        return $rank < static::RANK_SYSTEM;
    }
}
