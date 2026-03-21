<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use Illuminate\Support\Facades\DB;

/**
 * Compute non-persistent partner preference suggestions from the profile.
 * No DB writes – controller uses this only for default form values.
 */
class PartnerPreferenceSuggestionService
{
    public static function profileAge(MatrimonyProfile $profile): ?int
    {
        if (! $profile->date_of_birth) {
            return null;
        }
        try {
            $a = \Carbon\Carbon::parse($profile->date_of_birth)->age;

            return $a > 0 ? $a : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Default partner age range from member gender + age (slider domain 18–80).
     * Male: min = user age − 5, max = user age. Female: min = user age, max = user age + 5.
     *
     * @return array{min: int, max: int}|null
     */
    public static function defaultPreferredAgeRange(MatrimonyProfile $profile): ?array
    {
        $profile->loadMissing('gender');
        $age = self::profileAge($profile);
        if ($age === null) {
            return null;
        }
        $isFemale = ($profile->gender?->key ?? null) === 'female';
        if ($isFemale) {
            $min = $age;
            $max = $age + 5;
        } else {
            $min = $age - 5;
            $max = $age;
        }
        $min = max(18, min(80, $min));
        $max = max(18, min(80, $max));
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return ['min' => $min, 'max' => $max];
    }

    /**
     * @return array<string, mixed>
     */
    public static function suggestForProfile(MatrimonyProfile $profile): array
    {
        $out = [
            'preferred_age_min' => null,
            'preferred_age_max' => null,
            'preferred_income_min' => null,
            'preferred_income_max' => null,
            'preferred_education' => null,
            'preferred_city_id' => null,
            'preferred_religion_ids' => [],
            'preferred_caste_ids' => [],
            'preferred_district_ids' => [],
            'preference_preset' => 'balanced',
        ];

        $ageRange = self::defaultPreferredAgeRange($profile);
        if ($ageRange !== null) {
            $out['preferred_age_min'] = $ageRange['min'];
            $out['preferred_age_max'] = $ageRange['max'];
        }

        // Income: prefer normalized annual amount from income engine; fallback to legacy annual_income.
        $income = null;
        if (!empty($profile->income_normalized_annual_amount)) {
            $income = (float) $profile->income_normalized_annual_amount;
        } elseif (!empty($profile->annual_income)) {
            $income = (float) $profile->annual_income;
        }
        if ($income !== null) {
            $out['preferred_income_min'] = max(0, round($income * 0.7, 2));
            $out['preferred_income_max'] = null;
        }

        if (!empty($profile->highest_education)) {
            $out['preferred_education'] = $profile->highest_education;
        }

        if (!empty($profile->city_id)) {
            $out['preferred_city_id'] = (int) $profile->city_id;
        } elseif (!empty($profile->native_city_id)) {
            $out['preferred_city_id'] = (int) $profile->native_city_id;
        }

        if (!empty($profile->religion_id)) {
            $out['preferred_religion_ids'] = [(int) $profile->religion_id];
        }

        if (!empty($profile->caste_id)) {
            $out['preferred_caste_ids'] = [(int) $profile->caste_id];
        }

        if (!empty($profile->district_id)) {
            $out['preferred_district_ids'] = [(int) $profile->district_id];
        } elseif (!empty($profile->city_id)) {
            $districtId = DB::table('cities')->where('id', $profile->city_id)->value('district_id');
            if ($districtId) {
                $out['preferred_district_ids'] = [(int) $districtId];
            }
        }

        return $out;
    }
}

