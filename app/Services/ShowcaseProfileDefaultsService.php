<?php

namespace App\Services;

use App\Models\Caste;
use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\FieldRegistry;
use App\Models\MasterBloodGroup;
use App\Models\MasterComplexion;
use App\Models\MasterDiet;
use App\Models\MasterDrinkingStatus;
use App\Models\MasterFamilyType;
use App\Models\MasterEducation;
use App\Models\MasterGender;
use App\Models\MasterIncomeCurrency;
use App\Models\MasterMaritalStatus;
use App\Models\MasterMotherTongue;
use App\Models\MasterPhysicalBuild;
use App\Models\MasterSmokingStatus;
use App\Models\MatrimonyProfile;
use App\Models\Profession;
use App\Models\Religion;
use App\Models\State;
use App\Models\SubCaste;
use App\Models\Taluka;
use App\Models\WorkingWithType;
use App\Services\Showcase\ShowcaseBulkCreateSettings;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| ShowcaseProfileDefaultsService (SSOT)
|--------------------------------------------------------------------------
| Auto-fill mandatory fields for showcase profiles. Guarantees ≥70% completeness.
| Gender may be overridden by admin; all other fields are randomly generated
| per profile (non-identical in bulk).
*/
class ShowcaseProfileDefaultsService
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
     * Each image is used only once across all showcase profiles. Falls back to null if no unused images available.
     *
     * full_name is auto-generated based on gender and caste at creation time only.
     *
     * @param  int  $index  0-based index (bulk: for unique naming fallback)
     * @param  string|null  $genderOverride  male|female, or null for random
     */
    public static function defaults(int $index = 0, ?string $genderOverride = null): array
    {
        $gender = self::resolveGender($genderOverride);
        $dob = self::randomDob();
        $marital = self::MARITAL_STATUSES[array_rand(self::MARITAL_STATUSES)];
        $education = self::EDUCATION_OPTIONS[array_rand(self::EDUCATION_OPTIONS)];
        $location = self::LOCATION_OPTIONS[array_rand(self::LOCATION_OPTIONS)];
        $caste = self::randomCaste();
        $profilePhoto = self::randomShowcasePhoto($gender);
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
     * Phase-4: Defaults for showcase profile autofill. Only fill if null; do not override.
     * Uses: realistic name, gender, age 23–35, Never Married (single), Graduate,
     * caste, height_cm 150–180, location hierarchy (valid country/state/district/city).
     */
    public static function defaultsForShowcase(int $index = 0, ?string $genderOverride = null): array
    {
        $gender = self::resolveGender($genderOverride);
        $dob = self::randomDobShowcase();
        $caste = self::randomCaste();
        $profilePhoto = self::randomShowcasePhoto($gender);
        $fullName = self::generateFullName($gender, $caste, $index);
        $heightCm = random_int(150, 180);
        $hierarchy = self::locationHierarchyForShowcase();

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

    /**
     * Full attributes for MatrimonyProfile::create() — all fillable fields with realistic data.
     * Uses master table IDs (gender_id, marital_status_id, religion_id, caste_id, sub_caste_id, etc.).
     * No legacy labels; data looks like real users for manual testing.
     */
    /**
     * @param  array<string, mixed>|null  $bulkPolicy  raw or partial; normalized when non-null
     */
    public static function fullAttributesForShowcaseProfile(int $index = 0, ?string $genderOverride = null, ?array $bulkPolicy = null): array
    {
        $policy = $bulkPolicy !== null ? ShowcaseBulkCreateSettings::normalize($bulkPolicy) : null;

        $gender = self::resolveGender($genderOverride);
        $dob = $policy !== null
            ? self::randomDobForAgeRange((int) $policy['age_min'], (int) $policy['age_max'])
            : self::randomDobShowcase();
        $heightCm = $policy !== null
            ? random_int((int) $policy['height_cm_min'], (int) $policy['height_cm_max'])
            : random_int(155, 182);
        $weightKg = random_int(50, 85);

        $loc = $policy !== null
            ? self::locationHierarchyForShowcaseFromRealUsersPolicy($policy)
            : self::locationHierarchyForShowcaseFromRealUsers();
        if ($loc === null && $policy !== null) {
            $loc = self::locationHierarchyForShowcaseFromRealUsers();
        }

        $profilePhoto = self::randomShowcasePhoto($gender);

        $genderId = MasterGender::where('key', $gender)->where('is_active', true)->value('id');

        if ($policy !== null && $policy['marital_status_ids'] !== []) {
            $maritalId = MasterMaritalStatus::query()
                ->where('is_active', true)
                ->whereIn('id', $policy['marital_status_ids'])
                ->inRandomOrder()
                ->value('id');
            if ($maritalId === null) {
                $maritalId = MasterMaritalStatus::where('key', 'never_married')->where('is_active', true)->value('id')
                    ?? MasterMaritalStatus::where('is_active', true)->inRandomOrder()->value('id');
            }
        } else {
            $maritalId = MasterMaritalStatus::where('key', 'never_married')->where('is_active', true)->value('id')
                ?? MasterMaritalStatus::where('is_active', true)->inRandomOrder()->value('id');
        }

        if ($policy !== null && $policy['religion_ids'] !== []) {
            $religion = Religion::query()
                ->where(function ($q) {
                    $q->where('is_active', true)->orWhereNull('is_active');
                })
                ->whereIn('id', $policy['religion_ids'])
                ->inRandomOrder()
                ->first();
        } else {
            $religion = self::pickAllowedShowcaseReligion();
        }

        $religionId = $religion?->id;
        $casteId = null;
        $subCasteId = null;
        if ($religion) {
            $casteQ = Caste::where('religion_id', $religion->id)->where(function ($q) {
                $q->where('is_active', true)->orWhereNull('is_active');
            });
            if ($policy !== null && $policy['caste_ids'] !== []) {
                $casteQ->whereIn('id', $policy['caste_ids']);
            }
            $caste = $casteQ->inRandomOrder()->first();
            if (! $caste && $policy !== null && $policy['caste_ids'] !== []) {
                $caste = Caste::where('religion_id', $religion->id)->where(function ($q) {
                    $q->where('is_active', true)->orWhereNull('is_active');
                })->inRandomOrder()->first();
            }
            $casteId = $caste?->id;
            if ($caste) {
                $subCaste = SubCaste::where('caste_id', $caste->id)->inRandomOrder()->first();
                $subCasteId = $subCaste?->id;
            }
        }

        $complexionId = MasterComplexion::where('is_active', true)->inRandomOrder()->value('id');
        if ($policy !== null && $policy['fixed_complexion_ids'] !== []) {
            $pickCx = MasterComplexion::query()
                ->where('is_active', true)
                ->whereIn('id', $policy['fixed_complexion_ids'])
                ->inRandomOrder()
                ->value('id');
            if ($pickCx !== null) {
                $complexionId = $pickCx;
            }
        }

        $physicalBuildId = MasterPhysicalBuild::where('is_active', true)->inRandomOrder()->value('id');
        if ($policy !== null && $policy['fixed_physical_build_ids'] !== []) {
            $pickPb = MasterPhysicalBuild::query()
                ->where('is_active', true)
                ->whereIn('id', $policy['fixed_physical_build_ids'])
                ->inRandomOrder()
                ->value('id');
            if ($pickPb !== null) {
                $physicalBuildId = $pickPb;
            }
        }

        $bloodGroupId = MasterBloodGroup::where('is_active', true)->inRandomOrder()->value('id');
        $familyTypeId = MasterFamilyType::where('is_active', true)->inRandomOrder()->value('id');
        $incomeCurrencyId = MasterIncomeCurrency::where('is_active', true)->inRandomOrder()->value('id');

        $fullName = self::generateFullNameForReligion($gender, $religion, $index);
        if (random_int(1, 100) <= 50) {
            $parts = preg_split('/\s+/u', trim($fullName), 2);
            $firstOnly = trim((string) ($parts[0] ?? ''));
            if ($firstOnly !== '') {
                $fullName = $firstOnly;
            }
        }

        if ($policy !== null && $policy['master_education_ids'] !== []) {
            $eduRow = MasterEducation::query()
                ->where('is_active', true)
                ->whereIn('id', $policy['master_education_ids'])
                ->inRandomOrder()
                ->first();
            $eduLabel = $eduRow ? trim((string) $eduRow->name) : 'Graduate';
            if ($eduLabel === '') {
                $eduLabel = 'Graduate';
            }
            [$highestEducation, $occupationTitle] = self::pickEducationAndOccupation([$eduLabel]);
        } else {
            $educations = [
                'B.Com', 'B.E.', 'B.Tech', 'M.Com', 'M.B.A.', 'B.Sc', 'M.Sc', 'B.A.', 'M.A.',
                'Graduate', 'Post Graduate', 'Professional',
                'HSC', 'SSC', 'Below SSC',
            ];
            [$highestEducation, $occupationTitle] = self::pickEducationAndOccupation($educations);
        }

        $isFemale = ($gender === 'female');
        $isNonWorking = $isFemale && (random_int(1, 100) <= 80);

        if ($policy !== null && $policy['diet_ids'] !== []) {
            $dietId = MasterDiet::query()
                ->where('is_active', true)
                ->whereIn('id', $policy['diet_ids'])
                ->inRandomOrder()
                ->value('id');
            if ($dietId === null) {
                $dietId = self::pickDietIdForReligion($religion);
            }
        } else {
            $dietId = self::pickDietIdForReligion($religion);
        }

        $smokingStatusId = self::pickSmokingStatusIdForReligion($religion);
        $drinkingStatusId = self::pickDrinkingStatusIdForReligion($religion);
        if ($policy !== null && $policy['fixed_smoking_status_id']) {
            $sid = (int) $policy['fixed_smoking_status_id'];
            if (MasterSmokingStatus::query()->where('id', $sid)->where('is_active', true)->exists()) {
                $smokingStatusId = $sid;
            }
        }
        if ($policy !== null && $policy['fixed_drinking_status_id']) {
            $did = (int) $policy['fixed_drinking_status_id'];
            if (MasterDrinkingStatus::query()->where('id', $did)->where('is_active', true)->exists()) {
                $drinkingStatusId = $did;
            }
        }

        $motherTongueId = self::pickMotherTongueIdForReligion($religion);

        [$workingWithTypeId, $professionId] = self::pickWorkingWithAndProfessionIds();
        if ($isNonWorking) {
            $occupationTitle = null;
            $workingWithTypeId = null;
            $professionId = null;
        }

        $companies = ['IT Company', 'Bank', 'School', 'Hospital', 'Private Ltd', 'Self Employed', 'MNC', 'State Govt'];
        $fatherNames = ['Ramesh', 'Suresh', 'Rajesh', 'Mahesh', 'Vijay', 'Sunil', 'Prakash', 'Anil', 'Dilip', 'Sanjay'];
        $motherNames = ['Sunita', 'Lata', 'Kavita', 'Anita', 'Meera', 'Poonam', 'Seema', 'Rekha', 'Vandana', 'Priya'];

        $birthTime = sprintf('%02d:%02d', random_int(6, 22), random_int(0, 59));

        $annualIncome = (string) (random_int(3, 50) * 100000);
        $familyIncome = (string) (random_int(5, 80) * 100000);

        $spectaclesLens = ['no', 'spectacles', 'contact_lens', 'both'][array_rand(['no', 'spectacles', 'contact_lens', 'both'])];
        if ($policy !== null && $policy['fixed_spectacles_lens'] !== '') {
            $spectaclesLens = $policy['fixed_spectacles_lens'];
        }
        $physicalCondition = ['none', 'prefer_not_to_say'][array_rand(['none', 'prefer_not_to_say'])];

        $attrs = array_merge($loc ?? [], [
            'full_name' => $fullName,
            'gender_id' => $genderId,
            'date_of_birth' => $dob,
            'birth_time' => $birthTime,
            'marital_status_id' => $maritalId,
            'has_children' => false,
            'religion_id' => $religionId,
            'caste_id' => $casteId,
            'sub_caste_id' => $subCasteId,
            'mother_tongue_id' => $motherTongueId,
            'highest_education' => $highestEducation,
            'height_cm' => $heightCm,
            'weight_kg' => $weightKg,
            'profile_photo' => $profilePhoto,
            'complexion_id' => $complexionId,
            'physical_build_id' => $physicalBuildId,
            'blood_group_id' => $bloodGroupId,
            'spectacles_lens' => $spectaclesLens,
            'physical_condition' => $physicalCondition,
            'family_type_id' => $familyTypeId,
            'income_currency_id' => $incomeCurrencyId,
            'diet_id' => $dietId,
            'smoking_status_id' => $smokingStatusId,
            'drinking_status_id' => $drinkingStatusId,
            'is_suspended' => false,
            'photo_approved' => true,
            'is_showcase' => true,
            'specialization' => ['Commerce', 'Computer Science', 'Arts', 'Science', 'Management'][array_rand(['Commerce', 'Computer Science', 'Arts', 'Science', 'Management'])],
            'occupation_title' => $occupationTitle,
            'working_with_type_id' => $workingWithTypeId,
            'profession_id' => $professionId,
            'company_name' => $isNonWorking ? null : $companies[array_rand($companies)],
            'annual_income' => $isNonWorking ? null : $annualIncome,
            'family_income' => $familyIncome,
            'father_name' => $fatherNames[array_rand($fatherNames)].' '.explode(' ', $fullName)[0],
            'father_occupation' => ['Retired', 'Business', 'Government', 'Private Job'][array_rand(['Retired', 'Business', 'Government', 'Private Job'])],
            'mother_name' => $motherNames[array_rand($motherNames)].' '.explode(' ', $fullName)[0],
            'mother_occupation' => ['Homemaker', 'Teacher', 'Retired', 'Private Job'][array_rand(['Homemaker', 'Teacher', 'Retired', 'Private Job'])],
        ]);

        if ($policy !== null) {
            self::applyBulkPolicyRandomFill($attrs, $policy);
            self::applyBulkPolicyNeverFill($attrs, $policy);
        }

        return $attrs;
    }

    private static function randomDobForAgeRange(int $ageMin, int $ageMax): string
    {
        if ($ageMin > $ageMax) {
            [$ageMin, $ageMax] = [$ageMax, $ageMin];
        }
        $age = random_int($ageMin, $ageMax);

        return now()->subYears($age)->subDays(random_int(0, 364))->format('Y-m-d');
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $policy
     */
    private static function applyBulkPolicyRandomFill(array &$attrs, array $policy): void
    {
        foreach ($policy['random_fill_keys'] as $key) {
            if ($key === 'blood_group_id') {
                $attrs['blood_group_id'] = MasterBloodGroup::where('is_active', true)->inRandomOrder()->value('id');
            } elseif ($key === 'complexion_id') {
                $attrs['complexion_id'] = MasterComplexion::where('is_active', true)->inRandomOrder()->value('id');
            } elseif ($key === 'physical_build_id') {
                $attrs['physical_build_id'] = MasterPhysicalBuild::where('is_active', true)->inRandomOrder()->value('id');
            } elseif ($key === 'weight_kg') {
                $attrs['weight_kg'] = random_int(50, 85);
            } elseif ($key === 'family_type_id') {
                $attrs['family_type_id'] = MasterFamilyType::where('is_active', true)->inRandomOrder()->value('id');
            } elseif ($key === 'income_currency_id') {
                $attrs['income_currency_id'] = MasterIncomeCurrency::where('is_active', true)->inRandomOrder()->value('id');
            } elseif ($key === 'mother_tongue_id') {
                $attrs['mother_tongue_id'] = MasterMotherTongue::where('is_active', true)->inRandomOrder()->value('id');
            } elseif ($key === 'birth_time') {
                $attrs['birth_time'] = sprintf('%02d:%02d', random_int(6, 22), random_int(0, 59));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $policy
     */
    private static function applyBulkPolicyNeverFill(array &$attrs, array $policy): void
    {
        foreach ($policy['never_fill_keys'] as $key) {
            if ($key === 'about_me' || $key === 'expectations') {
                continue;
            }
            if (array_key_exists($key, $attrs)) {
                $attrs[$key] = null;
            }
        }
    }

    /**
     * Showcase constraint: allow only Hindu / Buddhist / Muslim religions.
     */
    private static function pickAllowedShowcaseReligion(): ?Religion
    {
        // Prefer canonical keys first.
        $religion = Religion::query()
            ->where('is_active', true)
            ->whereIn('key', ['hindu', 'buddhist', 'buddhism', 'muslim', 'islam'])
            ->inRandomOrder()
            ->first();

        if ($religion) {
            return $religion;
        }

        // Fallback on labels if keys differ across environments.
        return Religion::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereRaw('LOWER(COALESCE(`label`, "")) like ?', ['%hindu%'])
                    ->orWhereRaw('LOWER(COALESCE(`label`, "")) like ?', ['%buddh%'])
                    ->orWhereRaw('LOWER(COALESCE(`label`, "")) like ?', ['%muslim%']);
            })
            ->inRandomOrder()
            ->first();
    }

    /**
     * Showcase "step 6" + "step 7" parity:
     * - extended_narrative (about me + expectations)
     * - preferences (partner preference criteria + pivots via MutationService)
     *
     * @return array{extended_narrative: list<array<string, mixed>>, preferences: array<string, mixed>}
     */
    /**
     * @param  array<string, mixed>|null  $bulkPolicy  normalized admin bulk policy; null = legacy behaviour
     */
    public static function postCreateSnapshotForShowcaseProfile(MatrimonyProfile $profile, ?array $bulkPolicy = null): array
    {
        $policy = $bulkPolicy !== null ? ShowcaseBulkCreateSettings::normalize($bulkPolicy) : null;

        $age = null;
        if ($profile->date_of_birth) {
            try {
                $dob = $profile->date_of_birth instanceof \Carbon\CarbonInterface
                    ? $profile->date_of_birth
                    : \Carbon\Carbon::parse((string) $profile->date_of_birth);
                $age = $dob->age;
            } catch (\Throwable $e) {
                $age = null;
            }
        }

        $prefAgeMin = $age !== null ? max(18, (int) $age - 3) : null;
        $prefAgeMax = $age !== null ? min(80, (int) $age + 5) : null;

        $height = is_numeric($profile->height_cm) ? (int) $profile->height_cm : null;
        $prefHeightMin = $height !== null ? max(1, $height - 10) : null;
        $prefHeightMax = $height !== null ? ($height + 10) : null;

        $aboutText = '';
        $expectText = 'Looking for a respectful, family-oriented match. Prefer honest and calm communication.';

        if ($policy !== null && $policy['about_me_templates'] !== []) {
            $aboutText = $policy['about_me_templates'][array_rand($policy['about_me_templates'])];
        }
        if ($policy !== null && $policy['expectations_templates'] !== []) {
            $expectText = $policy['expectations_templates'][array_rand($policy['expectations_templates'])];
        }

        if ($aboutText === '') {
            $about = [];
            $occ = trim((string) ($profile->occupation_title ?? ''));
            $edu = trim((string) ($profile->highest_education ?? ''));
            if ($occ !== '') {
                $about[] = "Working as {$occ}.";
            }
            if ($edu !== '') {
                $about[] = "Education: {$edu}.";
            }
            if ($about === []) {
                $about[] = 'I value family and clear communication.';
            }
            $aboutText = trim(implode(' ', $about));
        }

        if ($policy !== null && in_array('about_me', $policy['never_fill_keys'], true)) {
            $aboutText = '';
        }
        if ($policy !== null && in_array('expectations', $policy['never_fill_keys'], true)) {
            $expectText = '';
        }

        $dietId = $profile->diet_id ? (int) $profile->diet_id : null;
        $religionId = $profile->religion_id ? (int) $profile->religion_id : null;
        $workingWithTypeId = $profile->working_with_type_id ? (int) $profile->working_with_type_id : null;
        $professionId = $profile->profession_id ? (int) $profile->profession_id : null;

        return [
            'extended_narrative' => [[
                'id' => null,
                'narrative_about_me' => $aboutText,
                'narrative_expectations' => $expectText,
                'additional_notes' => null,
            ]],
            'preferences' => [
                'preferred_age_min' => $prefAgeMin,
                'preferred_age_max' => $prefAgeMax,
                'preferred_height_min_cm' => $prefHeightMin,
                'preferred_height_max_cm' => $prefHeightMax,
                'preferred_income_min' => null,
                'preferred_income_max' => null,
                'preferred_education' => null,
                'preferred_city_id' => null,
                'willing_to_relocate' => null,
                'marriage_type_preference_id' => null,
                'preferred_marital_status_id' => null,
                'preferred_marital_status_ids' => [],
                'partner_profile_with_children' => 'no',
                'preferred_profile_managed_by' => 'self',
                'preferred_religion_ids' => $religionId ? [$religionId] : [],
                'preferred_caste_ids' => [],
                'preferred_country_ids' => [],
                'preferred_state_ids' => [],
                'preferred_district_ids' => [],
                'preferred_taluka_ids' => [],
                'preferred_master_education_ids' => [],
                'preferred_working_with_type_ids' => $workingWithTypeId ? [$workingWithTypeId] : [],
                'preferred_profession_ids' => $professionId ? [$professionId] : [],
                'preferred_diet_ids' => $dietId ? [$dietId] : [],
            ],
        ];
    }

    private static function generateFullNameForReligion(string $gender, ?Religion $religion, int $index = 0): string
    {
        $g = $gender === 'female' ? 'female' : 'male';
        $key = strtolower(trim((string) ($religion?->key ?? '')));

        // Keep names respectful, non-controversial, and consistent with religion where possible.
        // Falls back to a neutral pan-Indian pool when religion key is unknown.
        $pools = [
            'jain' => [
                'male' => ['Amit', 'Sanket', 'Nirav', 'Kunal', 'Rakesh', 'Nikhil', 'Jay', 'Harsh'],
                'female' => ['Riya', 'Jinal', 'Palak', 'Nidhi', 'Khushi', 'Aanchal', 'Neha', 'Komal'],
            ],
            'muslim' => [
                'male' => ['Ayaan', 'Arman', 'Faisal', 'Imran', 'Naved', 'Salman', 'Zaid', 'Irfan'],
                'female' => ['Aisha', 'Sara', 'Zara', 'Noor', 'Hiba', 'Sana', 'Alia', 'Mehak'],
            ],
            'christian' => [
                'male' => ['Brian', 'Kevin', 'Jason', 'Mark', 'Ryan', 'Andrew', 'Joseph', 'Daniel'],
                'female' => ['Maria', 'Angel', 'Anna', 'Rita', 'Sophia', 'Eliza', 'Daisy', 'Grace'],
            ],
            'sikh' => [
                'male' => ['Gurpreet', 'Harpreet', 'Jaspreet', 'Manpreet', 'Sukhdeep', 'Amrit', 'Navdeep', 'Harman'],
                'female' => ['Gurleen', 'Harleen', 'Jasleen', 'Manpreet', 'Sukhpreet', 'Amrit', 'Navleen', 'Harmanpreet'],
            ],
            'hindu' => [
                'male' => ['Aditya', 'Rahul', 'Rohan', 'Vikram', 'Karan', 'Siddharth', 'Dev', 'Arjun'],
                'female' => ['Priya', 'Ananya', 'Kavya', 'Meera', 'Shreya', 'Aditi', 'Divya', 'Sneha'],
            ],
            'buddhist' => [
                'male' => ['Rahul', 'Sagar', 'Akash', 'Nilesh', 'Prashant', 'Amol', 'Vivek', 'Swapnil'],
                'female' => ['Anita', 'Kavita', 'Pradnya', 'Komal', 'Rekha', 'Seema', 'Vandana', 'Poonam'],
            ],
        ];

        $neutral = [
            'male' => ['Aditya', 'Rahul', 'Rohan', 'Sagar', 'Nikhil', 'Amit', 'Kunal', 'Vivek'],
            'female' => ['Priya', 'Ananya', 'Riya', 'Neha', 'Kavya', 'Aditi', 'Sneha', 'Komal'],
        ];

        $pool = $pools[$key][$g] ?? $neutral[$g];
        $first = $pool[array_rand($pool)];

        // Add a light last-name-style suffix to look like a full name without implying religion/caste.
        $last = ['Patil', 'Sharma', 'Jadhav', 'Kulkarni', 'Deshmukh', 'Gupta', 'Rao', 'Singh'][array_rand(['Patil', 'Sharma', 'Jadhav', 'Kulkarni', 'Deshmukh', 'Gupta', 'Rao', 'Singh'])];
        $full = trim($first.' '.$last);

        if ($full === '' || mb_strlen($full) > 255) {
            return 'Showcase Profile '.($index + 1);
        }

        return $full;
    }

    /**
     * @param  list<string>  $educationOptions
     * @return array{0: string, 1: string}
     */
    private static function pickEducationAndOccupation(array $educationOptions): array
    {
        $education = $educationOptions[array_rand($educationOptions)];
        $e = strtolower(trim($education));

        $isSchoolLevel = in_array($e, ['below ssc', 'ssc', 'hsc'], true)
            || str_contains($e, 'below')
            || str_contains($e, 'ssc')
            || str_contains($e, 'hsc');

        // Default pool (safe, common roles).
        $base = ['Accountant', 'Teacher', 'Business', 'Bank Officer', 'Government Employee', 'Private Job', 'Consultant', 'Software Engineer'];

        // Restrict for school-level education (avoid "Doctor", avoid overly specialized roles).
        if ($isSchoolLevel) {
            $allowed = ['Business', 'Private Job', 'Government Employee', 'Consultant', 'Accountant'];

            return [$education, $allowed[array_rand($allowed)]];
        }

        // For higher education, allow doctor sometimes, but not always.
        $allowed = array_merge($base, ['Doctor']);
        $occupation = $allowed[array_rand($allowed)];

        return [$education, $occupation];
    }

    private static function pickDietIdForReligion(?Religion $religion): ?int
    {
        $key = strtolower(trim((string) ($religion?->key ?? '')));
        $active = MasterDiet::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'key', 'label']);
        if ($active->isEmpty()) {
            return null;
        }

        // Guardrail: Jain → vegetarian only (avoid non-veg by construction).
        if ($key === 'jain') {
            $vegKeys = ['veg', 'vegetarian', 'pure_veg'];
            foreach ($active as $row) {
                if (in_array(strtolower((string) $row->key), $vegKeys, true)) {
                    return (int) $row->id;
                }
            }
            foreach ($active as $row) {
                $label = strtolower((string) $row->label);
                if (str_contains($label, 'veg') || str_contains($label, 'vegetarian')) {
                    return (int) $row->id;
                }
            }

            return null;
        }

        // Otherwise pick any active (keeps variety).
        return (int) $active->random()->id;
    }

    private static function pickMotherTongueIdForReligion(?Religion $religion): ?int
    {
        $key = strtolower(trim((string) ($religion?->key ?? '')));
        $active = MasterMotherTongue::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'key', 'label']);
        if ($active->isEmpty()) {
            return null;
        }

        // Light preference mapping (best-effort). Falls back to random active.
        $preferredKeys = match ($key) {
            'jain' => ['gujarati', 'marathi', 'hindi'],
            'hindu' => ['marathi', 'hindi', 'gujarati'],
            'muslim' => ['urdu', 'hindi', 'marathi'],
            'sikh' => ['punjabi', 'hindi'],
            'christian' => ['english', 'hindi', 'marathi'],
            default => [],
        };

        foreach ($preferredKeys as $pk) {
            foreach ($active as $row) {
                if (strtolower((string) $row->key) === $pk) {
                    return (int) $row->id;
                }
            }
        }

        return (int) $active->random()->id;
    }

    /**
     * @return array{0: int|null, 1: int|null} working_with_type_id, profession_id
     */
    private static function pickWorkingWithAndProfessionIds(): array
    {
        $prof = Profession::query()->where('is_active', true)->inRandomOrder()->first();
        if ($prof) {
            return [$prof->working_with_type_id ? (int) $prof->working_with_type_id : null, (int) $prof->id];
        }

        $ww = WorkingWithType::query()->where('is_active', true)->inRandomOrder()->first();

        return [$ww?->id ? (int) $ww->id : null, null];
    }

    /**
     * Location rule-set for showcase profiles:
     * - pick only districts that have at least one real (non-showcase) profile
     * - set residence in the district "town" using cities table (not villages)
     *
     * Returns hierarchy keys for MatrimonyProfile core columns:
     * country_id/state_id/district_id/taluka_id/city_id + work_state_id/work_city_id (aligned)
     *
     * @return array{country_id:int|null,state_id:int|null,district_id:int|null,taluka_id:int|null,city_id:int|null,work_state_id:int|null,work_city_id:int|null}|null
     */
    /**
     * @return list<int>
     */
    private static function eligibleNonShowcaseDistrictIds(): array
    {
        return MatrimonyProfile::query()
            ->whereNonShowcase()
            ->whereNotNull('district_id')
            ->pluck('district_id')
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $eligibleDistrictIds
     * @return array{country_id:int|null,state_id:int|null,district_id:int|null,taluka_id:int|null,city_id:int|null,work_state_id:int|null,work_city_id:int|null}|null
     */
    private static function pickShowcaseHierarchyFromDistrictPool(array $eligibleDistrictIds): ?array
    {
        if ($eligibleDistrictIds === []) {
            return null;
        }

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $districtId = $eligibleDistrictIds[array_rand($eligibleDistrictIds)];
            $district = District::query()->find($districtId);
            if (! $district) {
                continue;
            }

            $stateId = $district->state_id ? (int) $district->state_id : null;
            $countryId = null;
            if ($stateId) {
                $countryId = (int) (State::query()->where('id', $stateId)->value('country_id') ?? 0) ?: null;
            }

            $districtName = strtolower(trim((string) ($district->name ?? '')));

            $candidate = DB::table('cities')
                ->join('talukas', 'cities.taluka_id', '=', 'talukas.id')
                ->where('talukas.district_id', $districtId)
                ->whereNotNull('cities.name')
                ->select('cities.id as city_id', 'cities.name as city_name', 'cities.taluka_id as taluka_id')
                ->get();

            if ($candidate->isEmpty()) {
                continue;
            }

            $picked = null;
            foreach ($candidate as $row) {
                $name = strtolower(trim((string) ($row->city_name ?? '')));
                if ($name !== '' && $districtName !== '' && $name === $districtName) {
                    $picked = $row;
                    break;
                }
            }

            if ($picked === null) {
                continue;
            }

            $talukaId = isset($picked->taluka_id) ? (int) $picked->taluka_id : null;
            $cityId = isset($picked->city_id) ? (int) $picked->city_id : null;
            if (! $cityId || ! $talukaId) {
                continue;
            }

            return [
                'country_id' => $countryId,
                'state_id' => $stateId,
                'district_id' => (int) $districtId,
                'taluka_id' => $talukaId,
                'city_id' => $cityId,
                'work_state_id' => $stateId,
                'work_city_id' => $cityId,
            ];
        }

        return null;
    }

    private static function locationHierarchyForShowcaseFromRealUsers(): ?array
    {
        static $eligibleDistrictIds = null;
        if ($eligibleDistrictIds === null) {
            $eligibleDistrictIds = self::eligibleNonShowcaseDistrictIds();
        }

        return self::pickShowcaseHierarchyFromDistrictPool($eligibleDistrictIds);
    }

    /**
     * @param  array<string, mixed>  $policy  normalized {@see ShowcaseBulkCreateSettings::normalize}
     */
    private static function locationHierarchyForShowcaseFromRealUsersPolicy(array $policy): ?array
    {
        static $fullPool = null;
        if ($fullPool === null) {
            $fullPool = self::eligibleNonShowcaseDistrictIds();
        }

        $ids = $fullPool;
        if ($policy['district_ids'] !== []) {
            $allow = array_flip($policy['district_ids']);
            $ids = array_values(array_filter($ids, fn (int $id) => isset($allow[$id])));
        }
        if ($policy['state_ids'] !== []) {
            $inStates = District::query()
                ->whereIn('state_id', $policy['state_ids'])
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();
            $inSet = array_flip($inStates);
            $ids = array_values(array_filter($ids, fn (int $id) => isset($inSet[$id])));
        }
        if ($policy['country_ids'] !== []) {
            $stateIds = State::query()
                ->whereIn('country_id', $policy['country_ids'])
                ->pluck('id')
                ->all();
            if ($stateIds === []) {
                return null;
            }
            $inCountries = District::query()
                ->whereIn('state_id', $stateIds)
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();
            $inSet = array_flip($inCountries);
            $ids = array_values(array_filter($ids, fn (int $id) => isset($inSet[$id])));
        }

        return self::pickShowcaseHierarchyFromDistrictPool($ids);
    }

    private static function pickSmokingStatusIdForReligion(?Religion $religion): ?int
    {
        $key = strtolower(trim((string) ($religion?->key ?? '')));
        $active = MasterSmokingStatus::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'key', 'label']);
        if ($active->isEmpty()) {
            return null;
        }

        // Jain → never/none
        if ($key === 'jain') {
            $preferredKeys = ['no', 'never', 'non_smoker', 'does_not_smoke'];
            foreach ($active as $row) {
                if (in_array(strtolower((string) $row->key), $preferredKeys, true)) {
                    return (int) $row->id;
                }
            }
            foreach ($active as $row) {
                $label = strtolower((string) $row->label);
                if (str_contains($label, 'no') || str_contains($label, 'never') || str_contains($label, 'non')) {
                    return (int) $row->id;
                }
            }

            return (int) $active->first()->id;
        }

        return (int) $active->random()->id;
    }

    private static function pickDrinkingStatusIdForReligion(?Religion $religion): ?int
    {
        $key = strtolower(trim((string) ($religion?->key ?? '')));
        $active = MasterDrinkingStatus::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'key', 'label']);
        if ($active->isEmpty()) {
            return null;
        }

        // Jain → never/none
        if ($key === 'jain') {
            $preferredKeys = ['no', 'never', 'non_drinker', 'does_not_drink'];
            foreach ($active as $row) {
                if (in_array(strtolower((string) $row->key), $preferredKeys, true)) {
                    return (int) $row->id;
                }
            }
            foreach ($active as $row) {
                $label = strtolower((string) $row->label);
                if (str_contains($label, 'no') || str_contains($label, 'never') || str_contains($label, 'non')) {
                    return (int) $row->id;
                }
            }

            return (int) $active->first()->id;
        }

        return (int) $active->random()->id;
    }

    /**
     * Generate a realistic Indian mobile number for showcase profile (primary contact).
     */
    public static function randomPrimaryPhone(): string
    {
        $prefixes = ['9', '8', '7'];
        $p = $prefixes[array_rand($prefixes)];
        $rest = '';
        for ($i = 0; $i < 9; $i++) {
            $rest .= (string) random_int(0, 9);
        }

        return $p.$rest;
    }

    /** Age 23–35 for showcase. */
    private static function randomDobShowcase(): string
    {
        $age = random_int(23, 35);

        return now()->subYears($age)->subDays(random_int(0, 364))->format('Y-m-d');
    }

    /** Valid country/state/district/taluka/city IDs; nulls if any level missing. */
    private static function locationHierarchyForShowcase(): array
    {
        $country = Country::query()->inRandomOrder()->first();
        if (! $country) {
            return ['country_id' => null, 'state_id' => null, 'district_id' => null, 'taluka_id' => null, 'city_id' => null];
        }
        $state = State::where('country_id', $country->id)->inRandomOrder()->first();
        if (! $state) {
            return ['country_id' => $country->id, 'state_id' => null, 'district_id' => null, 'taluka_id' => null, 'city_id' => null];
        }
        $district = District::where('state_id', $state->id)->inRandomOrder()->first();
        if (! $district) {
            return ['country_id' => $country->id, 'state_id' => $state->id, 'district_id' => null, 'taluka_id' => null, 'city_id' => null];
        }
        $taluka = Taluka::where('district_id', $district->id)->inRandomOrder()->first();
        if (! $taluka) {
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
     * Phase-4: Generate dummy extended field values for showcase profile.
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
     * Generate full_name for showcase profile based on gender and caste.
     * Falls back to "Showcase Profile {index+1}" if caste is missing or not mapped.
     *
     * @param  string  $gender  male|female
     * @param  string|null  $caste  Caste value or null
     * @param  int  $index  0-based index for fallback naming
     * @return string Generated full name
     */
    public static function generateFullName(string $gender, ?string $caste, int $index = 0): string
    {
        // Validate gender
        if (! in_array($gender, self::GENDERS, true)) {
            return 'Showcase Profile '.($index + 1);
        }

        // If caste is missing or empty, use fallback
        if (empty($caste) || ! is_string($caste)) {
            return 'Showcase Profile '.($index + 1);
        }

        // Get name pools
        $namePools = self::getNamePools();

        // Check if caste is mapped
        if (! isset($namePools[$caste]) || ! isset($namePools[$caste][$gender])) {
            return 'Showcase Profile '.($index + 1);
        }

        // Get names for this caste and gender
        $names = $namePools[$caste][$gender];

        // If pool is empty, use fallback
        if (empty($names) || ! is_array($names)) {
            return 'Showcase Profile '.($index + 1);
        }

        // Randomly select one name
        $selectedName = $names[array_rand($names)];

        // Ensure name doesn't exceed max length (255 chars)
        if (strlen($selectedName) > 255) {
            return 'Showcase Profile '.($index + 1);
        }

        return $selectedName;
    }

    /**
     * @deprecated Use generateFullName() instead. Kept for backward compatibility.
     */
    public static function fullNameForIndex(int $index): string
    {
        return 'Showcase Profile '.($index + 1);
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
     * Randomly select an unused showcase profile photo from gender-specific engagement folder.
     * Each image is used only once across all showcase profiles. Returns null if no unused images available.
     *
     * @param  string  $gender  male|female
     * @return string|null Full relative path from matrimony_photos (e.g., "engagement/female/f1.jpg"), or null if no unused images found
     */
    private static function randomShowcasePhoto(string $gender): ?string
    {
        if (! in_array($gender, self::GENDERS, true)) {
            return null;
        }

        $folderPath = public_path('uploads/matrimony_photos/engagement/'.$gender);

        if (! is_dir($folderPath) || ! is_readable($folderPath)) {
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

            $filePath = $folderPath.DIRECTORY_SEPARATOR.$entry;

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

        $usedFilenames = self::getUsedShowcasePhotoFilenames($gender);
        $unusedFiles = array_diff($availableFiles, $usedFilenames);

        if (empty($unusedFiles)) {
            return null;
        }

        $selectedFilename = $unusedFiles[array_rand($unusedFiles)];

        return 'engagement/'.$gender.'/'.$selectedFilename;
    }

    /**
     * Get list of filenames (extracted from stored paths) already assigned to showcase profiles of given gender.
     * Handles both old format (filename only) and new format (relative path).
     *
     * @param  string  $gender  male|female
     * @return array Array of filenames only (for comparison with available files)
     */
    private static function getUsedShowcasePhotoFilenames(string $gender): array
    {
        $genderId = MasterGender::where('key', $gender)->where('is_active', true)->value('id');
        if ($genderId === null) {
            return [];
        }

        $usedPhotos = MatrimonyProfile::query()
            ->whereShowcase()
            ->where('gender_id', $genderId)
            ->whereNotNull('profile_photo')
            ->pluck('profile_photo')
            ->toArray();

        $filenames = [];
        foreach ($usedPhotos as $photo) {
            if (empty($photo) || ! is_string($photo)) {
                continue;
            }

            // Extract filename from path (handles both "filename.jpg" and "engagement/gender/filename.jpg")
            $filename = basename($photo);

            // Only include if it's from engagement folder (new format) or just filename (old format)
            // For old format, we need to check if it matches engagement folder structure
            if (strpos($photo, 'engagement/'.$gender.'/') === 0) {
                // New format: extract filename
                $filenames[] = $filename;
            } elseif (strpos($photo, '/') === false) {
                // Old format: filename only, check if it exists in engagement folder
                $engagementPath = public_path('uploads/matrimony_photos/engagement/'.$gender.'/'.$photo);
                if (file_exists($engagementPath)) {
                    $filenames[] = $filename;
                }
            }
        }

        return array_unique($filenames);
    }
}
