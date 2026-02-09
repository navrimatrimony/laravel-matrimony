<?php

namespace App\Services;

use App\Models\FieldValueHistory;
use App\Models\MatrimonyProfile;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Day-6 / Law 9: Field value history recording and read access.
 * Append-only; immutability enforced by FieldValueHistory model.
 */
class FieldValueHistoryService
{
    public const CHANGED_BY_USER = 'USER';
    public const CHANGED_BY_ADMIN = 'ADMIN';
    public const CHANGED_BY_API = 'API';
    public const CHANGED_BY_MATCHMAKER = 'MATCHMAKER';
    public const CHANGED_BY_SYSTEM = 'SYSTEM';

    /**
     * Record a field value change (append-only). No-op if old and new are equal.
     */
    public static function record(
        int $profileId,
        string $fieldKey,
        string $fieldType,
        $oldValue,
        $newValue,
        string $changedBy
    ): void {
        $oldValue = static::normalizeValue($oldValue);
        $newValue = static::normalizeValue($newValue);

        if ((string) $oldValue === (string) $newValue) {
            return;
        }

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
     * Normalize value for storage: empty string → null, Carbon → formatted string.
     */
    protected static function normalizeValue($value)
    {
        if ($value === '' || $value === null) {
            return null;
        }
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        return $value;
    }

    /**
     * Return last 100 history rows for the profile, newest first.
     */
    public static function getHistoryForProfile(MatrimonyProfile $profile): Collection
    {
        return FieldValueHistory::query()
            ->where('profile_id', $profile->id)
            ->orderByDesc('changed_at')
            ->limit(100)
            ->get();
    }
}
