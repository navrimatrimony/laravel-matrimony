<?php

namespace App\Services;

use App\Models\FieldValueHistory;
use App\Models\MatrimonyProfile;

/**
 * Phase-3 Day 8: Record field value changes for historical data protection.
 * Call only on UPDATE (not on create). No delete of history records.
 */
class FieldValueHistoryService
{
    public const CHANGED_BY_USER = 'USER';
    public const CHANGED_BY_ADMIN = 'ADMIN';
    public const CHANGED_BY_MATCHMAKER = 'MATCHMAKER';
    public const CHANGED_BY_SYSTEM = 'SYSTEM';

    /**
     * Record one field value change. Call before overwriting the current value.
     */
    public static function record(
        int $profileId,
        string $fieldKey,
        string $fieldType,
        ?string $oldValue,
        ?string $newValue,
        string $changedBy
    ): void {
        FieldValueHistory::create([
            'profile_id' => $profileId,
            'field_key' => $fieldKey,
            'field_type' => $fieldType,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'changed_by' => $changedBy,
            'changed_at' => now(),
        ]);
    }

    /**
     * Get history for a profile (read-only). Ordered by changed_at desc.
     */
    public static function getHistoryForProfile(MatrimonyProfile $profile, int $limit = 100): \Illuminate\Database\Eloquent\Collection
    {
        return FieldValueHistory::where('profile_id', $profile->id)
            ->orderByDesc('changed_at')
            ->limit($limit)
            ->get();
    }
}
