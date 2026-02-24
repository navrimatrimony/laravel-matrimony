<?php

namespace App\Services;

use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\FieldRegistry;
use App\Models\MatrimonyProfile;
use App\Models\State;
use App\Models\Taluka;

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
     * Name pools mapped by caste and gender.
     * Simple Indian first names (no religion inference).
     */
    private static function getNamePools(): array
    {
        return [
            'Brahmin' => [
                'male' => ['Arjun', 'Rohan', 'Aditya', 'Vikram', 'Karan', 'Rahul', 'Siddharth', 'Aryan', 'Krishna', 'Dev'],
                'female' => ['Priya', 'Ananya', 'Kavya', 'Meera', 'Shreya', 'Aditi', 'Divya', 'Riya', 'Sneha', 'Anjali'],
            ],
            'Kshatriya' => [
                'male' => ['Raj', 'Vikram', 'Arjun', 'Karan', 'Yash', 'Rohan', 'Aryan', 'Krishna', 'Dev', 'Rahul'],
                'female' => ['Rajni', 'Kavya', 'Meera', 'Priya', 'Ananya', 'Shreya', 'Aditi', 'Divya', 'Riya', 'Sneha'],
            ],
            'Vaishya' => [
                'male' => ['Rahul', 'Arjun', 'Vikram', 'Karan', 'Aditya', 'Rohan', 'Aryan', 'Yash', 'Dev', 'Krishna'],
                'female' => ['Priya', 'Ananya', 'Kavya', 'Meera', 'Shreya', 'Aditi', 'Divya', 'Riya', 'Sneha', 'Anjali'],
            ],
            'Maratha' => [
                'male' => ['Raj', 'Vikram', 'Arjun', 'Karan', 'Yash', 'Rohan', 'Aryan', 'Krishna', 'Dev', 'Rahul'],
                'female' => ['Rajni', 'Kavya', 'Meera', 'Priya', 'Ananya', 'Shreya', 'Aditi', 'Divya', 'Riya', 'Sneha'],
            ],
            'Rajput' => [
                'male' => ['Raj', 'Vikram', 'Arjun', 'Karan', 'Yash', 'Rohan', 'Aryan', 'Krishna', 'Dev', 'Rahul'],
                'female' => ['Rajni', 'Kavya', 'Meera', 'Priya', 'Ananya', 'Shreya', 'Aditi', 'Divya', 'Riya', 'Sneha'],
            ],
            'Jat' => [
                'male' => ['Raj', 'Vikram', 'Arjun', 'Karan', 'Yash', 'Rohan', 'Aryan', 'Krishna', 'Dev', 'Rahul'],
                'female' => ['Rajni', 'Kavya', 'Meera', 'Priya', 'Ananya', 'Shreya', 'Aditi', 'Divya', 'Riya', 'Sneha'],
            ],
            'Gujar' => [
                'male' => ['Raj', 'Vikram', 'Arjun', 'Karan', 'Yash', 'Rohan', 'Aryan', 'Krishna', 'Dev', 'Rahul'],
                'female' => ['Rajni', 'Kavya', 'Meera', 'Priya', 'Ananya', 'Shreya', 'Aditi', 'Divya', 'Riya', 'Sneha'],
            ],
            'Patel' => [
                'male' => ['Raj', 'Vikram', 'Arjun', 'Karan', 'Yash', 'Rohan', 'Aryan', 'Krishna', 'Dev', 'Rahul'],
                'female' => ['Rajni', 'Kavya', 'Meera', 'Priya', 'Ananya', 'Shreya', 'Aditi', 'Divya', 'Riya', 'Sneha'],
            ],
            'Reddy' => [
                'male' => ['Raj', 'Vikram', 'Arjun', 'Karan', 'Yash', 'Rohan', 'Aryan', 'Krishna', 'Dev', 'Rahul'],
                'female' => ['Rajni', 'Kavya', 'Meera', 'Priya', 'Ananya', 'Shreya', 'Aditi', 'Divya', 'Riya', 'Sneha'],
            ],
            'Nair' => [
                'male' => ['Arjun', 'Rohan', 'Aditya', 'Vikram', 'Karan', 'Rahul', 'Siddharth', 'Aryan', 'Krishna', 'Dev'],
                'female' => ['Priya', 'Ananya', 'Kavya', 'Meera', 'Shreya', 'Aditi', 'Divya', 'Riya', 'Sneha', 'Anjali'],
            ],
            'Iyengar' => [
                'male' => ['Arjun', 'Rohan', 'Aditya', 'Vikram', 'Karan', 'Rahul', 'Siddharth', 'Aryan', 'Krishna', 'Dev'],
                'female' => ['Priya', 'Ananya', 'Kavya', 'Meera', 'Shreya', 'Aditi', 'Divya', 'Riya', 'Sneha', 'Anjali'],
            ],
            'Iyer' => [
                'male' => ['Arjun', 'Rohan', 'Aditya', 'Vikram', 'Karan', 'Rahul', 'Siddharth', 'Aryan', 'Krishna', 'Dev'],
                'female' => ['Priya', 'Ananya', 'Kavya', 'Meera', 'Shreya', 'Aditi', 'Divya', 'Riya', 'Sneha', 'Anjali'],
            ],
            'Vellalar' => [
                'male' => ['Arjun', 'Rohan', 'Aditya', 'Vikram', 'Karan', 'Rahul', 'Siddharth', 'Aryan', 'Krishna', 'Dev'],
                'female' => ['Priya', 'Ananya', 'Kavya', 'Meera', 'Shreya', 'Aditi', 'Divya', 'Riya', 'Sneha', 'Anjali'],
            ],
            'Namboodiri' => [
                'male' => ['Arjun', 'Rohan', 'Aditya', 'Vikram', 'Karan', 'Rahul', 'Siddharth', 'Aryan', 'Krishna', 'Dev'],
                'female' => ['Priya', 'Ananya', 'Kavya', 'Meera', 'Shreya', 'Aditi', 'Divya', 'Riya', 'Sneha', 'Anjali'],
            ],
            'Kamma' => [
                'male' => ['Raj', 'Vikram', 'Arjun', 'Karan', 'Yash', 'Rohan', 'Aryan', 'Krishna', 'Dev', 'Rahul'],
                'female' => ['Rajni', 'Kavya', 'Meera', 'Priya', 'Ananya', 'Shreya', 'Aditi', 'Divya', 'Riya', 'Sneha'],
            ],
            'Kapu' => [
                'male' => ['Raj', 'Vikram', 'Arjun', 'Karan', 'Yash', 'Rohan', 'Aryan', 'Krishna', 'Dev', 'Rahul'],
                'female' => ['Rajni', 'Kavya', 'Meera', 'Priya', 'Ananya', 'Shreya', 'Aditi', 'Divya', 'Riya', 'Sneha'],
            ],
        ];
    }

    /**
     * Returns mandatory field defaults. Age ≥21. No NULLs for required fields.
     * All except gender are randomly generated per call (no duplication across profiles).
     * 
     * profile_photo is randomly selected from unused images in engagement folder at creation time.
     * Stored as full relative path from matrimony_photos (e.g., "engagement/female/f1.jpg").
     * Each image is used only once across all demo profiles. Falls back to null if no unused images available.
     * 
     * full_name is auto-generated based on gender and caste at creation time only.
     *
     * @param int         $index          0-based index (bulk: for unique naming fallback)
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
        $profilePhoto = self::randomDemoPhoto($gender);
        $fullName = self::generateFullName($gender, $caste, $index);

        return [
            'gender' => $gender,
            'date_of_birth' => $dob,
            'marital_status' => $marital,
            'highest_education' => $education,
            'location' => $location,
            'caste' => $caste,
            'profile_photo' => $profilePhoto,
            'photo_approved' => true,
            'full_name' => $fullName,
        ];
    }

    /**
     * Phase-4: Defaults for demo profile autofill. Only fill if null; do not override.
     * Uses: realistic name, gender, age 23–35, Never Married (single), Graduate,
     * caste, height_cm 150–180, location hierarchy (valid country/state/district/city).
     */
    public static function defaultsForDemo(int $index = 0, ?string $genderOverride = null): array
    {
        $gender = self::resolveGender($genderOverride);
        $dob = self::randomDobDemo();
        $caste = self::randomCaste();
        $profilePhoto = self::randomDemoPhoto($gender);
        $fullName = self::generateFullName($gender, $caste, $index);
        $heightCm = random_int(150, 180);
        $hierarchy = self::locationHierarchyForDemo();

        return array_merge([
            'full_name' => $fullName,
            'gender' => $gender,
            'date_of_birth' => $dob,
            'marital_status' => 'single',
            'highest_education' => 'Graduate',
            'caste' => $caste,
            'height_cm' => $heightCm,
            'profile_photo' => $profilePhoto,
            'photo_approved' => true,
        ], $hierarchy);
    }

    /** Age 23–35 for demo. */
    private static function randomDobDemo(): string
    {
        $age = random_int(23, 35);
        return now()->subYears($age)->subDays(random_int(0, 364))->format('Y-m-d');
    }

    /** Valid country/state/district/taluka/city IDs; nulls if any level missing. */
    private static function locationHierarchyForDemo(): array
    {
        $country = Country::query()->inRandomOrder()->first();
        if (!$country) {
            return ['country_id' => null, 'state_id' => null, 'district_id' => null, 'taluka_id' => null, 'city_id' => null];
        }
        $state = State::where('country_id', $country->id)->inRandomOrder()->first();
        if (!$state) {
            return ['country_id' => $country->id, 'state_id' => null, 'district_id' => null, 'taluka_id' => null, 'city_id' => null];
        }
        $district = District::where('state_id', $state->id)->inRandomOrder()->first();
        if (!$district) {
            return ['country_id' => $country->id, 'state_id' => $state->id, 'district_id' => null, 'taluka_id' => null, 'city_id' => null];
        }
        $taluka = Taluka::where('district_id', $district->id)->inRandomOrder()->first();
        if (!$taluka) {
            return ['country_id' => $country->id, 'state_id' => $state->id, 'district_id' => $district->id, 'taluka_id' => null, 'city_id' => null];
        }
        $city = City::where('taluka_id', $taluka->id)->inRandomOrder()->first();
        return [
            'country_id' => $country->id,
            'state_id' => $state->id,
            'district_id' => $district->id,
            'taluka_id' => $taluka->id,
            'city_id' => $city?->id,
        ];
    }

    /**
     * Phase-4: Generate dummy extended field values for demo profile.
     * Loads EXTENDED + is_enabled from field_registry; returns [field_key => value] by data_type.
     */
    public static function extendedDefaultsForProfile(): array
    {
        $fields = FieldRegistry::where('field_type', 'EXTENDED')
            ->where(function ($q) {
                $q->where('is_enabled', true)->orWhereNull('is_enabled');
            })
            ->get();
        $out = [];
        foreach ($fields as $field) {
            $out[$field->field_key] = self::dummyValueForDataType($field->data_type);
        }
        return $out;
    }

    private static function dummyValueForDataType(string $dataType): string|int
    {
        return match ($dataType) {
            'text' => 'Reading, Traveling',
            'number' => (string) random_int(1, 99),
            'date' => now()->subYears(random_int(1, 10))->format('Y-m-d'),
            'boolean' => random_int(0, 1) === 1 ? '1' : '0',
            'select' => '1',
            default => '—',
        };
    }

    /**
     * Generate full_name for demo profile based on gender and caste.
     * Falls back to "Demo Profile {index+1}" if caste is missing or not mapped.
     *
     * @param string $gender male|female
     * @param string|null $caste Caste value or null
     * @param int $index 0-based index for fallback naming
     * @return string Generated full name
     */
    public static function generateFullName(string $gender, ?string $caste, int $index = 0): string
    {
        // Validate gender
        if (!in_array($gender, self::GENDERS, true)) {
            return 'Demo Profile ' . ($index + 1);
        }

        // If caste is missing or empty, use fallback
        if (empty($caste) || !is_string($caste)) {
            return 'Demo Profile ' . ($index + 1);
        }

        // Get name pools
        $namePools = self::getNamePools();

        // Check if caste is mapped
        if (!isset($namePools[$caste]) || !isset($namePools[$caste][$gender])) {
            return 'Demo Profile ' . ($index + 1);
        }

        // Get names for this caste and gender
        $names = $namePools[$caste][$gender];

        // If pool is empty, use fallback
        if (empty($names) || !is_array($names)) {
            return 'Demo Profile ' . ($index + 1);
        }

        // Randomly select one name
        $selectedName = $names[array_rand($names)];

        // Ensure name doesn't exceed max length (255 chars)
        if (strlen($selectedName) > 255) {
            return 'Demo Profile ' . ($index + 1);
        }

        return $selectedName;
    }

    /**
     * @deprecated Use generateFullName() instead. Kept for backward compatibility.
     */
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

    /**
     * Randomly select an unused demo profile photo from gender-specific engagement folder.
     * Each image is used only once across all demo profiles. Returns null if no unused images available.
     *
     * @param string $gender male|female
     * @return string|null Full relative path from matrimony_photos (e.g., "engagement/female/f1.jpg"), or null if no unused images found
     */
    private static function randomDemoPhoto(string $gender): ?string
    {
        if (!in_array($gender, self::GENDERS, true)) {
            return null;
        }

        $folderPath = public_path('uploads/matrimony_photos/engagement/' . $gender);

        if (!is_dir($folderPath) || !is_readable($folderPath)) {
            return null;
        }

        $availableFiles = [];
        $handle = opendir($folderPath);
        
        if ($handle === false) {
            return null;
        }

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            
            $filePath = $folderPath . DIRECTORY_SEPARATOR . $entry;
            
            if (is_file($filePath)) {
                $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (in_array($extension, $imageExtensions, true)) {
                    $availableFiles[] = $entry;
                }
            }
        }
        
        closedir($handle);

        if (empty($availableFiles)) {
            return null;
        }

        $usedFilenames = self::getUsedDemoPhotoFilenames($gender);
        $unusedFiles = array_diff($availableFiles, $usedFilenames);

        if (empty($unusedFiles)) {
            return null;
        }

        $selectedFilename = $unusedFiles[array_rand($unusedFiles)];
        return 'engagement/' . $gender . '/' . $selectedFilename;
    }

    /**
     * Get list of filenames (extracted from stored paths) already assigned to demo profiles of given gender.
     * Handles both old format (filename only) and new format (relative path).
     *
     * @param string $gender male|female
     * @return array Array of filenames only (for comparison with available files)
     */
    private static function getUsedDemoPhotoFilenames(string $gender): array
    {
        $usedPhotos = MatrimonyProfile::where('is_demo', true)
            ->where('gender', $gender)
            ->whereNotNull('profile_photo')
            ->pluck('profile_photo')
            ->toArray();

        $filenames = [];
        foreach ($usedPhotos as $photo) {
            if (empty($photo) || !is_string($photo)) {
                continue;
            }
            
            // Extract filename from path (handles both "filename.jpg" and "engagement/gender/filename.jpg")
            $filename = basename($photo);
            
            // Only include if it's from engagement folder (new format) or just filename (old format)
            // For old format, we need to check if it matches engagement folder structure
            if (strpos($photo, 'engagement/' . $gender . '/') === 0) {
                // New format: extract filename
                $filenames[] = $filename;
            } elseif (strpos($photo, '/') === false) {
                // Old format: filename only, check if it exists in engagement folder
                $engagementPath = public_path('uploads/matrimony_photos/engagement/' . $gender . '/' . $photo);
                if (file_exists($engagementPath)) {
                    $filenames[] = $filename;
                }
            }
        }

        return array_unique($filenames);
    }
}
