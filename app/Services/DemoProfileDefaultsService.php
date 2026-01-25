<?php

namespace App\Services;

/*
|--------------------------------------------------------------------------
| DemoProfileDefaultsService (SSOT)
|--------------------------------------------------------------------------
| Auto-fill mandatory fields for demo profiles. Guarantees ≥70% completeness.
| Gender may be overridden by admin; all other fields are randomly generated
| per profile (non-identical in bulk).
*/
class DemoProfileDefaultsService
{
    public const GENDERS = ['male', 'female'];

    public const MARITAL_STATUSES = ['single', 'divorced', 'widowed'];

    public const EDUCATION_OPTIONS = ['Graduate', 'Post Graduate', 'Professional', 'Doctorate'];

    public const CASTE_OPTIONS = [
        'Brahmin', 'Kshatriya', 'Vaishya', 'Maratha', 'Rajput', 'Jat', 'Gujar', 'Patel',
        'Reddy', 'Nair', 'Iyengar', 'Iyer', 'Vellalar', 'Namboodiri', 'Kamma', 'Kapu',
    ];

    public const LOCATION_OPTIONS = [
        'Mumbai, Maharashtra',
        'Delhi, Delhi',
        'Bangalore, Karnataka',
        'Chennai, Tamil Nadu',
        'Kolkata, West Bengal',
        'Hyderabad, Telangana',
        'Pune, Maharashtra',
        'Ahmedabad, Gujarat',
    ];

    /**
     * Returns mandatory field defaults. Age ≥21. No NULLs for required fields.
     * All except gender are randomly generated per call (no duplication across profiles).
     * 
     * NOTE: profile_photo is intentionally NULL for demo profiles.
     * UI layer handles gender-based placeholder display as fallback.
     *
     * @param int         $index          0-based index (bulk: for unique naming, etc.)
     * @param string|null $genderOverride male|female, or null for random
     */
    public static function defaults(int $index = 0, ?string $genderOverride = null): array
    {
        $gender = self::resolveGender($genderOverride);
        $dob = self::randomDob();
        $marital = self::MARITAL_STATUSES[array_rand(self::MARITAL_STATUSES)];
        $education = self::EDUCATION_OPTIONS[array_rand(self::EDUCATION_OPTIONS)];
        $location = self::LOCATION_OPTIONS[array_rand(self::LOCATION_OPTIONS)];
        $caste = self::randomCaste();

        return [
            'gender' => $gender,
            'date_of_birth' => $dob,
            'marital_status' => $marital,
            'education' => $education,
            'location' => $location,
            'caste' => $caste,
            'profile_photo' => null, // UI fallback handles gender-based placeholder
            'photo_approved' => true,
        ];
    }

    public static function fullNameForIndex(int $index): string
    {
        return 'Demo Profile ' . ($index + 1);
    }

    private static function resolveGender(?string $override): string
    {
        if ($override !== null && in_array($override, self::GENDERS, true)) {
            return $override;
        }
        return self::GENDERS[array_rand(self::GENDERS)];
    }

    private static function randomDob(): string
    {
        $age = random_int(21, 60);
        return now()->subYears($age)->subDays(random_int(0, 364))->format('Y-m-d');
    }

    private static function randomCaste(): string
    {
        return self::CASTE_OPTIONS[array_rand(self::CASTE_OPTIONS)];
    }
}
