<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Minimum marriage age — single source of truth (PO decision 2026-07-22).
 *
 * Female 18 / male 21 (Indian legal minimums). Consumed by all three write
 * surfaces — web wizard (ProfileWizardController), mobile full PUT
 * (MatrimonyProfileApiController) and the onboarding step engine
 * (MobileProfileStepSnapshotService) — so the rule can never diverge per
 * surface the way the marital rules did.
 *
 * When gender is unknown at validation time the 18-year floor applies; the
 * stricter male check re-fires on any later write once gender_id is present.
 */
final class MarriageAgePolicy
{
    public const FEMALE_MIN_AGE = 18;

    public const MALE_MIN_AGE = 21;

    public static function minimumAgeForGenderKey(?string $genderKey): int
    {
        return $genderKey === 'male' ? self::MALE_MIN_AGE : self::FEMALE_MIN_AGE;
    }

    public static function genderKeyForId(int|string|null $genderId): ?string
    {
        if ($genderId === null || $genderId === '' || ! Schema::hasTable('master_genders')) {
            return null;
        }

        // NOTE: explicit where('id', ...) — whereKey() on the base Query Builder
        // is a dynamic where on a column literally named "key" (which this table
        // has!), silently matching nothing. Caught by ZzAgeProbe during A2.
        $key = DB::table('master_genders')->where('id', (int) $genderId)->value('key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Returns a user-facing error when the DOB violates the policy
     * (under-age or in the future); null when acceptable or unparseable
     * (unparseable input is left to the 'date' validation rule).
     */
    public static function dateOfBirthError(mixed $dob, ?string $genderKey): ?string
    {
        if ($dob === null || $dob === '') {
            return null;
        }

        try {
            $date = Carbon::parse((string) $dob)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        $minAge = self::minimumAgeForGenderKey($genderKey);

        if ($date->isFuture()) {
            return self::message($genderKey, $minAge);
        }

        if ($date->diffInYears(Carbon::now()) < $minAge) {
            return self::message($genderKey, $minAge);
        }

        return null;
    }

    private static function message(?string $genderKey, int $minAge): string
    {
        $marathi = $genderKey === 'male'
            ? 'उमेदवाराचे किमान वय २१ वर्षे आवश्यक आहे. जन्मतारीख तपासा.'
            : 'उमेदवाराचे किमान वय १८ वर्षे आवश्यक आहे. जन्मतारीख तपासा.';

        return $marathi." (Minimum age {$minAge} years required.)";
    }
}
