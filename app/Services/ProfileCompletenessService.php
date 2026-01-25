<?php

namespace App\Services;

use App\Models\MatrimonyProfile;

/*
|--------------------------------------------------------------------------
| ProfileCompletenessService (SSOT Day-7 — Recovery-Day-R3)
|--------------------------------------------------------------------------
|
| Centralized completeness: (filled mandatory / total mandatory) × 100.
| Mandatory: gender, date_of_birth, marital_status, education, location,
| profile_photo. Same logic for demo and real profiles.
|
*/
class ProfileCompletenessService
{
    public const MANDATORY_FIELDS = [
        'gender',
        'date_of_birth',
        'marital_status',
        'education',
        'location',
        'profile_photo',
    ];

    public const THRESHOLD = 70;

    /**
     * Compute completeness percentage (0–100) for a profile.
     */
    public static function percentage(MatrimonyProfile $profile): int
    {
        $filled = 0;
        $total = count(self::MANDATORY_FIELDS);

        $gender = $profile->gender ?? $profile->user?->gender ?? null;
        if ($gender !== null && $gender !== '') $filled++;

        if ($profile->date_of_birth !== null && $profile->date_of_birth !== '') $filled++;
        if ($profile->marital_status !== null && $profile->marital_status !== '') $filled++;
        if ($profile->education !== null && $profile->education !== '') $filled++;
        if ($profile->location !== null && $profile->location !== '') $filled++;

        $photoFilled = $profile->profile_photo !== null && $profile->profile_photo !== ''
            && $profile->photo_approved !== false;
        if ($photoFilled) $filled++;

        return $total ? (int) round(($filled / $total) * 100) : 0;
    }

    /**
     * Whether profile meets 70% threshold.
     */
    public static function meetsThreshold(MatrimonyProfile $profile): bool
    {
        return self::percentage($profile) >= self::THRESHOLD;
    }

    /**
     * Raw SQL condition for "meets 70% OR visibility_override".
     * Uses MatrimonyProfile fields only. Table alias optional.
     */
    public static function sqlSearchVisible(string $table = 'matrimony_profiles'): string
    {
        $t = $table;
        $pct = "(
            (CASE WHEN COALESCE(TRIM({$t}.gender),'') != '' THEN 1 ELSE 0 END) +
            (CASE WHEN {$t}.date_of_birth IS NOT NULL AND {$t}.date_of_birth != '' THEN 1 ELSE 0 END) +
            (CASE WHEN COALESCE(TRIM({$t}.marital_status),'') != '' THEN 1 ELSE 0 END) +
            (CASE WHEN COALESCE(TRIM({$t}.education),'') != '' THEN 1 ELSE 0 END) +
            (CASE WHEN COALESCE(TRIM({$t}.location),'') != '' THEN 1 ELSE 0 END) +
            (CASE WHEN COALESCE(TRIM({$t}.profile_photo),'') != '' AND ({$t}.photo_approved = 1 OR {$t}.photo_approved IS TRUE) THEN 1 ELSE 0 END)
        ) / 6.0 * 100";
        return "({$pct} >= " . self::THRESHOLD . " OR {$t}.visibility_override = 1)";
    }
}
