<?php

namespace App\Services;

use App\Models\MatrimonyProfile;

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
     * profile_photo is randomly selected from unused images in engagement folder at creation time.
     * Stored as full relative path from matrimony_photos (e.g., "engagement/female/f1.jpg").
     * Each image is used only once across all demo profiles. Falls back to null if no unused images available.
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
        $profilePhoto = self::randomDemoPhoto($gender);

        return [
            'gender' => $gender,
            'date_of_birth' => $dob,
            'marital_status' => $marital,
            'education' => $education,
            'location' => $location,
            'caste' => $caste,
            'profile_photo' => $profilePhoto,
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
