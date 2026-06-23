<?php

use App\Models\Block;
use App\Models\Caste;
use App\Models\EducationCategory;
use App\Models\EducationDegree;
use App\Models\HiddenProfile;
use App\Models\Interest;
use App\Models\Location;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\OccupationCategory;
use App\Models\OccupationCustom;
use App\Models\OccupationMaster;
use App\Models\ProfilePhoto;
use App\Models\ProfileView;
use App\Models\Religion;
use App\Models\Shortlist;
use App\Models\SubCaste;
use App\Models\User;
use App\Services\Api\MobileProfileDisplayPresenter;
use App\Services\ContactAccessService;
use App\Services\FeatureUsageService;
use App\Services\Gunamilan\GunamilanService;
use App\Services\MutationService;
use App\Services\ProfilePartnerCommunityFlagService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function mobileApiProfileTestLeafLocation(): Location
{
    $suffix = strtolower(str_replace('.', '-', uniqid('mobile-api-', true)));

    $country = Location::create([
        'name' => 'India '.$suffix,
        'slug' => 'india-'.$suffix,
        'hierarchy' => 'country',
        'is_active' => true,
    ]);
    $state = Location::create([
        'name' => 'Maharashtra '.$suffix,
        'slug' => 'maharashtra-'.$suffix,
        'hierarchy' => 'state',
        'parent_id' => $country->id,
        'is_active' => true,
    ]);
    $district = Location::create([
        'name' => 'Pune '.$suffix,
        'slug' => 'pune-'.$suffix,
        'hierarchy' => 'district',
        'parent_id' => $state->id,
        'is_active' => true,
    ]);
    $taluka = Location::create([
        'name' => 'Haveli '.$suffix,
        'slug' => 'haveli-'.$suffix,
        'hierarchy' => 'taluka',
        'parent_id' => $district->id,
        'is_active' => true,
    ]);

    return Location::create([
        'name' => 'Wakad '.$suffix,
        'slug' => 'wakad-'.$suffix,
        'hierarchy' => 'village',
        'tag' => 'city',
        'parent_id' => $taluka->id,
        'is_active' => true,
    ]);
}

function mobileApiProfileTestLocationNode(
    string $hierarchy,
    string $name,
    ?Location $parent = null,
    array $extra = []
): Location {
    $suffix = strtolower(str_replace('.', '-', uniqid('mobile-api-location-', true)));
    $data = [
        'name' => $name.' '.$suffix,
        'slug' => strtolower(str_replace(' ', '-', $name)).'-'.$suffix,
        'hierarchy' => $hierarchy,
        'parent_id' => $parent?->id,
        'is_active' => true,
    ];
    if ($hierarchy === 'village') {
        $data['tag'] = 'city';
    }
    foreach (['lat', 'lng', 'pincode'] as $key) {
        if (array_key_exists($key, $extra)) {
            $data[$key] = $extra[$key];
        }
    }

    return Location::create($data);
}

function mobileApiProfileTestLocationChain(
    ?Location $country = null,
    ?Location $state = null,
    ?Location $district = null,
    ?Location $taluka = null,
    array $districtExtra = [],
    array $talukaExtra = [],
    array $leafExtra = []
): array {
    $country ??= mobileApiProfileTestLocationNode('country', 'India');
    $state ??= mobileApiProfileTestLocationNode('state', 'Maharashtra', $country);
    $district ??= mobileApiProfileTestLocationNode('district', 'Pune', $state, $districtExtra);
    $taluka ??= mobileApiProfileTestLocationNode('taluka', 'Haveli', $district, $talukaExtra);
    $leaf = mobileApiProfileTestLocationNode('village', 'Wakad', $taluka, $leafExtra);

    return [
        'country' => $country,
        'state' => $state,
        'district' => $district,
        'taluka' => $taluka,
        'leaf' => $leaf,
    ];
}

function mobileApiProfileTestCaste(): Caste
{
    $suffix = strtolower(str_replace('.', '-', uniqid('mobile-api-', true)));
    $religionData = [
        'key' => 'hindu-'.$suffix,
        'label' => 'Hindu '.$suffix,
        'is_active' => true,
    ];
    if (Schema::hasColumn('master_religions', 'label_en')) {
        $religionData['label_en'] = 'Hindu';
    }
    if (Schema::hasColumn('master_religions', 'label_mr')) {
        $religionData['label_mr'] = 'Hindu';
    }
    $religion = Religion::create($religionData);

    $casteData = [
        'religion_id' => $religion->id,
        'key' => 'maratha-'.$suffix,
        'label' => 'Maratha',
        'is_active' => true,
    ];
    if (Schema::hasColumn('master_castes', 'label_en')) {
        $casteData['label_en'] = 'Maratha';
    }
    if (Schema::hasColumn('master_castes', 'label_mr')) {
        $casteData['label_mr'] = 'Maratha';
    }

    return Caste::create($casteData);
}

function mobileApiProfileTestCommunity(): array
{
    $suffix = strtolower(str_replace('.', '-', uniqid('mobile-api-community-', true)));
    $religionData = [
        'key' => 'hindu-'.$suffix,
        'label' => 'Hindu '.$suffix,
        'is_active' => true,
    ];
    if (Schema::hasColumn('master_religions', 'label_en')) {
        $religionData['label_en'] = 'Hindu '.$suffix;
    }
    if (Schema::hasColumn('master_religions', 'label_mr')) {
        $religionData['label_mr'] = 'हिंदू';
    }
    $religion = Religion::create($religionData);

    $casteData = [
        'religion_id' => $religion->id,
        'key' => 'maratha-'.$suffix,
        'label' => 'Maratha '.$suffix,
        'is_active' => true,
    ];
    if (Schema::hasColumn('master_castes', 'label_en')) {
        $casteData['label_en'] = 'Maratha '.$suffix;
    }
    if (Schema::hasColumn('master_castes', 'label_mr')) {
        $casteData['label_mr'] = 'मराठा';
    }
    $caste = Caste::create($casteData);

    $subCasteData = [
        'caste_id' => $caste->id,
        'key' => 'deshmukh-'.$suffix,
        'label' => 'Deshmukh '.$suffix,
        'is_active' => true,
    ];
    if (Schema::hasColumn('master_sub_castes', 'label_en')) {
        $subCasteData['label_en'] = 'Deshmukh '.$suffix;
    }
    if (Schema::hasColumn('master_sub_castes', 'label_mr')) {
        $subCasteData['label_mr'] = 'देशमुख';
    }
    if (Schema::hasColumn('master_sub_castes', 'status')) {
        $subCasteData['status'] = 'approved';
    }
    $subCaste = SubCaste::create($subCasteData);

    return [$religion, $caste, $subCaste];
}

function mobileApiProfileTestGender(string $key): MasterGender
{
    return MasterGender::query()->firstOrCreate(
        ['key' => $key],
        [
            'label' => ucfirst($key),
            'is_active' => true,
        ]
    );
}

function mobileApiProfileTestMasterOption(string $table, string $keyPrefix, string $labelPrefix, array $overrides = []): int
{
    $suffix = substr(strtolower(str_replace('.', '', uniqid('', true))), -6);
    $maxKeyLength = $table === 'master_blood_groups' ? 8 : 32;
    $base = preg_replace('/[^a-z0-9]/', '', strtolower($keyPrefix)) ?: 'm';
    $key = substr($base, 0, max(1, $maxKeyLength - strlen($suffix))).$suffix;
    $data = [
        'key' => $key,
        'label' => $labelPrefix.' '.$suffix,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ];
    if (Schema::hasColumn($table, 'label_en')) {
        $data['label_en'] = $labelPrefix.' '.$suffix;
    }
    if (Schema::hasColumn($table, 'label_mr')) {
        $data['label_mr'] = 'चाचणी '.$labelPrefix;
    }
    if (Schema::hasColumn($table, 'sort_order')) {
        $data['sort_order'] = 10;
    }
    $data = array_merge($data, $overrides);

    return (int) DB::table($table)->insertGetId($data);
}

function mobileApiProfileTestKnownMaritalStatus(string $key, string $label): int
{
    $data = [
        'label' => $label,
        'is_active' => true,
        'updated_at' => now(),
    ];
    if (Schema::hasColumn('master_marital_statuses', 'label_en')) {
        $data['label_en'] = $label;
    }
    if (Schema::hasColumn('master_marital_statuses', 'label_mr')) {
        $data['label_mr'] = $label;
    }

    DB::table('master_marital_statuses')->updateOrInsert(
        ['key' => $key],
        array_merge($data, ['created_at' => now()])
    );

    return (int) DB::table('master_marital_statuses')->where('key', $key)->value('id');
}

function mobileApiProfileTestKnownMasterOption(string $table, string $key, string $label, array $overrides = []): int
{
    $data = [
        'label' => $label,
        'is_active' => true,
        'updated_at' => now(),
    ];
    if (Schema::hasColumn($table, 'label_en')) {
        $data['label_en'] = $label;
    }
    if (Schema::hasColumn($table, 'label_mr')) {
        $data['label_mr'] = $label;
    }
    if (Schema::hasColumn($table, 'sort_order')) {
        $data['sort_order'] = 10;
    }

    DB::table($table)->updateOrInsert(
        ['key' => $key],
        array_merge($data, ['created_at' => now()], $overrides)
    );

    return (int) DB::table($table)->where('key', $key)->value('id');
}

function mobileApiProfileAssertIdBefore(array $ids, int $firstId, int $secondId): void
{
    $firstIndex = array_search($firstId, $ids, true);
    $secondIndex = array_search($secondId, $ids, true);

    expect($firstIndex)->not->toBeFalse()
        ->and($secondIndex)->not->toBeFalse()
        ->and($firstIndex)->toBeLessThan($secondIndex);
}

function mobileApiProfileTestPhase1AOptions(): array
{
    return [
        'mother_tongue_id' => mobileApiProfileTestMasterOption('master_mother_tongues', 'marathi', 'Marathi'),
        'complexion_id' => mobileApiProfileTestMasterOption('master_complexions', 'fair', 'Fair'),
        'blood_group_id' => mobileApiProfileTestMasterOption('master_blood_groups', 'a-positive', 'A+'),
        'physical_build_id' => mobileApiProfileTestMasterOption('master_physical_builds', 'average', 'Average'),
    ];
}

function mobileApiProfileTestMaritalLifestyleOptions(): array
{
    return [
        'marital_status_id' => mobileApiProfileTestKnownMaritalStatus('never_married', 'Never Married'),
        'diet_id' => mobileApiProfileTestMasterOption('master_diets', 'vegetarian', 'Vegetarian'),
        'smoking_status_id' => mobileApiProfileTestMasterOption('master_smoking_statuses', 'non-smoker', 'Non-smoker'),
        'drinking_status_id' => mobileApiProfileTestMasterOption('master_drinking_statuses', 'non-drinker', 'Non-drinker'),
    ];
}

function mobileApiProfileTestPhase4AOptions(): array
{
    $nakshatraOverrides = Schema::hasColumn('master_nakshatras', 'nakshatra_number')
        ? ['nakshatra_number' => random_int(100, 999)]
        : [];

    return [
        'family_type_id' => mobileApiProfileTestMasterOption('master_family_types', 'joint', 'Joint Family'),
        'rashi_id' => mobileApiProfileTestKnownMasterOption('master_rashis', 'mesha', 'Mesha'),
        'nakshatra_id' => mobileApiProfileTestKnownMasterOption('master_nakshatras', 'ashwini', 'Ashwini', $nakshatraOverrides),
        'gan_id' => mobileApiProfileTestKnownMasterOption('master_gans', 'deva', 'Deva'),
        'nadi_id' => mobileApiProfileTestKnownMasterOption('master_nadis', 'adi', 'Adi Nadi'),
        'yoni_id' => mobileApiProfileTestKnownMasterOption('master_yonis', 'horse', 'Horse'),
        'varna_id' => mobileApiProfileTestKnownMasterOption('master_varnas', 'brahmin', 'Brahmin'),
        'vashya_id' => mobileApiProfileTestKnownMasterOption('master_vashyas', 'manav', 'Manav'),
        'rashi_lord_id' => mobileApiProfileTestKnownMasterOption('master_rashi_lords', 'sun', 'Sun'),
        'mangal_dosh_type_id' => mobileApiProfileTestKnownMasterOption('master_mangal_dosh_types', 'none', 'No Mangal Dosh'),
    ];
}

function mobileApiProfileTestPartnerPreferenceOptions(): array
{
    $educationDegree = mobileApiProfileTestEducationDegree('M.C.A.', 101);
    $occupationMaster = mobileApiProfileTestOccupationMaster('Data Analyst', 101);

    return [
        'marriage_type_preference_id' => mobileApiProfileTestKnownMasterOption('master_marriage_type_preferences', 'arranged', 'Arranged', ['sort_order' => 10]),
        'marital_status_id' => mobileApiProfileTestKnownMaritalStatus('divorced', 'Divorced'),
        'second_marital_status_id' => mobileApiProfileTestKnownMaritalStatus('widowed', 'Widowed'),
        'diet_id' => mobileApiProfileTestMasterOption('master_diets', 'vegetarian', 'Vegetarian', ['sort_order' => 10]),
        'second_diet_id' => mobileApiProfileTestMasterOption('master_diets', 'jain', 'Jain', ['sort_order' => 20]),
        'education_degree_id' => (int) $educationDegree->id,
        'education_degree_label' => $educationDegree->code,
        'occupation_master_id' => (int) $occupationMaster->id,
        'occupation_master_label' => $occupationMaster->name,
    ];
}

function mobileApiProfileTestOccupationMaster(string $name = 'Software Engineer', int $sortOrder = 10): OccupationMaster
{
    $suffix = strtolower(str_replace('.', '-', uniqid('mobile-api-occ-', true)));
    $categoryData = [
        'name' => 'Technology '.$suffix,
        'sort_order' => $sortOrder,
    ];
    if (Schema::hasColumn('master_occupation_categories', 'name_mr')) {
        $categoryData['name_mr'] = 'तंत्रज्ञान';
    }
    if (Schema::hasColumn('master_occupation_categories', 'legacy_working_with_type_id')) {
        $categoryData['legacy_working_with_type_id'] = null;
    }
    $category = OccupationCategory::create($categoryData);

    $occupationData = [
        'name' => $name.' '.$suffix,
        'normalized_name' => mb_strtolower($name.' '.$suffix),
        'category_id' => $category->id,
    ];
    if (Schema::hasColumn('master_occupations', 'name_mr')) {
        $occupationData['name_mr'] = 'सॉफ्टवेअर अभियंता';
    }
    if (Schema::hasColumn('master_occupations', 'sort_order')) {
        $occupationData['sort_order'] = $sortOrder;
    }

    return OccupationMaster::create($occupationData);
}

function mobileApiProfileTestSeedCurrentAddressType(): void
{
    $values = [
        'label' => 'Current',
        'created_at' => now(),
        'updated_at' => now(),
    ];
    if (Schema::hasColumn('master_address_types', 'label_mr')) {
        $values['label_mr'] = 'Current';
    }

    DB::table('master_address_types')->updateOrInsert(
        ['key' => 'current'],
        $values
    );
    \App\Services\Profile\ProfileCanonicalResidenceService::forgetCachedMasters();
}

function mobileApiProfileActionPair(): array
{
    $viewerUser = User::factory()->create(['name' => 'Mobile Action Viewer']);
    $targetUser = User::factory()->create(['name' => 'Mobile Action Target']);
    $viewerProfile = mobileApiCreateValidActionProfile($viewerUser, 'Mobile Action Viewer', 'male');
    $targetProfile = mobileApiCreateValidActionProfile($targetUser, 'Mobile Action Target', 'female');

    return [$viewerUser, $viewerProfile, $targetUser, $targetProfile];
}

function mobileApiCreateValidActionProfile(
    User $user,
    string $name,
    string $genderKey = 'male',
    ?Location $location = null,
    array $coreOverrides = []
): MatrimonyProfile
{
    mobileApiProfileTestSeedCurrentAddressType();
    $location ??= mobileApiProfileTestLeafLocation();
    [$religion, $caste, $subCaste] = mobileApiProfileTestCommunity();
    $gender = mobileApiProfileTestGender($genderKey);

    $profile = app(MutationService::class)->createDraftProfileForUser($user);
    $core = array_merge([
        'full_name' => $name,
        'gender_id' => $gender->id,
        'date_of_birth' => '1995-01-05',
        'highest_education' => 'B.A.',
        'location_id' => $location->id,
        'religion_id' => $religion->id,
        'caste_id' => $caste->id,
        'sub_caste_id' => $subCaste->id,
    ], $coreOverrides);

    app(MutationService::class)->applyManualSnapshot($profile, [
        'core' => $core,
    ], (int) $user->id, 'manual');

    $profile->refresh();
    $profile->lifecycle_state = 'active';
    $profile->is_suspended = false;
    $profile->save();

    return $profile->refresh();
}

function mobileApiAttachProfilePhoto(MatrimonyProfile $profile, string $status = 'approved'): void
{
    ProfilePhoto::query()->create([
        'profile_id' => $profile->id,
        'file_path' => 'matrimony_photos/mobile-feed-'.$profile->id.'-'.$status.'.webp',
        'is_primary' => true,
        'sort_order' => 0,
        'uploaded_via' => 'test',
        'approved_status' => $status,
        'watermark_detected' => false,
    ]);
}

function mobileApiCreateComparisonProfilesAt(Location $viewerLocation, Location $targetLocation): array
{
    $viewerUser = User::factory()->create(['name' => 'Location Rule Viewer']);
    $targetUser = User::factory()->create(['name' => 'Location Rule Target']);
    $viewerProfile = mobileApiCreateValidActionProfile($viewerUser, 'Location Rule Viewer', 'male', $viewerLocation);
    $targetProfile = mobileApiCreateValidActionProfile($targetUser, 'Location Rule Target', 'female', $targetLocation);

    return [$viewerUser, $viewerProfile, $targetUser, $targetProfile];
}

function mobileApiLocationComparisonRow(array $comparison): array
{
    $row = mobileApiComparisonRow($comparison, 'location');
    expect($row)->toBeArray();

    return $row;
}

function mobileApiComparisonRow(array $comparison, string $key): ?array
{
    $row = collect($comparison['rows'] ?? [])->firstWhere('key', $key);

    return is_array($row) ? $row : null;
}

function mobileApiRecursivePayloadKeys(mixed $payload): array
{
    if (! is_array($payload)) {
        return [];
    }

    $keys = [];
    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        $keys = array_merge($keys, mobileApiRecursivePayloadKeys($value));
    }

    return array_values(array_unique($keys));
}

function mobileApiProfileTestEducationDegree(string $code, int $sortOrder): EducationDegree
{
    $suffix = strtolower(str_replace('.', '-', uniqid('mobile-api-edu-', true)));
    $category = EducationCategory::create([
        'name' => 'Comparison Education '.$suffix,
        'slug' => 'comparison-education-'.$suffix,
        'sort_order' => $sortOrder,
        'is_active' => true,
    ]);

    $data = [
        'category_id' => $category->id,
        'code' => $code.' '.$suffix,
        'full_form' => $code.' comparison degree '.$suffix,
        'sort_order' => $sortOrder,
    ];
    if (Schema::hasColumn('master_education', 'code_mr')) {
        $data['code_mr'] = null;
    }

    return EducationDegree::create($data);
}

function mobileApiAddTargetPartnerPreferences(MatrimonyProfile $targetProfile, MatrimonyProfile $viewerProfile): void
{
    DB::table('profile_preference_criteria')->updateOrInsert(
        ['profile_id' => $targetProfile->id],
        [
            'preferred_age_min' => 28,
            'preferred_age_max' => 35,
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );

    DB::table('profile_preferred_religions')->insert([
        'profile_id' => $targetProfile->id,
        'religion_id' => $viewerProfile->religion_id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('profile_preferred_castes')->insert([
        'profile_id' => $targetProfile->id,
        'caste_id' => $viewerProfile->caste_id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('MobileProfile GET api v1 religions returns active religions for authenticated user', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $activeData = [
        'key' => 'active-mobile-religion',
        'label' => 'Active Mobile Religion',
        'is_active' => true,
    ];
    $inactiveData = [
        'key' => 'inactive-mobile-religion',
        'label' => 'Inactive Mobile Religion',
        'is_active' => false,
    ];
    if (Schema::hasColumn('master_religions', 'label_en')) {
        $activeData['label_en'] = 'Active Mobile Religion';
        $inactiveData['label_en'] = 'Inactive Mobile Religion';
    }
    if (Schema::hasColumn('master_religions', 'label_mr')) {
        $activeData['label_mr'] = 'सक्रिय';
        $inactiveData['label_mr'] = 'निष्क्रिय';
    }
    $active = Religion::create($activeData);
    $inactive = Religion::create($inactiveData);

    $response = $this->getJson('/api/v1/religions');

    $response
        ->assertOk()
        ->assertJsonFragment([
            'id' => $active->id,
            'label' => 'Active Mobile Religion',
            'label_en' => 'Active Mobile Religion',
        ])
        ->assertJsonMissing([
            'id' => $inactive->id,
            'label' => 'Inactive Mobile Religion',
        ]);
});

test('MobileProfile GET api v1 genders returns active governed gender options', function () {
    $male = MasterGender::query()->updateOrCreate(
        ['key' => 'male'],
        ['label' => 'Male', 'is_active' => true]
    );
    $female = MasterGender::query()->updateOrCreate(
        ['key' => 'female'],
        ['label' => 'Female', 'is_active' => true]
    );
    $inactive = MasterGender::query()->updateOrCreate(
        ['key' => 'inactive-mobile-gender'],
        ['label' => 'Inactive Mobile Gender', 'is_active' => false]
    );

    if (Schema::hasColumn('master_genders', 'label_mr')) {
        $male->forceFill(['label_mr' => 'वर'])->save();
        $female->forceFill(['label_mr' => 'वधू'])->save();
    }

    $response = $this->getJson('/api/v1/genders');

    $response->assertOk();

    $payload = $response->json();
    expect($payload[0])->toHaveKeys(['id', 'key', 'label', 'label_mr']);
    expect($payload[0]['id'])->toBe($male->id);
    expect($payload[0]['key'])->toBe('male');
    expect($payload[0]['label'])->toBe('Male');
    expect($payload[0]['label_mr'])->toBe('वर');
    expect($payload[1]['id'])->toBe($female->id);
    expect($payload[1]['key'])->toBe('female');
    expect($payload[1]['label_mr'])->toBe('वधू');
    expect(collect($payload)->pluck('id')->all())->not->toContain($inactive->id);
});

test('MobileProfile GET api v1 profile basic physical options returns active governed lookups', function () {
    $user = User::factory()->create(['name' => 'Lookup Account']);
    Sanctum::actingAs($user);
    $options = mobileApiProfileTestPhase1AOptions();
    $motherTongueFirst = mobileApiProfileTestMasterOption('master_mother_tongues', 'zsource', 'Zulu Source First', ['sort_order' => 20]);
    $motherTongueSecond = mobileApiProfileTestMasterOption('master_mother_tongues', 'asource', 'Alpha Source Second', ['sort_order' => 40]);
    $complexionFirst = mobileApiProfileTestMasterOption('master_complexions', 'zsource', 'Zulu Source First');
    $complexionSecond = mobileApiProfileTestMasterOption('master_complexions', 'asource', 'Alpha Source Second');
    $inactiveKey = 'inactive'.substr(strtolower(str_replace('.', '', uniqid('', true))), -8);
    $inactiveId = (int) DB::table('master_complexions')->insertGetId([
        'key' => $inactiveKey,
        'label' => 'Inactive Mobile Complexion',
        'is_active' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/profile/basic-physical-options');

    $response
        ->assertOk()
        ->assertJsonStructure([
            'mother_tongues' => [
                '*' => ['id', 'key', 'label', 'label_en', 'label_mr'],
            ],
            'complexions' => [
                '*' => ['id', 'key', 'label', 'label_en', 'label_mr'],
            ],
            'blood_groups' => [
                '*' => ['id', 'key', 'label', 'label_en', 'label_mr'],
            ],
            'physical_builds' => [
                '*' => ['id', 'key', 'label', 'label_en', 'label_mr'],
            ],
            'spectacles_lens' => [
                '*' => ['key', 'label', 'label_en', 'label_mr'],
            ],
            'physical_conditions' => [
                '*' => ['key', 'label', 'label_en', 'label_mr'],
            ],
        ]);

    expect(collect($response->json('mother_tongues'))->pluck('id')->all())->toContain($options['mother_tongue_id']);
    expect(collect($response->json('complexions'))->pluck('id')->all())->toContain($options['complexion_id']);
    expect(collect($response->json('complexions'))->pluck('id')->all())->not->toContain($inactiveId);
    mobileApiProfileAssertIdBefore(collect($response->json('mother_tongues'))->pluck('id')->all(), $motherTongueFirst, $motherTongueSecond);
    mobileApiProfileAssertIdBefore(collect($response->json('complexions'))->pluck('id')->all(), $complexionFirst, $complexionSecond);
    expect(collect($response->json('spectacles_lens'))->pluck('key')->all())->toContain('contact_lens');
    expect(collect($response->json('physical_conditions'))->pluck('key')->all())->toContain('prefer_not_to_say');
});

test('MobileProfile GET api v1 profile education career options returns governed lookups', function () {
    $user = User::factory()->create(['name' => 'Education Career Lookup Account']);
    Sanctum::actingAs($user);
    $degree = mobileApiProfileTestEducationDegree('M.B.A.', 25);
    $occupation = mobileApiProfileTestOccupationMaster('Product Manager');
    $degreeFirst = mobileApiProfileTestEducationDegree('Z.Source', 101);
    $degreeSecond = mobileApiProfileTestEducationDegree('A.Source', 202);
    $occupationFirst = mobileApiProfileTestOccupationMaster('Zulu Source Occupation', 101);
    $occupationSecond = mobileApiProfileTestOccupationMaster('Alpha Source Occupation', 202);
    $custom = OccupationCustom::create([
        'raw_name' => 'Family Business',
        'normalized_name' => 'family business',
        'user_id' => $user->id,
        'status' => 'pending',
    ]);

    $response = $this->getJson('/api/v1/profile/education-career-options');

    $response
        ->assertOk()
        ->assertJsonStructure([
            'education_degrees' => [
                '*' => ['id', 'code', 'label', 'label_en', 'label_mr', 'full_form', 'category_id', 'category_label', 'category_label_mr'],
            ],
            'occupation_categories' => [
                '*' => ['id', 'label', 'label_en', 'label_mr', 'legacy_working_with_type_id'],
            ],
            'occupations' => [
                '*' => ['id', 'label', 'label_en', 'label_mr', 'category_id', 'category_label', 'category_label_mr'],
            ],
            'custom_occupations' => [
                '*' => ['id', 'label', 'label_en', 'label_mr', 'status'],
            ],
        ]);

    expect(collect($response->json('education_degrees'))->pluck('id')->all())->toContain($degree->id);
    expect(collect($response->json('occupations'))->pluck('id')->all())->toContain($occupation->id);
    mobileApiProfileAssertIdBefore(collect($response->json('education_degrees'))->pluck('id')->all(), $degreeFirst->id, $degreeSecond->id);
    mobileApiProfileAssertIdBefore(collect($response->json('occupations'))->pluck('id')->all(), $occupationFirst->id, $occupationSecond->id);
    expect(collect($response->json('custom_occupations'))->pluck('id')->all())->toContain($custom->id);
});

test('MobileProfile GET api v1 profile marital lifestyle options returns governed lookups', function () {
    $user = User::factory()->create(['name' => 'Marital Lifestyle Lookup Account']);
    Sanctum::actingAs($user);
    $options = mobileApiProfileTestMaritalLifestyleOptions();
    $neverMarriedId = mobileApiProfileTestKnownMaritalStatus('never_married', 'Zulu Never Married');
    $divorcedId = mobileApiProfileTestKnownMaritalStatus('divorced', 'Alpha Divorced');
    $dietFirst = mobileApiProfileTestMasterOption('master_diets', 'zdiet', 'Zulu Source Diet', ['sort_order' => 101]);
    $dietSecond = mobileApiProfileTestMasterOption('master_diets', 'adiet', 'Alpha Source Diet', ['sort_order' => 202]);
    $inactiveId = (int) DB::table('master_diets')->insertGetId([
        'key' => 'inactive'.substr(strtolower(str_replace('.', '', uniqid('', true))), -8),
        'label' => 'Inactive Mobile Diet',
        'is_active' => false,
        'sort_order' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/profile/marital-lifestyle-options');

    $response
        ->assertOk()
        ->assertJsonStructure([
            'marital_statuses' => [
                '*' => ['id', 'key', 'label', 'label_en', 'label_mr'],
            ],
            'diets' => [
                '*' => ['id', 'key', 'label', 'label_en', 'label_mr'],
            ],
            'smoking_statuses' => [
                '*' => ['id', 'key', 'label', 'label_en', 'label_mr'],
            ],
            'drinking_statuses' => [
                '*' => ['id', 'key', 'label', 'label_en', 'label_mr'],
            ],
        ]);

    expect(collect($response->json('marital_statuses'))->pluck('id')->all())->toContain($options['marital_status_id']);
    expect(collect($response->json('diets'))->pluck('id')->all())->toContain($options['diet_id']);
    expect(collect($response->json('diets'))->pluck('id')->all())->not->toContain($inactiveId);
    mobileApiProfileAssertIdBefore(collect($response->json('marital_statuses'))->pluck('id')->all(), $neverMarriedId, $divorcedId);
    mobileApiProfileAssertIdBefore(collect($response->json('diets'))->pluck('id')->all(), $dietFirst, $dietSecond);
    expect(collect($response->json('smoking_statuses'))->pluck('id')->all())->toContain($options['smoking_status_id']);
    expect(collect($response->json('drinking_statuses'))->pluck('id')->all())->toContain($options['drinking_status_id']);
});

test('MobileProfile GET api v1 profile remaining options returns family and horoscope lookups', function () {
    $user = User::factory()->create(['name' => 'Remaining Lookup Account']);
    Sanctum::actingAs($user);
    $options = mobileApiProfileTestPhase4AOptions();
    $familyFirst = mobileApiProfileTestMasterOption('master_family_types', 'zfamily', 'Zulu Source Family');
    $familySecond = mobileApiProfileTestMasterOption('master_family_types', 'afamily', 'Alpha Source Family');
    $varnaFirst = mobileApiProfileTestMasterOption('master_varnas', 'zvarna', 'Zulu Source Varna');
    $varnaSecond = mobileApiProfileTestMasterOption('master_varnas', 'avarna', 'Alpha Source Varna');
    DB::table('master_nakshatra_pada_rashi_rules')->updateOrInsert(
        ['nakshatra_id' => $options['nakshatra_id'], 'charan' => 1],
        ['rashi_id' => $options['rashi_id'], 'is_active' => true, 'updated_at' => now()]
    );
    DB::table('master_nakshatra_attributes')->updateOrInsert(
        ['nakshatra_id' => $options['nakshatra_id']],
        [
            'gan_id' => $options['gan_id'],
            'nadi_id' => $options['nadi_id'],
            'yoni_id' => $options['yoni_id'],
            'is_active' => true,
            'updated_at' => now(),
        ]
    );
    DB::table('master_rashis')
        ->where('id', $options['rashi_id'])
        ->update([
            'varna_id' => $options['varna_id'],
            'vashya_id' => $options['vashya_id'],
            'rashi_lord_id' => $options['rashi_lord_id'],
            'updated_at' => now(),
        ]);

    $response = $this->getJson('/api/v1/profile/remaining-profile-options');

    $response
        ->assertOk()
        ->assertJsonStructure([
            'family_types' => [
                '*' => ['id', 'key', 'label', 'label_en', 'label_mr'],
            ],
            'family_statuses' => [
                '*' => ['key', 'label', 'label_en', 'label_mr'],
            ],
            'family_values' => [
                '*' => ['key', 'label', 'label_en', 'label_mr'],
            ],
            'occupation_categories',
            'occupations',
            'custom_occupations',
            'rashis' => [
                '*' => ['id', 'key', 'label', 'label_en', 'label_mr'],
            ],
            'nakshatras' => [
                '*' => ['id', 'key', 'label', 'label_en', 'label_mr'],
            ],
            'gans',
            'nadis',
            'yonis',
            'mangal_dosh_types',
            'varnas' => [
                '*' => ['id', 'key', 'label', 'label_en', 'label_mr'],
            ],
            'vashyas',
            'rashi_lords',
            'birth_weekdays' => [
                '*' => ['key', 'label', 'label_en', 'label_mr'],
            ],
            'horoscope_rules' => [
                'rashi_rules',
                'nakshatra_attributes',
                'distinct_rashi_ids_by_nakshatra',
                'nakshatra_ids_by_rashi',
            ],
            'rashi_ashtakoota',
        ]);

    expect(collect($response->json('family_types'))->pluck('id')->all())->toContain($options['family_type_id']);
    expect(collect($response->json('family_statuses'))->pluck('key')->all())->toBe([
        'simple',
        'middle_class',
        'upper_middle_class',
        'affluent',
    ]);
    expect(collect($response->json('family_values'))->pluck('key')->all())->toBe([
        'traditional',
        'moderate',
        'modern',
    ]);
    expect(collect($response->json('rashis'))->pluck('id')->all())->toContain($options['rashi_id']);
    expect(collect($response->json('nakshatras'))->pluck('id')->all())->toContain($options['nakshatra_id']);
    expect(collect($response->json('mangal_dosh_types'))->pluck('id')->all())->toContain($options['mangal_dosh_type_id']);
    expect(collect($response->json('birth_weekdays'))->pluck('key')->all())->toBe([
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday',
    ]);
    mobileApiProfileAssertIdBefore(collect($response->json('family_types'))->pluck('id')->all(), $familyFirst, $familySecond);
    mobileApiProfileAssertIdBefore(collect($response->json('varnas'))->pluck('id')->all(), $varnaSecond, $varnaFirst);
    expect($response->json("horoscope_rules.rashi_rules"))->toContain([
        'nakshatra_id' => $options['nakshatra_id'],
        'charan' => 1,
        'rashi_id' => $options['rashi_id'],
    ]);
    expect($response->json("horoscope_rules.nakshatra_attributes"))->toContain([
        'nakshatra_id' => $options['nakshatra_id'],
        'gan_id' => $options['gan_id'],
        'nadi_id' => $options['nadi_id'],
        'yoni_id' => $options['yoni_id'],
    ]);
    expect($response->json("rashi_ashtakoota.{$options['rashi_id']}.varna_id"))->toBe($options['varna_id']);
    expect($response->json("rashi_ashtakoota.{$options['rashi_id']}.vashya_id"))->toBe($options['vashya_id']);
    expect($response->json("rashi_ashtakoota.{$options['rashi_id']}.rashi_lord_id"))->toBe($options['rashi_lord_id']);
});

test('MobileProfile GET api v1 profile partner preference options returns governed source options', function () {
    $user = User::factory()->create(['name' => 'Partner Preference Lookup Account']);
    Sanctum::actingAs($user);

    $marriageFirst = mobileApiProfileTestKnownMasterOption('master_marriage_type_preferences', 'phase5b1-arranged', 'Arranged', ['sort_order' => 101]);
    $marriageSecond = mobileApiProfileTestKnownMasterOption('master_marriage_type_preferences', 'phase5b1-love', 'Love Marriage', ['sort_order' => 202]);
    $divorcedId = mobileApiProfileTestKnownMaritalStatus('divorced', 'Divorced');
    $widowedId = mobileApiProfileTestKnownMaritalStatus('widowed', 'Widowed');
    $dietFirst = mobileApiProfileTestMasterOption('master_diets', 'phase5b1-vegetarian', 'Vegetarian', ['sort_order' => 101]);
    $dietSecond = mobileApiProfileTestMasterOption('master_diets', 'phase5b1-jain', 'Jain', ['sort_order' => 202]);
    $educationFirst = mobileApiProfileTestEducationDegree('P5D-B.A.', 101);
    $educationSecond = mobileApiProfileTestEducationDegree('P5D-M.A.', 202);
    $occupationFirst = mobileApiProfileTestOccupationMaster('P5D Analyst', 101);
    $occupationSecond = mobileApiProfileTestOccupationMaster('P5D Architect', 202);
    [$religion, $caste] = mobileApiProfileTestCommunity();
    $inactiveDietId = (int) DB::table('master_diets')->insertGetId([
        'key' => 'inactive'.substr(strtolower(str_replace('.', '', uniqid('', true))), -8),
        'label' => 'Inactive Partner Diet',
        'is_active' => false,
        'sort_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/profile/partner-preference-options');

    $response
        ->assertOk()
        ->assertJsonStructure([
            'marriage_type_preferences' => [
                '*' => ['id', 'key', 'label', 'label_en', 'label_mr'],
            ],
            'marital_statuses' => [
                '*' => ['id', 'key', 'label', 'label_en', 'label_mr'],
            ],
            'diets' => [
                '*' => ['id', 'key', 'label', 'label_en', 'label_mr'],
            ],
            'religions' => [
                '*' => ['id', 'key', 'label', 'label_en', 'label_mr'],
            ],
            'castes' => [
                '*' => ['id', 'religion_id', 'key', 'label', 'label_en', 'label_mr'],
            ],
            'education_degrees' => [
                '*' => ['id', 'code', 'label', 'label_en', 'label_mr', 'full_form', 'category_id', 'category_label', 'category_label_mr'],
            ],
            'occupation_categories' => [
                '*' => ['id', 'label', 'label_en', 'label_mr', 'legacy_working_with_type_id'],
            ],
            'occupations' => [
                '*' => ['id', 'label', 'label_en', 'label_mr', 'category_id', 'category_label', 'category_label_mr'],
            ],
            'partner_profile_with_children' => [
                '*' => ['key', 'label', 'label_en', 'label_mr'],
            ],
            'preferred_profile_managed_by' => [
                '*' => ['key', 'label', 'label_en', 'label_mr'],
            ],
        ]);

    mobileApiProfileAssertIdBefore(collect($response->json('marriage_type_preferences'))->pluck('id')->all(), $marriageFirst, $marriageSecond);
    mobileApiProfileAssertIdBefore(collect($response->json('marital_statuses'))->pluck('id')->all(), $divorcedId, $widowedId);
    mobileApiProfileAssertIdBefore(collect($response->json('diets'))->pluck('id')->all(), $dietFirst, $dietSecond);
    mobileApiProfileAssertIdBefore(collect($response->json('education_degrees'))->pluck('id')->all(), (int) $educationFirst->id, (int) $educationSecond->id);
    mobileApiProfileAssertIdBefore(collect($response->json('occupations'))->pluck('id')->all(), (int) $occupationFirst->id, (int) $occupationSecond->id);
    expect(collect($response->json('diets'))->pluck('id')->all())->not->toContain($inactiveDietId);
    expect(collect($response->json('religions'))->pluck('id')->all())->toContain((int) $religion->id);
    expect(collect($response->json('castes'))->firstWhere('id', (int) $caste->id)['religion_id'] ?? null)->toBe((int) $religion->id);
    expect(collect($response->json('partner_profile_with_children'))->pluck('key')->all())->toBe(['no', 'yes_if_live_separate', 'yes']);
    expect(collect($response->json('preferred_profile_managed_by'))->pluck('key')->all())->toBe(['', 'self', 'parent_guardian', 'sibling', 'relative', 'friend', 'other']);
});

test('MobileProfile GET api v1 matrimony-profile returns read only partner preference suggestions', function () {
    $user = User::factory()->create(['name' => 'Partner Preference Suggestion Account']);
    $options = mobileApiProfileTestMaritalLifestyleOptions();
    $profile = mobileApiCreateValidActionProfile($user, 'Partner Suggestion Candidate', 'female', null, [
        'date_of_birth' => now()->subYears(31)->subDay()->toDateString(),
        'height_cm' => 160,
        'marital_status_id' => $options['marital_status_id'],
        'diet_id' => $options['diet_id'],
    ]);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/matrimony-profile');

    $response
        ->assertOk()
        ->assertJsonPath('profile.preferred_age_min', null)
        ->assertJsonPath('profile.partner_preference_suggestions.preferred_age_min', 31)
        ->assertJsonPath('profile.partner_preference_suggestions.preferred_age_max', 36)
        ->assertJsonPath('profile.partner_preference_suggestions.preferred_height_min_cm', 160)
        ->assertJsonPath('profile.partner_preference_suggestions.preferred_height_max_cm', 170)
        ->assertJsonPath('profile.partner_preference_suggestions.preferred_marital_status_ids', [$options['marital_status_id']])
        ->assertJsonPath('profile.partner_preference_suggestions.preferred_diet_ids', [$options['diet_id']]);

    $this->assertDatabaseMissing('profile_preference_criteria', [
        'profile_id' => $profile->id,
    ]);
});

test('MobileProfile partner preference suggestions include own and nearby talukas across district and state', function () {
    $country = mobileApiProfileTestLocationNode('country', 'India');
    $maharashtra = mobileApiProfileTestLocationNode('state', 'Maharashtra', $country);
    $karnataka = mobileApiProfileTestLocationNode('state', 'Karnataka', $country);
    $sangli = mobileApiProfileTestLocationNode('district', 'Sangli', $maharashtra);
    $kolhapur = mobileApiProfileTestLocationNode('district', 'Kolhapur', $maharashtra);
    $belagavi = mobileApiProfileTestLocationNode('district', 'Belagavi', $karnataka);
    $ownTaluka = mobileApiProfileTestLocationNode('taluka', 'Khanapur', $sangli, ['lat' => 17.2800, 'lng' => 74.1800]);
    $nearTaluka = mobileApiProfileTestLocationNode('taluka', 'Hatkanangale', $kolhapur, ['lat' => 17.2900, 'lng' => 74.1950]);
    $crossStateTaluka = mobileApiProfileTestLocationNode('taluka', 'Athani', $belagavi, ['lat' => 17.3000, 'lng' => 74.2050]);
    $farSameDistrictTaluka = mobileApiProfileTestLocationNode('taluka', 'Jat', $sangli, ['lat' => 17.5500, 'lng' => 74.5500]);
    $leaf = mobileApiProfileTestLocationNode('village', 'Vita', $ownTaluka);
    $user = User::factory()->create(['name' => 'Nearby Taluka Suggestion Account']);
    mobileApiCreateValidActionProfile($user, 'Nearby Taluka Suggestion Candidate', 'female', $leaf);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/matrimony-profile');

    $suggestions = $response->json('profile.partner_preference_suggestions');
    $talukaIds = $suggestions['preferred_taluka_ids'] ?? [];
    $locationSuggestionIds = collect($suggestions['preferred_location_suggestions'] ?? [])->pluck('id')->all();

    expect($talukaIds[0] ?? null)->toBe($ownTaluka->id);
    expect($talukaIds)
        ->toContain($nearTaluka->id)
        ->toContain($crossStateTaluka->id)
        ->toContain($farSameDistrictTaluka->id);
    expect($suggestions['preferred_state_ids'] ?? [])
        ->toContain($maharashtra->id)
        ->toContain($karnataka->id);
    expect($suggestions['preferred_district_ids'] ?? [])
        ->toContain($sangli->id)
        ->toContain($kolhapur->id)
        ->toContain($belagavi->id);
    expect($locationSuggestionIds[0] ?? null)->toBe($ownTaluka->id);
    expect(array_search($crossStateTaluka->id, $talukaIds, true))
        ->toBeLessThan(array_search($farSameDistrictTaluka->id, $talukaIds, true));
});

test('MobileProfile partner preference suggestions limit nearby taluka payload size', function () {
    $country = mobileApiProfileTestLocationNode('country', 'India');
    $state = mobileApiProfileTestLocationNode('state', 'Maharashtra', $country);
    $district = mobileApiProfileTestLocationNode('district', 'Pune', $state);
    $ownTaluka = mobileApiProfileTestLocationNode('taluka', 'Haveli', $district, ['lat' => 18.5200, 'lng' => 73.8500]);
    $leaf = mobileApiProfileTestLocationNode('village', 'Wakad', $ownTaluka);
    for ($i = 1; $i <= 20; $i++) {
        mobileApiProfileTestLocationNode('taluka', 'Nearby '.$i, $district, [
            'lat' => 18.5200 + ($i * 0.001),
            'lng' => 73.8500 + ($i * 0.001),
        ]);
    }
    $user = User::factory()->create(['name' => 'Nearby Taluka Limit Account']);
    mobileApiCreateValidActionProfile($user, 'Nearby Taluka Limit Candidate', 'female', $leaf);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/matrimony-profile');

    $suggestions = $response->json('profile.partner_preference_suggestions');
    expect($suggestions['preferred_taluka_ids'][0] ?? null)->toBe($ownTaluka->id);
    expect($suggestions['preferred_taluka_ids'] ?? [])->toHaveCount(12);
    expect($suggestions['preferred_location_suggestions'] ?? [])->toHaveCount(12);
});

test('MobileProfile partner preference suggestions do not fake nearby talukas without lat lng', function () {
    $country = mobileApiProfileTestLocationNode('country', 'India');
    $state = mobileApiProfileTestLocationNode('state', 'Maharashtra', $country);
    $ownDistrict = mobileApiProfileTestLocationNode('district', 'Pune', $state, ['pincode' => '411001']);
    $otherDistrict = mobileApiProfileTestLocationNode('district', 'Raigad', $state, ['pincode' => '411001']);
    $ownTaluka = mobileApiProfileTestLocationNode('taluka', 'Haveli', $ownDistrict, ['pincode' => '411057']);
    $otherTaluka = mobileApiProfileTestLocationNode('taluka', 'Panvel', $otherDistrict, ['lat' => 18.9900, 'lng' => 73.1100]);
    $leaf = mobileApiProfileTestLocationNode('village', 'Wakad', $ownTaluka, ['pincode' => '411057']);
    $user = User::factory()->create(['name' => 'No Fake Nearby Taluka Account']);
    mobileApiCreateValidActionProfile($user, 'No Fake Nearby Taluka Candidate', 'female', $leaf);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/matrimony-profile');

    $suggestions = $response->json('profile.partner_preference_suggestions');
    expect($suggestions['preferred_taluka_ids'] ?? [])->toContain($ownTaluka->id);
    expect($suggestions['preferred_taluka_ids'] ?? [])->not->toContain($otherTaluka->id);
    expect(collect($suggestions['preferred_location_suggestions'] ?? [])->pluck('id')->all())
        ->not->toContain($otherTaluka->id);
});

test('MobileProfile partner preference suggestions can use child village centroid for nearby talukas', function () {
    $country = mobileApiProfileTestLocationNode('country', 'India');
    $state = mobileApiProfileTestLocationNode('state', 'Maharashtra', $country);
    $district = mobileApiProfileTestLocationNode('district', 'Sangli', $state);
    $ownTaluka = mobileApiProfileTestLocationNode('taluka', 'Khanapur', $district);
    $nearTaluka = mobileApiProfileTestLocationNode('taluka', 'Tasgaon', $district);
    $leaf = mobileApiProfileTestLocationNode('village', 'Vita', $ownTaluka);
    mobileApiProfileTestLocationNode('village', 'Taluka Center', $ownTaluka, ['lat' => 17.2800, 'lng' => 74.1800]);
    mobileApiProfileTestLocationNode('village', 'Nearby Taluka Center', $nearTaluka, ['lat' => 17.2850, 'lng' => 74.1900]);
    $user = User::factory()->create(['name' => 'Centroid Nearby Taluka Account']);
    mobileApiCreateValidActionProfile($user, 'Centroid Nearby Taluka Candidate', 'female', $leaf);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/matrimony-profile');

    $suggestions = $response->json('profile.partner_preference_suggestions');
    expect($suggestions['preferred_taluka_ids'][0] ?? null)->toBe($ownTaluka->id);
    expect($suggestions['preferred_taluka_ids'] ?? [])->toContain($nearTaluka->id);
});

test('MobileProfile POST api v1 matrimony-profile accepts canonical community ids', function () {
    mobileApiProfileTestSeedCurrentAddressType();
    $user = User::factory()->create(['name' => 'Canonical Account']);
    $location = mobileApiProfileTestLeafLocation();
    $birthCity = mobileApiProfileTestLeafLocation();
    [$religion, $caste, $subCaste] = mobileApiProfileTestCommunity();
    $gender = mobileApiProfileTestGender('female');
    $phase1A = mobileApiProfileTestPhase1AOptions();
    $phase3A = mobileApiProfileTestMaritalLifestyleOptions();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/matrimony-profile', [
        'full_name' => 'Canonical Mobile Candidate',
        'gender_id' => $gender->id,
        'date_of_birth' => '1998-04-15',
        'birth_time' => '10:30',
        'birth_city_id' => $birthCity->id,
        'birth_place_text' => 'Pune',
        'caste' => $caste->label,
        'highest_education' => 'B.E.',
        'location_id' => $location->id,
        'religion_id' => $religion->id,
        'caste_id' => $caste->id,
        'sub_caste_id' => $subCaste->id,
        'mother_tongue_id' => $phase1A['mother_tongue_id'],
        'marital_status_id' => $phase3A['marital_status_id'],
        'has_children' => false,
        'height_cm' => 168,
        'weight_kg' => 58,
        'complexion_id' => $phase1A['complexion_id'],
        'blood_group_id' => $phase1A['blood_group_id'],
        'physical_build_id' => $phase1A['physical_build_id'],
        'spectacles_lens' => 'contact_lens',
        'physical_condition' => 'none',
        'diet_id' => $phase3A['diet_id'],
        'smoking_status_id' => $phase3A['smoking_status_id'],
        'drinking_status_id' => $phase3A['drinking_status_id'],
    ]);

    $response
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Matrimony profile created',
            'profile' => [
                'full_name' => 'Canonical Mobile Candidate',
                'gender_id' => $gender->id,
                'religion_id' => $religion->id,
                'religion_label' => $religion->fresh()->display_label,
                'caste_id' => $caste->id,
                'caste_label' => $caste->fresh()->display_label,
                'sub_caste_id' => $subCaste->id,
                'sub_caste_label' => $subCaste->fresh()->display_label,
                'location_id' => $location->id,
                'birth_time' => '10:30',
                'birth_city_id' => $birthCity->id,
                'birth_place_text' => 'Pune',
                'mother_tongue_id' => $phase1A['mother_tongue_id'],
                'height_cm' => 168,
                'weight_kg' => 58,
                'complexion_id' => $phase1A['complexion_id'],
                'blood_group_id' => $phase1A['blood_group_id'],
                'physical_build_id' => $phase1A['physical_build_id'],
                'spectacles_lens' => 'contact_lens',
                'physical_condition' => 'none',
                'marital_status_id' => $phase3A['marital_status_id'],
                'diet_id' => $phase3A['diet_id'],
                'smoking_status_id' => $phase3A['smoking_status_id'],
                'drinking_status_id' => $phase3A['drinking_status_id'],
            ],
        ]);

    expect($response->json('profile.location_label'))->toContain('Wakad');
    expect($response->json('profile.birth_place_label'))->toContain('Wakad');
    expect($response->json('profile.mother_tongue_label'))->toBeString();
    expect($response->json('profile.complexion_label'))->toBeString();
    expect($response->json('profile.blood_group_label'))->toBeString();
    expect($response->json('profile.physical_build_label'))->toBeString();
    expect($response->json('profile.marital_status_label'))->toBeString();
    expect($response->json('profile.diet_label'))->toBeString();
    expect($response->json('profile.smoking_status_label'))->toBeString();
    expect($response->json('profile.drinking_status_label'))->toBeString();

    $profile = MatrimonyProfile::where('user_id', $user->id)->firstOrFail();

    expect((int) $profile->gender_id)->toBe((int) $gender->id);
    expect((int) $profile->religion_id)->toBe((int) $religion->id);
    expect((int) $profile->caste_id)->toBe((int) $caste->id);
    expect((int) $profile->sub_caste_id)->toBe((int) $subCaste->id);
    expect($profile->birth_time)->toBe('10:30');
    expect((int) $profile->birth_city_id)->toBe((int) $birthCity->id);
    expect($profile->birth_place_text)->toBe('Pune');
    expect((int) $profile->mother_tongue_id)->toBe((int) $phase1A['mother_tongue_id']);
    expect((int) $profile->height_cm)->toBe(168);
    expect((int) $profile->weight_kg)->toBe(58);
    expect((int) $profile->complexion_id)->toBe((int) $phase1A['complexion_id']);
    expect((int) $profile->blood_group_id)->toBe((int) $phase1A['blood_group_id']);
    expect((int) $profile->physical_build_id)->toBe((int) $phase1A['physical_build_id']);
    expect($profile->spectacles_lens)->toBe('contact_lens');
    expect($profile->physical_condition)->toBe('none');
    expect((int) $profile->marital_status_id)->toBe((int) $phase3A['marital_status_id']);
    expect((bool) $profile->has_children)->toBeFalse();
    expect((int) $profile->diet_id)->toBe((int) $phase3A['diet_id']);
    expect((int) $profile->smoking_status_id)->toBe((int) $phase3A['smoking_status_id']);
    expect((int) $profile->drinking_status_id)->toBe((int) $phase3A['drinking_status_id']);
});

test('MobileProfile POST api v1 matrimony-profile requires governed gender id', function () {
    mobileApiProfileTestSeedCurrentAddressType();
    $user = User::factory()->create(['name' => 'Missing Gender Account']);
    $location = mobileApiProfileTestLeafLocation();
    $caste = mobileApiProfileTestCaste();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/matrimony-profile', [
        'full_name' => 'Missing Gender Candidate',
        'date_of_birth' => '1998-04-15',
        'caste' => $caste->label,
        'highest_education' => 'B.E.',
        'location_id' => $location->id,
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['gender_id']);

    expect(MatrimonyProfile::where('user_id', $user->id)->exists())->toBeFalse();
});

test('MobileProfile POST api v1 matrimony-profile rejects caste religion mismatch', function () {
    mobileApiProfileTestSeedCurrentAddressType();
    $user = User::factory()->create(['name' => 'Mismatch Account']);
    $location = mobileApiProfileTestLeafLocation();
    [$religion, $caste] = mobileApiProfileTestCommunity();
    [$otherReligion] = mobileApiProfileTestCommunity();
    $gender = mobileApiProfileTestGender('female');
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/matrimony-profile', [
        'full_name' => 'Mismatch Mobile Candidate',
        'gender_id' => $gender->id,
        'date_of_birth' => '1998-04-15',
        'caste' => $caste->label,
        'highest_education' => 'B.E.',
        'location_id' => $location->id,
        'religion_id' => $otherReligion->id,
        'caste_id' => $caste->id,
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['caste_id']);

    expect(MatrimonyProfile::where('user_id', $user->id)->exists())->toBeFalse();
});

test('MobileProfile POST api v1 matrimony-profile rejects sub caste caste mismatch', function () {
    mobileApiProfileTestSeedCurrentAddressType();
    $user = User::factory()->create(['name' => 'Sub Caste Mismatch Account']);
    $location = mobileApiProfileTestLeafLocation();
    [$religion, $caste] = mobileApiProfileTestCommunity();
    [, , $otherSubCaste] = mobileApiProfileTestCommunity();
    $gender = mobileApiProfileTestGender('female');
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/matrimony-profile', [
        'full_name' => 'Sub Caste Mismatch Candidate',
        'gender_id' => $gender->id,
        'date_of_birth' => '1998-04-15',
        'caste' => $caste->label,
        'highest_education' => 'B.E.',
        'location_id' => $location->id,
        'religion_id' => $religion->id,
        'caste_id' => $caste->id,
        'sub_caste_id' => $otherSubCaste->id,
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sub_caste_id']);

    expect(MatrimonyProfile::where('user_id', $user->id)->exists())->toBeFalse();
});

test('MobileProfile POST api v1 matrimony-profile creates through governed mutation path', function () {
    mobileApiProfileTestSeedCurrentAddressType();
    $user = User::factory()->create(['name' => 'Bootstrap Account']);
    $location = mobileApiProfileTestLeafLocation();
    $caste = mobileApiProfileTestCaste();
    $gender = mobileApiProfileTestGender('female');
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/matrimony-profile', [
        'full_name' => 'Mobile Candidate',
        'gender_id' => $gender->id,
        'date_of_birth' => '1998-04-15',
        'caste' => 'Maratha',
        'highest_education' => 'B.E.',
        'location_id' => $location->id,
    ]);

    $response
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Matrimony profile created',
            'profile' => [
                'full_name' => 'Mobile Candidate',
                'gender_id' => $gender->id,
                'highest_education' => 'B.E.',
                'caste_id' => $caste->id,
            ],
        ]);

    $profile = MatrimonyProfile::where('user_id', $user->id)->firstOrFail();

    expect($profile->full_name)->toBe('Mobile Candidate');
    expect((int) $profile->gender_id)->toBe((int) $gender->id);
    expect(substr((string) $profile->date_of_birth, 0, 10))->toBe('1998-04-15');
    expect($profile->highest_education)->toBe('B.E.');
    expect((int) $profile->location_id)->toBe((int) $location->id);
    expect((int) $profile->caste_id)->toBe((int) $caste->id);
    expect(DB::table('profile_change_history')
        ->where('profile_id', $profile->id)
        ->whereIn('field_name', ['full_name', 'gender_id', 'date_of_birth', 'highest_education', 'location_id', 'caste_id'])
        ->count())->toBeGreaterThanOrEqual(6);
});

test('MobileProfile PUT api v1 matrimony-profile accepts mobile core fields through MutationService', function () {
    mobileApiProfileTestSeedCurrentAddressType();
    $user = User::factory()->create(['name' => 'Existing Account']);
    $profile = app(MutationService::class)->createDraftProfileForUser($user);
    $location = mobileApiProfileTestLeafLocation();
    $birthCity = mobileApiProfileTestLeafLocation();
    $caste = mobileApiProfileTestCaste();
    $gender = mobileApiProfileTestGender('female');
    $phase1A = mobileApiProfileTestPhase1AOptions();
    $phase3A = mobileApiProfileTestMaritalLifestyleOptions();
    Sanctum::actingAs($user);

    $response = $this->putJson('/api/v1/matrimony-profile', [
        'full_name' => 'Updated Mobile Candidate',
        'gender_id' => $gender->id,
        'date_of_birth' => '1997-03-20',
        'birth_time' => '22:15',
        'birth_city_id' => $birthCity->id,
        'birth_place_text' => 'Mumbai',
        'caste' => 'Maratha',
        'highest_education' => 'MCA',
        'location_id' => $location->id,
        'mother_tongue_id' => $phase1A['mother_tongue_id'],
        'marital_status_id' => $phase3A['marital_status_id'],
        'has_children' => true,
        'height_cm' => 172,
        'weight_kg' => 61,
        'complexion_id' => $phase1A['complexion_id'],
        'blood_group_id' => $phase1A['blood_group_id'],
        'physical_build_id' => $phase1A['physical_build_id'],
        'spectacles_lens' => 'spectacles',
        'physical_condition' => 'prefer_not_to_say',
        'diet_id' => $phase3A['diet_id'],
        'smoking_status_id' => $phase3A['smoking_status_id'],
        'drinking_status_id' => $phase3A['drinking_status_id'],
    ]);

    $response
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Matrimony profile updated',
            'profile' => [
                'full_name' => 'Updated Mobile Candidate',
                'gender_id' => $gender->id,
                'highest_education' => 'MCA',
                'caste_id' => $caste->id,
                'location_id' => $location->id,
                'birth_time' => '22:15',
                'birth_city_id' => $birthCity->id,
                'birth_place_text' => 'Mumbai',
                'mother_tongue_id' => $phase1A['mother_tongue_id'],
                'marital_status_id' => $phase3A['marital_status_id'],
                'height_cm' => 172,
                'weight_kg' => 61,
                'complexion_id' => $phase1A['complexion_id'],
                'blood_group_id' => $phase1A['blood_group_id'],
                'physical_build_id' => $phase1A['physical_build_id'],
                'spectacles_lens' => 'spectacles',
                'physical_condition' => 'prefer_not_to_say',
                'diet_id' => $phase3A['diet_id'],
                'smoking_status_id' => $phase3A['smoking_status_id'],
                'drinking_status_id' => $phase3A['drinking_status_id'],
            ],
        ]);

    $profile->refresh();

    expect($profile->full_name)->toBe('Updated Mobile Candidate');
    expect((int) $profile->gender_id)->toBe((int) $gender->id);
    expect(substr((string) $profile->date_of_birth, 0, 10))->toBe('1997-03-20');
    expect($profile->highest_education)->toBe('MCA');
    expect((int) $profile->location_id)->toBe((int) $location->id);
    expect((int) $profile->caste_id)->toBe((int) $caste->id);
    expect($profile->birth_time)->toBe('22:15');
    expect((int) $profile->birth_city_id)->toBe((int) $birthCity->id);
    expect($profile->birth_place_text)->toBe('Mumbai');
    expect((int) $profile->mother_tongue_id)->toBe((int) $phase1A['mother_tongue_id']);
    expect((int) $profile->marital_status_id)->toBe((int) $phase3A['marital_status_id']);
    expect((bool) $profile->has_children)->toBeTrue();
    expect((int) $profile->height_cm)->toBe(172);
    expect((int) $profile->weight_kg)->toBe(61);
    expect((int) $profile->complexion_id)->toBe((int) $phase1A['complexion_id']);
    expect((int) $profile->blood_group_id)->toBe((int) $phase1A['blood_group_id']);
    expect((int) $profile->physical_build_id)->toBe((int) $phase1A['physical_build_id']);
    expect($profile->spectacles_lens)->toBe('spectacles');
    expect($profile->physical_condition)->toBe('prefer_not_to_say');
    expect((int) $profile->diet_id)->toBe((int) $phase3A['diet_id']);
    expect((int) $profile->smoking_status_id)->toBe((int) $phase3A['smoking_status_id']);
    expect((int) $profile->drinking_status_id)->toBe((int) $phase3A['drinking_status_id']);
    expect(DB::table('profile_field_locks')
        ->where('profile_id', $profile->id)
        ->where('field_key', 'caste_id')
        ->exists())->toBeTrue();
});

test('MobileProfile PUT api v1 matrimony-profile can update fields after prior mobile locks', function () {
    mobileApiProfileTestSeedCurrentAddressType();
    $user = User::factory()->create(['name' => 'Repeat Edit Account']);
    $profile = app(MutationService::class)->createDraftProfileForUser($user);
    $firstLocation = mobileApiProfileTestLeafLocation();
    $secondLocation = mobileApiProfileTestLeafLocation();
    $firstBirthCity = mobileApiProfileTestLeafLocation();
    $secondBirthCity = mobileApiProfileTestLeafLocation();
    [$firstReligion, $firstCaste, $firstSubCaste] = mobileApiProfileTestCommunity();
    [$secondReligion, $secondCaste, $secondSubCaste] = mobileApiProfileTestCommunity();
    $gender = mobileApiProfileTestGender('female');
    $firstPhase1A = mobileApiProfileTestPhase1AOptions();
    $secondPhase1A = mobileApiProfileTestPhase1AOptions();
    $firstPhase3A = mobileApiProfileTestMaritalLifestyleOptions();
    $secondPhase3A = mobileApiProfileTestMaritalLifestyleOptions();
    $firstDegree = mobileApiProfileTestEducationDegree('B.A.', 20);
    $secondDegree = mobileApiProfileTestEducationDegree('M.B.A.', 30);
    $firstOccupation = mobileApiProfileTestOccupationMaster('Teacher');
    $secondOccupation = mobileApiProfileTestOccupationMaster('Business Analyst');
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/matrimony-profile', [
        'full_name' => 'First Mobile Edit',
        'gender_id' => $gender->id,
        'date_of_birth' => '1997-03-20',
        'birth_time' => '08:30',
        'birth_city_id' => $firstBirthCity->id,
        'birth_place_text' => 'First Birth Place',
        'caste' => $firstCaste->label,
        'highest_education' => 'First education',
        'education_slots' => json_encode([['t' => 'd', 'id' => $firstDegree->id]], JSON_THROW_ON_ERROR),
        'location_id' => $firstLocation->id,
        'religion_id' => $firstReligion->id,
        'caste_id' => $firstCaste->id,
        'sub_caste_id' => $firstSubCaste->id,
        'mother_tongue_id' => $firstPhase1A['mother_tongue_id'],
        'marital_status_id' => $firstPhase3A['marital_status_id'],
        'has_children' => false,
        'height_cm' => 160,
        'weight_kg' => 50,
        'complexion_id' => $firstPhase1A['complexion_id'],
        'blood_group_id' => $firstPhase1A['blood_group_id'],
        'physical_build_id' => $firstPhase1A['physical_build_id'],
        'spectacles_lens' => 'spectacles',
        'physical_condition' => 'none',
        'diet_id' => $firstPhase3A['diet_id'],
        'smoking_status_id' => $firstPhase3A['smoking_status_id'],
        'drinking_status_id' => $firstPhase3A['drinking_status_id'],
        'occupation_master_id' => $firstOccupation->id,
        'company_name' => 'First Company',
        'work_location_text' => 'First Work Location',
    ])->assertOk();

    expect(DB::table('profile_field_locks')
        ->where('profile_id', $profile->id)
        ->whereIn('field_key', ['full_name', 'location_id', 'height_cm', 'occupation_master_id'])
        ->count())->toBeGreaterThanOrEqual(4);

    $response = $this->putJson('/api/v1/matrimony-profile', [
        'full_name' => 'Second Mobile Edit',
        'gender_id' => $gender->id,
        'date_of_birth' => '1998-04-21',
        'birth_time' => '22:45',
        'birth_city_id' => $secondBirthCity->id,
        'birth_place_text' => 'Second Birth Place',
        'caste' => $secondCaste->label,
        'highest_education' => 'Second education',
        'education_slots' => json_encode([['t' => 'd', 'id' => $secondDegree->id]], JSON_THROW_ON_ERROR),
        'location_id' => $secondLocation->id,
        'religion_id' => $secondReligion->id,
        'caste_id' => $secondCaste->id,
        'sub_caste_id' => $secondSubCaste->id,
        'mother_tongue_id' => $secondPhase1A['mother_tongue_id'],
        'marital_status_id' => $secondPhase3A['marital_status_id'],
        'has_children' => true,
        'height_cm' => 172,
        'weight_kg' => 66,
        'complexion_id' => $secondPhase1A['complexion_id'],
        'blood_group_id' => $secondPhase1A['blood_group_id'],
        'physical_build_id' => $secondPhase1A['physical_build_id'],
        'spectacles_lens' => 'both',
        'physical_condition' => 'prefer_not_to_say',
        'diet_id' => $secondPhase3A['diet_id'],
        'smoking_status_id' => $secondPhase3A['smoking_status_id'],
        'drinking_status_id' => $secondPhase3A['drinking_status_id'],
        'occupation_master_id' => $secondOccupation->id,
        'company_name' => 'Second Company',
        'work_location_text' => 'Second Work Location',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('profile.full_name', 'Second Mobile Edit')
        ->assertJsonPath('profile.location_id', $secondLocation->id)
        ->assertJsonPath('profile.birth_city_id', $secondBirthCity->id)
        ->assertJsonPath('profile.birth_place_text', 'Second Birth Place')
        ->assertJsonPath('profile.mother_tongue_id', $secondPhase1A['mother_tongue_id'])
        ->assertJsonPath('profile.marital_status_id', $secondPhase3A['marital_status_id'])
        ->assertJsonPath('profile.height_cm', 172)
        ->assertJsonPath('profile.weight_kg', 66)
        ->assertJsonPath('profile.complexion_id', $secondPhase1A['complexion_id'])
        ->assertJsonPath('profile.blood_group_id', $secondPhase1A['blood_group_id'])
        ->assertJsonPath('profile.physical_build_id', $secondPhase1A['physical_build_id'])
        ->assertJsonPath('profile.spectacles_lens', 'both')
        ->assertJsonPath('profile.physical_condition', 'prefer_not_to_say')
        ->assertJsonPath('profile.diet_id', $secondPhase3A['diet_id'])
        ->assertJsonPath('profile.smoking_status_id', $secondPhase3A['smoking_status_id'])
        ->assertJsonPath('profile.drinking_status_id', $secondPhase3A['drinking_status_id'])
        ->assertJsonPath('profile.occupation_master_id', $secondOccupation->id)
        ->assertJsonPath('profile.company_name', 'Second Company')
        ->assertJsonPath('profile.work_location_text', 'Second Work Location');

    $profile->refresh();

    expect($profile->full_name)->toBe('Second Mobile Edit');
    expect(substr((string) $profile->date_of_birth, 0, 10))->toBe('1998-04-21');
    expect((int) $profile->location_id)->toBe((int) $secondLocation->id);
    expect((int) $profile->birth_city_id)->toBe((int) $secondBirthCity->id);
    expect($profile->birth_place_text)->toBe('Second Birth Place');
    expect((int) $profile->religion_id)->toBe((int) $secondReligion->id);
    expect((int) $profile->caste_id)->toBe((int) $secondCaste->id);
    expect((int) $profile->sub_caste_id)->toBe((int) $secondSubCaste->id);
    expect($profile->highest_education)->toContain($secondDegree->code);
    expect((int) $profile->mother_tongue_id)->toBe((int) $secondPhase1A['mother_tongue_id']);
    expect((int) $profile->marital_status_id)->toBe((int) $secondPhase3A['marital_status_id']);
    expect((bool) $profile->has_children)->toBeTrue();
    expect((int) $profile->height_cm)->toBe(172);
    expect((int) $profile->weight_kg)->toBe(66);
    expect((int) $profile->complexion_id)->toBe((int) $secondPhase1A['complexion_id']);
    expect((int) $profile->blood_group_id)->toBe((int) $secondPhase1A['blood_group_id']);
    expect((int) $profile->physical_build_id)->toBe((int) $secondPhase1A['physical_build_id']);
    expect($profile->spectacles_lens)->toBe('both');
    expect($profile->physical_condition)->toBe('prefer_not_to_say');
    expect((int) $profile->diet_id)->toBe((int) $secondPhase3A['diet_id']);
    expect((int) $profile->smoking_status_id)->toBe((int) $secondPhase3A['smoking_status_id']);
    expect((int) $profile->drinking_status_id)->toBe((int) $secondPhase3A['drinking_status_id']);
    expect((int) $profile->occupation_master_id)->toBe((int) $secondOccupation->id);
    expect($profile->company_name)->toBe('Second Company');
    expect($profile->work_location_text)->toBe('Second Work Location');
});

test('MobileProfile PUT api v1 matrimony-profile accepts education career fields through MutationService', function () {
    mobileApiProfileTestSeedCurrentAddressType();
    $user = User::factory()->create(['name' => 'Education Career Account']);
    $profile = app(MutationService::class)->createDraftProfileForUser($user);
    $location = mobileApiProfileTestLeafLocation();
    $caste = mobileApiProfileTestCaste();
    $gender = mobileApiProfileTestGender('female');
    $degree = mobileApiProfileTestEducationDegree('M.B.A.', 30);
    $occupation = mobileApiProfileTestOccupationMaster('Business Analyst');
    Sanctum::actingAs($user);

    $response = $this->putJson('/api/v1/matrimony-profile', [
        'full_name' => 'Career Updated Candidate',
        'gender_id' => $gender->id,
        'date_of_birth' => '1997-03-20',
        'caste' => 'Maratha',
        'education_slots' => json_encode([['t' => 'd', 'id' => $degree->id]], JSON_THROW_ON_ERROR),
        'location_id' => $location->id,
        'occupation_master_id' => $occupation->id,
        'company_name' => 'Navri Tech',
        'work_location_text' => 'Pune, Maharashtra',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('profile.occupation_master_id', $occupation->id)
        ->assertJsonPath('profile.occupation_master_label', $occupation->name)
        ->assertJsonPath('profile.company_name', 'Navri Tech')
        ->assertJsonPath('profile.work_location_text', 'Pune, Maharashtra');

    $profile->refresh();

    expect((int) $profile->gender_id)->toBe((int) $gender->id);
    expect((int) $profile->caste_id)->toBe((int) $caste->id);
    expect($profile->highest_education)->toContain($degree->code);
    expect((int) $profile->location_id)->toBe((int) $location->id);
    expect((int) $profile->occupation_master_id)->toBe((int) $occupation->id);
    expect($profile->company_name)->toBe('Navri Tech');
    expect($profile->work_location_text)->toBe('Pune, Maharashtra');
});

test('MobileProfile PUT api v1 matrimony-profile accepts remaining edit all scalar fields through MutationService', function () {
    mobileApiProfileTestSeedCurrentAddressType();
    $user = User::factory()->create(['name' => 'Remaining Edit Account']);
    $profile = app(MutationService::class)->createDraftProfileForUser($user);
    $location = mobileApiProfileTestLeafLocation();
    $caste = mobileApiProfileTestCaste();
    $gender = mobileApiProfileTestGender('female');
    $options = mobileApiProfileTestPhase4AOptions();
    $fatherOccupation = mobileApiProfileTestOccupationMaster('Father Business');
    $motherOccupation = mobileApiProfileTestOccupationMaster('Mother Teacher');
    Sanctum::actingAs($user);

    $response = $this->putJson('/api/v1/matrimony-profile', [
        'full_name' => 'Remaining Updated Candidate',
        'gender_id' => $gender->id,
        'date_of_birth' => '1997-03-20',
        'caste' => 'Maratha',
        'highest_education' => 'B.Com.',
        'location_id' => $location->id,
        'father_name' => 'Father Person',
        'father_occupation_master_id' => $fatherOccupation->id,
        'father_extra_info' => 'Runs a family business',
        'mother_name' => 'Mother Person',
        'mother_occupation_master_id' => $motherOccupation->id,
        'mother_extra_info' => 'Works in education',
        'family_type_id' => $options['family_type_id'],
        'family_status' => 'middle_class',
        'family_values' => 'traditional',
        'has_siblings' => true,
        'other_relatives_text' => 'Relatives are settled in Pune.',
        'property_details' => 'Own house and farm land.',
        'rashi_id' => $options['rashi_id'],
        'nakshatra_id' => $options['nakshatra_id'],
        'charan' => 2,
        'gan_id' => $options['gan_id'],
        'nadi_id' => $options['nadi_id'],
        'yoni_id' => $options['yoni_id'],
        'varna_id' => $options['varna_id'],
        'vashya_id' => $options['vashya_id'],
        'rashi_lord_id' => $options['rashi_lord_id'],
        'mangal_dosh_type_id' => $options['mangal_dosh_type_id'],
        'devak' => 'Audumbar',
        'kul' => 'Kuldaivat',
        'gotra' => 'Kashyap',
        'navras_name' => 'Navras Name',
        'birth_weekday' => 'Monday',
        'narrative_about_me' => 'I value family, education, and mutual respect.',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('profile.father_name', 'Father Person')
        ->assertJsonPath('profile.father_occupation_master_id', $fatherOccupation->id)
        ->assertJsonPath('profile.father_occupation_master_label', $fatherOccupation->name)
        ->assertJsonPath('profile.mother_name', 'Mother Person')
        ->assertJsonPath('profile.mother_occupation_master_id', $motherOccupation->id)
        ->assertJsonPath('profile.mother_occupation_master_label', $motherOccupation->name)
        ->assertJsonPath('profile.family_type_id', $options['family_type_id'])
        ->assertJsonPath('profile.family_status', 'middle_class')
        ->assertJsonPath('profile.family_values', 'traditional')
        ->assertJsonPath('profile.has_siblings', true)
        ->assertJsonPath('profile.other_relatives_text', 'Relatives are settled in Pune.')
        ->assertJsonPath('profile.property_details', 'Own house and farm land.')
        ->assertJsonPath('profile.rashi_id', $options['rashi_id'])
        ->assertJsonPath('profile.nakshatra_id', $options['nakshatra_id'])
        ->assertJsonPath('profile.charan', 2)
        ->assertJsonPath('profile.gan_id', $options['gan_id'])
        ->assertJsonPath('profile.nadi_id', $options['nadi_id'])
        ->assertJsonPath('profile.yoni_id', $options['yoni_id'])
        ->assertJsonPath('profile.varna_id', $options['varna_id'])
        ->assertJsonPath('profile.vashya_id', $options['vashya_id'])
        ->assertJsonPath('profile.rashi_lord_id', $options['rashi_lord_id'])
        ->assertJsonPath('profile.mangal_dosh_type_id', $options['mangal_dosh_type_id'])
        ->assertJsonPath('profile.devak', 'Audumbar')
        ->assertJsonPath('profile.kul', 'Kuldaivat')
        ->assertJsonPath('profile.gotra', 'Kashyap')
        ->assertJsonPath('profile.navras_name', 'Navras Name')
        ->assertJsonPath('profile.birth_weekday', 'Monday')
        ->assertJsonPath('profile.narrative_about_me', 'I value family, education, and mutual respect.');

    $profile->refresh();
    $horoscope = DB::table('profile_horoscope_data')->where('profile_id', $profile->id)->first();
    $extended = DB::table('profile_extended_attributes')->where('profile_id', $profile->id)->first();

    expect((int) $profile->gender_id)->toBe((int) $gender->id);
    expect((int) $profile->caste_id)->toBe((int) $caste->id);
    expect((int) $profile->location_id)->toBe((int) $location->id);
    expect($profile->father_name)->toBe('Father Person');
    expect((int) $profile->father_occupation_master_id)->toBe((int) $fatherOccupation->id);
    expect($profile->mother_name)->toBe('Mother Person');
    expect((int) $profile->mother_occupation_master_id)->toBe((int) $motherOccupation->id);
    expect((int) $profile->family_type_id)->toBe((int) $options['family_type_id']);
    expect($profile->family_status)->toBe('middle_class');
    expect($profile->family_values)->toBe('traditional');
    expect((bool) $profile->has_siblings)->toBeTrue();
    expect($profile->other_relatives_text)->toBe('Relatives are settled in Pune.');
    expect($profile->property_details)->toBe('Own house and farm land.');
    expect((int) $horoscope->rashi_id)->toBe((int) $options['rashi_id']);
    expect((int) $horoscope->nakshatra_id)->toBe((int) $options['nakshatra_id']);
    expect((int) $horoscope->charan)->toBe(2);
    expect((int) $horoscope->gan_id)->toBe((int) $options['gan_id']);
    expect((int) $horoscope->nadi_id)->toBe((int) $options['nadi_id']);
    expect((int) $horoscope->yoni_id)->toBe((int) $options['yoni_id']);
    expect((int) $horoscope->varna_id)->toBe((int) $options['varna_id']);
    expect((int) $horoscope->vashya_id)->toBe((int) $options['vashya_id']);
    expect((int) $horoscope->rashi_lord_id)->toBe((int) $options['rashi_lord_id']);
    expect((int) $horoscope->mangal_dosh_type_id)->toBe((int) $options['mangal_dosh_type_id']);
    expect($horoscope->devak)->toBe('Audumbar');
    expect($horoscope->kul)->toBe('Kuldaivat');
    expect($horoscope->gotra)->toBe('Kashyap');
    expect($horoscope->navras_name)->toBe('Navras Name');
    expect($horoscope->birth_weekday)->toBe('Monday');
    expect($extended->narrative_about_me)->toBe('I value family, education, and mutual respect.');
    expect($response->json('profile'))->not->toHaveKeys([
        'father_contact_1',
        'father_contact_2',
        'father_contact_3',
        'mother_contact_1',
        'mother_contact_2',
        'mother_contact_3',
    ]);
});

test('MobileProfile PUT api v1 matrimony-profile persists simple partner preferences through governed path', function () {
    $user = User::factory()->create(['name' => 'Partner Preference Edit Account']);
    $profile = mobileApiCreateValidActionProfile($user, 'Partner Preference Candidate', 'female');
    $options = mobileApiProfileTestPartnerPreferenceOptions();
    Sanctum::actingAs($user);

    DB::table('profile_extended_attributes')->insert([
        'profile_id' => $profile->id,
        'narrative_about_me' => 'Existing about me text.',
        'narrative_expectations' => 'Old expectations.',
        'additional_notes' => 'Keep this note.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if (Schema::hasTable('profile_preferred_religions') && $profile->religion_id) {
        DB::table('profile_preferred_religions')->insert([
            'profile_id' => $profile->id,
            'religion_id' => $profile->religion_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $expectedMaritalIds = collect([
        $options['marital_status_id'],
        $options['second_marital_status_id'],
    ])->sort()->values()->all();
    $expectedDietIds = collect([
        $options['diet_id'],
        $options['second_diet_id'],
    ])->sort()->values()->all();
    $expectedReligionIds = collect([$profile->religion_id])->sort()->values()->map(fn ($id) => (int) $id)->all();
    $expectedCasteIds = collect([$profile->caste_id])->sort()->values()->map(fn ($id) => (int) $id)->all();
    $expectedEducationDegreeIds = [$options['education_degree_id']];
    $expectedOccupationMasterIds = [$options['occupation_master_id']];
    $locationChain = mobileApiProfileTestLocationChain();
    $expectedCountryIds = [(int) $locationChain['country']->id];
    $expectedStateIds = [(int) $locationChain['state']->id];
    $expectedDistrictIds = [(int) $locationChain['district']->id];
    $expectedTalukaIds = [(int) $locationChain['taluka']->id];

    $response = $this->putJson('/api/v1/matrimony-profile', [
        'preferred_age_min' => 24,
        'preferred_age_max' => 31,
        'preferred_height_min_cm' => 150,
        'preferred_height_max_cm' => 180,
        'preferred_income_min' => 700000,
        'preferred_income_max' => 1200000,
        'marriage_type_preference_id' => $options['marriage_type_preference_id'],
        'partner_profile_with_children' => 'yes_if_live_separate',
        'preferred_profile_managed_by' => 'parent_guardian',
        'willing_to_relocate' => true,
        'preferred_religion_ids' => $expectedReligionIds,
        'preferred_caste_ids' => $expectedCasteIds,
        'preferred_intercaste' => true,
        'preferred_education_degree_ids' => $expectedEducationDegreeIds,
        'preferred_occupation_master_ids' => $expectedOccupationMasterIds,
        'preferred_marital_status_ids' => [
            $options['marital_status_id'],
            $options['second_marital_status_id'],
        ],
        'preferred_diet_ids' => [
            $options['diet_id'],
            $options['second_diet_id'],
        ],
        'preferred_country_ids' => $expectedCountryIds,
        'preferred_state_ids' => $expectedStateIds,
        'preferred_district_ids' => $expectedDistrictIds,
        'preferred_taluka_ids' => $expectedTalukaIds,
        'narrative_expectations' => 'Looking for a thoughtful partner.',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('profile.preferred_age_min', 24)
        ->assertJsonPath('profile.preferred_age_max', 31)
        ->assertJsonPath('profile.preferred_height_min_cm', 150)
        ->assertJsonPath('profile.preferred_height_max_cm', 180)
        ->assertJsonPath('profile.preferred_income_min', 700000)
        ->assertJsonPath('profile.preferred_income_max', 1200000)
        ->assertJsonPath('profile.marriage_type_preference_id', $options['marriage_type_preference_id'])
        ->assertJsonPath('profile.partner_profile_with_children', 'yes_if_live_separate')
        ->assertJsonPath('profile.preferred_profile_managed_by', 'parent_guardian')
        ->assertJsonPath('profile.willing_to_relocate', true)
        ->assertJsonPath('profile.preferred_religion_ids', $expectedReligionIds)
        ->assertJsonPath('profile.preferred_caste_ids', $expectedCasteIds)
        ->assertJsonPath('profile.preferred_intercaste', true)
        ->assertJsonPath('profile.preferred_education_degree_ids', $expectedEducationDegreeIds)
        ->assertJsonPath('profile.preferred_occupation_master_ids', $expectedOccupationMasterIds)
        ->assertJsonPath('profile.preferred_marital_status_ids', $expectedMaritalIds)
        ->assertJsonPath('profile.preferred_diet_ids', $expectedDietIds)
        ->assertJsonPath('profile.preferred_country_ids', $expectedCountryIds)
        ->assertJsonPath('profile.preferred_state_ids', $expectedStateIds)
        ->assertJsonPath('profile.preferred_district_ids', $expectedDistrictIds)
        ->assertJsonPath('profile.preferred_taluka_ids', $expectedTalukaIds)
        ->assertJsonPath('profile.narrative_about_me', 'Existing about me text.')
        ->assertJsonPath('profile.narrative_expectations', 'Looking for a thoughtful partner.');

    $criteria = DB::table('profile_preference_criteria')->where('profile_id', $profile->id)->first();
    $extended = DB::table('profile_extended_attributes')->where('profile_id', $profile->id)->first();
    $maritalIds = DB::table('profile_preferred_marital_statuses')
        ->where('profile_id', $profile->id)
        ->orderBy('marital_status_id')
        ->pluck('marital_status_id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();
    $dietIds = DB::table('profile_preferred_diets')
        ->where('profile_id', $profile->id)
        ->orderBy('diet_id')
        ->pluck('diet_id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();
    $religionIds = DB::table('profile_preferred_religions')
        ->where('profile_id', $profile->id)
        ->orderBy('religion_id')
        ->pluck('religion_id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();
    $casteIds = DB::table('profile_preferred_castes')
        ->where('profile_id', $profile->id)
        ->orderBy('caste_id')
        ->pluck('caste_id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();
    $educationDegreeIds = DB::table('profile_preferred_education_degrees')
        ->where('profile_id', $profile->id)
        ->orderBy('education_degree_id')
        ->pluck('education_degree_id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();
    $occupationMasterIds = DB::table('profile_preferred_occupation_master')
        ->where('profile_id', $profile->id)
        ->orderBy('occupation_master_id')
        ->pluck('occupation_master_id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();
    $countryIds = DB::table('profile_preferred_countries')
        ->where('profile_id', $profile->id)
        ->orderBy('country_id')
        ->pluck('country_id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();
    $stateIds = DB::table('profile_preferred_states')
        ->where('profile_id', $profile->id)
        ->orderBy('state_id')
        ->pluck('state_id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();
    $districtIds = DB::table('profile_preferred_districts')
        ->where('profile_id', $profile->id)
        ->orderBy('district_id')
        ->pluck('district_id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();
    $talukaIds = DB::table('profile_preferred_talukas')
        ->where('profile_id', $profile->id)
        ->orderBy('taluka_id')
        ->pluck('taluka_id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();

    expect((int) $criteria->preferred_age_min)->toBe(24);
    expect((int) $criteria->preferred_age_max)->toBe(31);
    expect((int) $criteria->preferred_height_min_cm)->toBe(150);
    expect((int) $criteria->preferred_height_max_cm)->toBe(180);
    expect((int) $criteria->preferred_income_min)->toBe(700000);
    expect((int) $criteria->preferred_income_max)->toBe(1200000);
    expect((int) $criteria->marriage_type_preference_id)->toBe((int) $options['marriage_type_preference_id']);
    expect($criteria->partner_profile_with_children)->toBe('yes_if_live_separate');
    expect($criteria->preferred_profile_managed_by)->toBe('parent_guardian');
    expect((bool) $criteria->willing_to_relocate)->toBeTrue();
    expect($maritalIds)->toBe($expectedMaritalIds);
    expect($dietIds)->toBe($expectedDietIds);
    expect($religionIds)->toBe($expectedReligionIds);
    expect($casteIds)->toBe($expectedCasteIds);
    expect($educationDegreeIds)->toBe($expectedEducationDegreeIds);
    expect($occupationMasterIds)->toBe($expectedOccupationMasterIds);
    expect(ProfilePartnerCommunityFlagService::interestedInIntercaste((int) $profile->id))->toBeTrue();
    expect($countryIds)->toBe($expectedCountryIds);
    expect($stateIds)->toBe($expectedStateIds);
    expect($districtIds)->toBe($expectedDistrictIds);
    expect($talukaIds)->toBe($expectedTalukaIds);
    expect($extended->narrative_about_me)->toBe('Existing about me text.');
    expect($extended->narrative_expectations)->toBe('Looking for a thoughtful partner.');
    expect($extended->additional_notes)->toBe('Keep this note.');

    if (Schema::hasTable('profile_preferred_religions') && $profile->religion_id) {
        $this->assertDatabaseHas('profile_preferred_religions', [
            'profile_id' => $profile->id,
            'religion_id' => $profile->religion_id,
        ]);
    }

    $display = $this->getJson('/api/v1/matrimony-profile')->assertOk();
    $display
        ->assertJsonPath('profile.preferred_religion_ids', $expectedReligionIds)
        ->assertJsonPath('profile.preferred_caste_ids', $expectedCasteIds)
        ->assertJsonPath('profile.preferred_education_degree_ids', $expectedEducationDegreeIds)
        ->assertJsonPath('profile.preferred_occupation_master_ids', $expectedOccupationMasterIds)
        ->assertJsonPath('profile.preferred_intercaste', true);
    $partnerPreferenceItems = collect(collect($display->json('display.sections'))
        ->firstWhere('key', 'partner_preferences')['items'] ?? []);
    expect($partnerPreferenceItems->contains(
        fn (array $item): bool => ($item['label'] ?? null) === 'Intercaste'
            && ($item['value'] ?? null) === 'Open to intercaste'
    ))->toBeTrue();
});

test('MobileProfile PUT api v1 matrimony-profile rejects invalid education career ids', function () {
    $user = User::factory()->create(['name' => 'Invalid Career Account']);
    app(MutationService::class)->createDraftProfileForUser($user);
    $gender = mobileApiProfileTestGender('female');
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/matrimony-profile', [
        'gender_id' => $gender->id,
        'occupation_master_id' => 999999999,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['occupation_master_id']);

    $this->putJson('/api/v1/matrimony-profile', [
        'gender_id' => $gender->id,
        'education_slots' => json_encode([['t' => 'd', 'id' => 999999999]], JSON_THROW_ON_ERROR),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['education_slots']);
});

test('MobileProfile PUT api v1 matrimony-profile rejects invalid marital lifestyle ids', function () {
    $user = User::factory()->create(['name' => 'Invalid Marital Lifestyle Account']);
    app(MutationService::class)->createDraftProfileForUser($user);
    $gender = mobileApiProfileTestGender('female');
    Sanctum::actingAs($user);

    foreach (['marital_status_id', 'diet_id', 'smoking_status_id', 'drinking_status_id'] as $field) {
        $this->putJson('/api/v1/matrimony-profile', [
            'gender_id' => $gender->id,
            $field => 999999999,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
    }
});

test('MobileProfile PUT api v1 matrimony-profile rejects invalid remaining edit all ids', function () {
    $user = User::factory()->create(['name' => 'Invalid Remaining Account']);
    app(MutationService::class)->createDraftProfileForUser($user);
    $gender = mobileApiProfileTestGender('female');
    Sanctum::actingAs($user);

    foreach ([
        'family_type_id',
        'father_occupation_master_id',
        'mother_occupation_master_id',
        'rashi_id',
        'nakshatra_id',
        'gan_id',
        'nadi_id',
        'yoni_id',
        'varna_id',
        'vashya_id',
        'rashi_lord_id',
        'mangal_dosh_type_id',
    ] as $field) {
        $this->putJson('/api/v1/matrimony-profile', [
            'gender_id' => $gender->id,
            $field => 999999999,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
    }
});

test('MobileProfile PUT api v1 matrimony-profile rejects invalid partner preference ids and ranges', function () {
    $user = User::factory()->create(['name' => 'Invalid Partner Preference Account']);
    mobileApiCreateValidActionProfile($user, 'Invalid Partner Preference Candidate', 'female');
    Sanctum::actingAs($user);

    foreach ([
        'marriage_type_preference_id',
        'preferred_religion_ids',
        'preferred_caste_ids',
        'preferred_education_degree_ids',
        'preferred_occupation_master_ids',
        'preferred_marital_status_ids',
        'preferred_diet_ids',
    ] as $field) {
        $payload = match ($field) {
            'preferred_religion_ids',
            'preferred_caste_ids',
            'preferred_education_degree_ids',
            'preferred_occupation_master_ids',
            'preferred_marital_status_ids',
            'preferred_diet_ids' => [$field => [999999999]],
            default => [$field => 999999999],
        };

        $expectedError = match ($field) {
            'preferred_religion_ids',
            'preferred_caste_ids',
            'preferred_education_degree_ids',
            'preferred_occupation_master_ids',
            'preferred_marital_status_ids',
            'preferred_diet_ids' => $field.'.0',
            default => $field,
        };

        $this->putJson('/api/v1/matrimony-profile', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors([$expectedError]);
    }

    $this->putJson('/api/v1/matrimony-profile', [
        'preferred_age_min' => 35,
        'preferred_age_max' => 24,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['preferred_age_min']);

    $this->putJson('/api/v1/matrimony-profile', [
        'preferred_height_min_cm' => 180,
        'preferred_height_max_cm' => 150,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['preferred_height_min_cm']);

    $this->putJson('/api/v1/matrimony-profile', [
        'preferred_income_min' => 1200000,
        'preferred_income_max' => 700000,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['preferred_income_min']);

    $this->putJson('/api/v1/matrimony-profile', [
        'preferred_intercaste' => 'not-a-boolean',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['preferred_intercaste']);

    [$religionA] = mobileApiProfileTestCommunity();
    [, $casteB] = mobileApiProfileTestCommunity();
    $this->putJson('/api/v1/matrimony-profile', [
        'preferred_religion_ids' => [(int) $religionA->id],
        'preferred_caste_ids' => [(int) $casteB->id],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['preferred_caste_ids']);
});

test('MobileProfile GET api v1 matrimony profile returns clean display payload beside profile', function () {
    mobileApiProfileTestSeedCurrentAddressType();
    $user = User::factory()->create(['name' => 'Display Account']);
    $location = mobileApiProfileTestLeafLocation();
    $birthCity = mobileApiProfileTestLeafLocation();
    [$religion, $caste, $subCaste] = mobileApiProfileTestCommunity();
    $gender = mobileApiProfileTestGender('female');
    $phase1A = mobileApiProfileTestPhase1AOptions();
    Sanctum::actingAs($user);

    $create = $this->postJson('/api/v1/matrimony-profile', [
        'full_name' => 'Display Mobile Candidate',
        'gender_id' => $gender->id,
        'date_of_birth' => '1995-01-05',
        'birth_time' => '09:45',
        'birth_city_id' => $birthCity->id,
        'birth_place_text' => 'Nashik',
        'caste' => $caste->label,
        'highest_education' => 'B.A., B.Com.',
        'location_id' => $location->id,
        'religion_id' => $religion->id,
        'caste_id' => $caste->id,
        'sub_caste_id' => $subCaste->id,
        'mother_tongue_id' => $phase1A['mother_tongue_id'],
        'height_cm' => 165,
        'weight_kg' => 55,
        'complexion_id' => $phase1A['complexion_id'],
        'blood_group_id' => $phase1A['blood_group_id'],
        'physical_build_id' => $phase1A['physical_build_id'],
        'spectacles_lens' => 'both',
        'physical_condition' => 'hearing_condition',
    ]);
    $create->assertOk();

    $profile = MatrimonyProfile::where('user_id', $user->id)->firstOrFail();
    $response = $this->getJson('/api/v1/matrimony-profile');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('profile.id', $profile->id)
        ->assertJsonPath('profile.location_id', $location->id)
        ->assertJsonPath('profile.birth_city_id', $birthCity->id)
        ->assertJsonPath('profile.mother_tongue_id', $phase1A['mother_tongue_id'])
        ->assertJsonPath('profile.height_cm', 165)
        ->assertJsonPath('profile.weight_kg', 55)
        ->assertJsonPath('profile.complexion_id', $phase1A['complexion_id'])
        ->assertJsonPath('profile.blood_group_id', $phase1A['blood_group_id'])
        ->assertJsonPath('profile.physical_build_id', $phase1A['physical_build_id'])
        ->assertJsonPath('profile.spectacles_lens', 'both')
        ->assertJsonPath('profile.physical_condition', 'hearing_condition')
        ->assertJsonPath('display.version', 1)
        ->assertJsonStructure([
            'profile',
            'display' => [
                'version',
                'hero',
                'about',
                'chips',
                'sections',
                'actions',
                'contact',
            ],
        ]);

    $display = $response->json('display');
    expect($display['hero'])->toBeArray();
    expect($display['sections'])->toBeArray()->not->toBeEmpty();
    expect($response->json('profile.birth_place_label'))->toContain('Wakad');
    expect($response->json('profile.mother_tongue_label'))->toBeString();
    expect($response->json('profile.complexion_label'))->toBeString();
    expect($response->json('profile.blood_group_label'))->toBeString();
    expect($response->json('profile.physical_build_label'))->toBeString();
    $basicItems = collect($display['sections'])->firstWhere('key', 'basic')['items'] ?? [];
    expect(collect($basicItems)->pluck('label')->all())->toContain('Mother Tongue');
    expect(collect($basicItems)->pluck('label')->all())->toContain('Complexion');
    expect(collect($basicItems)->pluck('label')->all())->toContain('Physical Build');

    foreach ($display['sections'] as $section) {
        expect($section['items'])->toBeArray()->not->toBeEmpty();
        foreach ($section['items'] as $item) {
            expect($item['value'])->toBeString();
            expect((bool) preg_match('/^\s*[\{\[]|=>|\bcreated_at\b|\bupdated_at\b/i', $item['value']))->toBeFalse();
        }
    }

    $this->getJson('/api/v1/matrimony-profiles/'.$profile->id)
        ->assertNotFound();
});

test('MobileProfile GET api v1 matrimony profiles includes safe list card display payload', function () {
    [$viewerUser, , , $targetProfile] = mobileApiProfileActionPair();
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'profiles' => [
                '*' => [
                    'id',
                    'user_id',
                    'full_name',
                    'gender',
                    'date_of_birth',
                    'caste',
                    'highest_education',
                    'location_id',
                    'country_id',
                    'state_id',
                    'district_id',
                    'taluka_id',
                    'profile_photo',
                    'created_at',
                    'updated_at',
                    'display' => [
                        'card' => [
                            'name',
                            'age',
                            'age_label',
                            'height_label',
                            'community_label',
                            'education_label',
                            'occupation_label',
                            'location_label',
                            'verified',
                            'premium',
                            'photo_count',
                            'primary_photo_url',
                            'comparison_label',
                            'has_astro',
                        ],
                        'actions' => [
                            'can_send_interest',
                        ],
                    ],
                ],
            ],
        ]);

    $row = collect($response->json('profiles'))->firstWhere('id', $targetProfile->id);
    expect($row)->toBeArray();
    expect($row['display']['card']['name'])->toBe('Mobile Action Target');
    expect($row['display']['card'])->toHaveKeys([
        'age',
        'age_label',
        'primary_photo_url',
        'photo_count',
        'verified',
        'premium',
    ]);
    expect($row['display']['actions'])->toHaveKey('can_send_interest');

    $displayJson = json_encode($row['display'], JSON_THROW_ON_ERROR);
    expect($displayJson)->not->toContain('phone');
    expect($displayJson)->not->toContain('email');
    expect(mb_strtolower($displayJson))->not->toContain('whatsapp');
    expect(mb_strtolower($displayJson))->not->toContain('contact');
});

test('MobileProfile feed new suppresses recently opened profiles from immediate discovery', function () {
    [$viewerUser, $viewerProfile, , $recentlyViewedProfile] = mobileApiProfileActionPair();
    $unopenedUser = User::factory()->create(['name' => 'Unopened Feed Target']);
    $unopenedProfile = mobileApiCreateValidActionProfile($unopenedUser, 'Unopened Feed Target', 'female');

    $recentlyViewedProfile->forceFill(['updated_at' => now()->addMinute()])->saveQuietly();
    $unopenedProfile->forceFill(['updated_at' => now()->subDays(10)])->saveQuietly();
    ProfileView::query()->create([
        'viewer_profile_id' => $viewerProfile->id,
        'viewed_profile_id' => $recentlyViewedProfile->id,
    ]);

    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles?feed=new');

    $response->assertOk();
    $ids = collect($response->json('profiles'))->pluck('id')->all();
    expect($ids)->toContain($unopenedProfile->id);
    expect($ids)->not->toContain($recentlyViewedProfile->id);
});

test('MobileProfile feed new orders approved photo profiles before no-photo profiles', function () {
    $viewerUser = User::factory()->create(['name' => 'Photo First Viewer']);
    mobileApiCreateValidActionProfile($viewerUser, 'Photo First Viewer', 'male');
    $noPhotoUser = User::factory()->create(['name' => 'No Photo Feed Target']);
    $noPhotoProfile = mobileApiCreateValidActionProfile($noPhotoUser, 'No Photo Feed Target', 'female');
    $photoUser = User::factory()->create(['name' => 'Approved Photo Feed Target']);
    $photoProfile = mobileApiCreateValidActionProfile($photoUser, 'Approved Photo Feed Target', 'female');
    mobileApiAttachProfilePhoto($photoProfile);

    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles?feed=new');

    $response->assertOk();
    $ids = collect($response->json('profiles'))->pluck('id')->all();
    expect($ids)->toContain($photoProfile->id)
        ->and($ids)->toContain($noPhotoProfile->id)
        ->and(array_search($photoProfile->id, $ids, true))->toBeLessThan(array_search($noPhotoProfile->id, $ids, true));
});

test('MobileProfile feed new does not treat rejected photos as photo-first', function () {
    $viewerUser = User::factory()->create(['name' => 'Rejected Photo Viewer']);
    mobileApiCreateValidActionProfile($viewerUser, 'Rejected Photo Viewer', 'male');
    $approvedUser = User::factory()->create(['name' => 'Approved Photo Target']);
    $approvedProfile = mobileApiCreateValidActionProfile($approvedUser, 'Approved Photo Target', 'female');
    mobileApiAttachProfilePhoto($approvedProfile);
    $rejectedUser = User::factory()->create(['name' => 'Rejected Photo Target']);
    $rejectedProfile = mobileApiCreateValidActionProfile($rejectedUser, 'Rejected Photo Target', 'female');
    mobileApiAttachProfilePhoto($rejectedProfile, 'rejected');

    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles?feed=new');

    $response->assertOk();
    $ids = collect($response->json('profiles'))->pluck('id')->all();
    expect($ids)->toContain($approvedProfile->id)
        ->and($ids)->toContain($rejectedProfile->id)
        ->and(array_search($approvedProfile->id, $ids, true))->toBeLessThan(array_search($rejectedProfile->id, $ids, true));
});

test('MobileProfile feed tabs use backend matching feeds', function () {
    [$viewerUser, , , $targetProfile] = mobileApiProfileActionPair();
    Sanctum::actingAs($viewerUser);

    foreach (['daily', 'my_matches', 'nearby'] as $feed) {
        $response = $this->getJson('/api/v1/matrimony-profiles?feed='.$feed);

        $response->assertOk();
        $ids = collect($response->json('profiles'))->pluck('id')->all();
        expect($ids)->toContain($targetProfile->id);
    }
});

test('MobileProfile GET api v1 matrimony profiles shows only opposite gender member profiles', function () {
    $viewerUser = User::factory()->create(['name' => 'Gender Rule Viewer']);
    $viewerProfile = mobileApiCreateValidActionProfile($viewerUser, 'Gender Rule Viewer', 'male');
    $femaleUser = User::factory()->create(['name' => 'Visible Female Member']);
    $femaleProfile = mobileApiCreateValidActionProfile($femaleUser, 'Visible Female Member', 'female');
    $showcaseUser = User::factory()->create([
        'name' => 'Visible Showcase Female',
        'email' => 'visible-showcase-'.strtolower(Str::random(8)).'@system.local',
    ]);
    $showcaseProfile = mobileApiCreateValidActionProfile($showcaseUser, 'Visible Showcase Female', 'female');
    $showcaseProfile->forceFill(['is_showcase' => true])->saveQuietly();
    $draftShowcaseUser = User::factory()->create([
        'name' => 'Draft Showcase Female',
        'email' => 'draft-showcase-'.strtolower(Str::random(8)).'@system.local',
    ]);
    $draftShowcaseProfile = mobileApiCreateValidActionProfile($draftShowcaseUser, 'Draft Showcase Female', 'female');
    $draftShowcaseProfile->forceFill([
        'is_showcase' => true,
        'lifecycle_state' => 'draft',
    ])->saveQuietly();
    $missingGenderUser = User::factory()->create(['name' => 'Hidden Missing Gender Female']);
    $missingGenderProfile = MatrimonyProfile::factory()->create([
        'user_id' => $missingGenderUser->id,
        'full_name' => 'Hidden Missing Gender Female',
        'gender_id' => null,
        'location_id' => mobileApiProfileTestLeafLocation()->id,
        'lifecycle_state' => 'draft',
        'is_suspended' => false,
    ]);
    $missingGenderProfile->forceFill(['lifecycle_state' => 'active'])->save();
    $maleUser = User::factory()->create(['name' => 'Hidden Male Member']);
    $maleProfile = mobileApiCreateValidActionProfile($maleUser, 'Hidden Male Member', 'male');
    $adminUser = User::factory()->create([
        'name' => 'Hidden Admin Female',
        'is_admin' => true,
    ]);
    $adminProfile = mobileApiCreateValidActionProfile($adminUser, 'Hidden Admin Female', 'female');
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles');

    $response->assertOk();
    $ids = collect($response->json('profiles'))->pluck('id')->all();
    expect($ids)->toContain($femaleProfile->id);
    expect($ids)->toContain($showcaseProfile->id);
    expect($ids)->not->toContain($viewerProfile->id);
    expect($ids)->not->toContain($draftShowcaseProfile->id);
    expect($ids)->not->toContain($missingGenderProfile->id);
    expect($ids)->not->toContain($maleProfile->id);
    expect($ids)->not->toContain($adminProfile->id);
});

test('MobileProfile GET api v1 profile detail allows active opposite-gender showcase profile', function () {
    $viewerUser = User::factory()->create(['name' => 'Showcase Detail Viewer']);
    mobileApiCreateValidActionProfile($viewerUser, 'Showcase Detail Viewer', 'male');
    $showcaseUser = User::factory()->create([
        'name' => 'Showcase Detail Target',
        'email' => 'showcase-detail-'.strtolower(Str::random(8)).'@system.local',
    ]);
    $showcaseProfile = mobileApiCreateValidActionProfile($showcaseUser, 'Showcase Detail Target', 'female');
    $showcaseProfile->forceFill(['is_showcase' => true])->saveQuietly();
    Sanctum::actingAs($viewerUser);

    $this->getJson('/api/v1/matrimony-profiles/'.$showcaseProfile->id)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('profile.id', $showcaseProfile->id);
});

test('MobileProfile GET api v1 matrimony profiles shows male profiles for female viewer', function () {
    $viewerUser = User::factory()->create(['name' => 'Female Gender Rule Viewer']);
    $viewerProfile = mobileApiCreateValidActionProfile($viewerUser, 'Female Gender Rule Viewer', 'female');
    $maleUser = User::factory()->create(['name' => 'Visible Male Member']);
    $maleProfile = mobileApiCreateValidActionProfile($maleUser, 'Visible Male Member', 'male');
    $femaleUser = User::factory()->create(['name' => 'Hidden Female Member']);
    $femaleProfile = mobileApiCreateValidActionProfile($femaleUser, 'Hidden Female Member', 'female');
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles');

    $response->assertOk();
    $ids = collect($response->json('profiles'))->pluck('id')->all();
    expect($ids)->toContain($maleProfile->id);
    expect($ids)->not->toContain($viewerProfile->id);
    expect($ids)->not->toContain($femaleProfile->id);
});

test('MobileProfile discovery returns empty payloads when viewer profile gender is missing', function () {
    $viewerUser = User::factory()->create(['name' => 'Missing Gender Viewer']);
    $viewerProfile = MatrimonyProfile::factory()->create([
        'user_id' => $viewerUser->id,
        'full_name' => 'Missing Gender Viewer',
        'gender_id' => null,
        'location_id' => mobileApiProfileTestLeafLocation()->id,
        'lifecycle_state' => 'draft',
        'is_suspended' => false,
    ]);
    $viewerProfile->forceFill(['lifecycle_state' => 'active'])->save();
    $targetUser = User::factory()->create(['name' => 'Hidden Missing Gender Target']);
    mobileApiCreateValidActionProfile($targetUser, 'Hidden Missing Gender Target', 'female');
    Sanctum::actingAs($viewerUser);

    $listResponse = $this->getJson('/api/v1/matrimony-profiles');
    $listResponse->assertOk();
    expect($listResponse->json('profiles'))->toBe([]);

    $sectionsResponse = $this->getJson('/api/v1/matrimony-profiles/more-sections');
    $sectionsResponse
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('viewer_context.viewer_gender', null)
        ->assertJsonPath('viewer_context.target_gender', null);

    foreach ($sectionsResponse->json('sections') as $section) {
        expect($section['profiles'])->toBe([]);
    }
});

test('MobileProfile more sections endpoint requires authentication', function () {
    $this->getJson('/api/v1/matrimony-profiles/more-sections')
        ->assertUnauthorized();
});

test('MobileProfile GET api v1 profile detail records a profile view for visible target', function () {
    [$viewerUser, $viewerProfile, , $targetProfile] = mobileApiProfileActionPair();
    Sanctum::actingAs($viewerUser);

    $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id)
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(DB::table('profile_views')
        ->where('viewer_profile_id', $viewerProfile->id)
        ->where('viewed_profile_id', $targetProfile->id)
        ->count())->toBe(1);
});

test('MobileProfile GET api v1 profile detail does not record self views', function () {
    $user = User::factory()->create(['name' => 'Self Detail Viewer']);
    $profile = mobileApiCreateValidActionProfile($user, 'Self Detail Viewer', 'male');
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/matrimony-profiles/'.$profile->id)
        ->assertNotFound();

    expect(DB::table('profile_views')
        ->where('viewer_profile_id', $profile->id)
        ->where('viewed_profile_id', $profile->id)
        ->count())->toBe(0);
});

test('MobileProfile GET api v1 profile detail does not record blocked profile views', function () {
    [$viewerUser, $viewerProfile, , $targetProfile] = mobileApiProfileActionPair();
    Block::create([
        'blocker_profile_id' => $viewerProfile->id,
        'blocked_profile_id' => $targetProfile->id,
    ]);
    Sanctum::actingAs($viewerUser);

    $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id)
        ->assertNotFound();

    expect(DB::table('profile_views')
        ->where('viewer_profile_id', $viewerProfile->id)
        ->where('viewed_profile_id', $targetProfile->id)
        ->count())->toBe(0);
});

test('MobileProfile GET api v1 profile detail does not record invisible profile views', function () {
    [$viewerUser, $viewerProfile, , $targetProfile] = mobileApiProfileActionPair();
    $targetProfile->forceFill(['lifecycle_state' => 'draft'])->saveQuietly();
    Sanctum::actingAs($viewerUser);

    $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id)
        ->assertNotFound();

    expect(DB::table('profile_views')
        ->where('viewer_profile_id', $viewerProfile->id)
        ->where('viewed_profile_id', $targetProfile->id)
        ->count())->toBe(0);
});

test('MobileProfile GET api v1 profile detail hides same gender and admin profiles', function () {
    $viewerUser = User::factory()->create(['name' => 'Detail Rule Viewer']);
    $viewerProfile = mobileApiCreateValidActionProfile($viewerUser, 'Detail Rule Viewer', 'female');
    $sameGenderUser = User::factory()->create(['name' => 'Hidden Female Detail']);
    $sameGenderProfile = mobileApiCreateValidActionProfile($sameGenderUser, 'Hidden Female Detail', 'female');
    $adminUser = User::factory()->create([
        'name' => 'Hidden Admin Male Detail',
        'is_admin' => true,
    ]);
    $adminProfile = mobileApiCreateValidActionProfile($adminUser, 'Hidden Admin Male Detail', 'male');
    Sanctum::actingAs($viewerUser);

    $this->getJson('/api/v1/matrimony-profiles/'.$sameGenderProfile->id)
        ->assertNotFound();
    $this->getJson('/api/v1/matrimony-profiles/'.$adminProfile->id)
        ->assertNotFound();

    expect(DB::table('profile_views')
        ->where('viewer_profile_id', $viewerProfile->id)
        ->whereIn('viewed_profile_id', [$sameGenderProfile->id, $adminProfile->id])
        ->count())->toBe(0);
});

test('MobileProfile index includes mobile-created profile after approved primary photo activation', function () {
    mobileApiProfileTestSeedCurrentAddressType();
    $location = mobileApiProfileTestLeafLocation();
    [$religion, $caste, $subCaste] = mobileApiProfileTestCommunity();
    $targetGender = mobileApiProfileTestGender('female');

    $viewerUser = User::factory()->create(['name' => 'Mobile List Viewer']);
    mobileApiCreateValidActionProfile($viewerUser, 'Mobile List Viewer', 'male', $location);

    $targetUser = User::factory()->create(['name' => 'Mobile Created Target']);
    $targetProfile = app(MutationService::class)->createDraftProfileForUser($targetUser);
    app(MutationService::class)->applyManualSnapshot($targetProfile, [
        'core' => [
            'full_name' => 'Mobile Created Target',
            'gender_id' => $targetGender->id,
            'date_of_birth' => '1996-02-08',
            'highest_education' => 'M.A.',
            'location_id' => $location->id,
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
            'sub_caste_id' => $subCaste->id,
        ],
    ], (int) $targetUser->id, 'manual');

    $targetProfile->refresh();
    expect($targetProfile->lifecycle_state)->toBe('draft');

    $targetProfile->forceFill([
        'profile_photo' => 'approved-mobile-created.webp',
        'photo_approved' => true,
        'photo_rejected_at' => null,
        'photo_rejection_reason' => null,
        'is_suspended' => false,
    ])->saveQuietly();

    expect(app(MutationService::class)->activateDraftProfileAfterApprovedPrimaryPhoto($targetProfile))->toBeTrue();

    Sanctum::actingAs($viewerUser);
    $response = $this->getJson('/api/v1/matrimony-profiles');

    $response->assertOk();
    expect(collect($response->json('profiles'))->pluck('id')->all())->toContain($targetProfile->id);
});

test('MobileProfile more sections returns gender aware real sections and safe card rows', function () {
    $sharedLocation = mobileApiProfileTestLeafLocation();
    $viewerUser = User::factory()->create(['name' => 'Mobile Action Viewer']);
    $targetUser = User::factory()->create(['name' => 'Mobile Action Target']);
    $sameGenderUser = User::factory()->create(['name' => 'Mobile Same Gender Target']);
    $viewerProfile = mobileApiCreateValidActionProfile($viewerUser, 'Mobile Action Viewer', 'male', $sharedLocation);
    $targetProfile = mobileApiCreateValidActionProfile($targetUser, 'Mobile Action Target', 'female', $sharedLocation);
    $sameGenderProfile = mobileApiCreateValidActionProfile($sameGenderUser, 'Mobile Same Gender Target', 'male', $sharedLocation);
    mobileApiAddTargetPartnerPreferences($targetProfile, $viewerProfile);
    DB::table('profile_views')->insert([
        'viewer_profile_id' => $viewerProfile->id,
        'viewed_profile_id' => $targetProfile->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('profile_views')->insert([
        'viewer_profile_id' => $targetProfile->id,
        'viewed_profile_id' => $viewerProfile->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('profile_views')->insert([
        'viewer_profile_id' => $viewerProfile->id,
        'viewed_profile_id' => $sameGenderProfile->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('profile_views')->insert([
        'viewer_profile_id' => $sameGenderProfile->id,
        'viewed_profile_id' => $viewerProfile->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/more-sections');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('viewer_context.viewer_gender', 'male')
        ->assertJsonPath('viewer_context.target_gender', 'female')
        ->assertJsonPath('viewer_context.target_singular_en', 'Bride')
        ->assertJsonPath('viewer_context.target_plural_en', 'Brides')
        ->assertJsonPath('viewer_context.target_plural_mr', 'वधू');

    $sections = collect($response->json('sections'));
    expect($sections->pluck('key')->all())->toBe([
        'looking_for_me',
        'recently_viewed',
        'matching_my_preference',
        'nearby',
        'recent_visitors',
        'you_may_like',
    ]);
    foreach ($sections as $section) {
        $profileIds = collect($section['profiles'])->pluck('id')->all();
        expect($profileIds)->not->toContain($viewerProfile->id);
        expect($profileIds)->not->toContain($sameGenderProfile->id);
    }

    $lookingForMe = $sections->firstWhere('key', 'looking_for_me');
    expect($lookingForMe['title_en'])->toBe('Brides looking for me');
    expect($lookingForMe['title_mr'])->toBe('माझ्या शोधात असलेल्या वधू');
    expect(collect($lookingForMe['profiles'])->pluck('id')->all())->toContain($targetProfile->id);

    $row = collect($lookingForMe['profiles'])->firstWhere('id', $targetProfile->id);
    expect($row)->toBeArray();
    expect($row['display']['card'])->toHaveKeys([
        'name',
        'age',
        'age_label',
        'primary_photo_url',
        'photo_count',
        'verified',
        'premium',
    ]);
    expect($row['display']['actions'])->toHaveKey('can_send_interest');

    $recentlyViewed = $sections->firstWhere('key', 'recently_viewed');
    expect(collect($recentlyViewed['profiles'])->pluck('id')->all())->toContain($targetProfile->id);

    $nearby = $sections->firstWhere('key', 'nearby');
    expect($nearby['title_en'])->toBe('Nearby Brides');
    expect($nearby['title_mr'])->toBe('जवळच्या वधू');
    expect($nearby['subtitle_en'])->toBe('Profiles closer to your location');
    expect($nearby['subtitle_mr'])->toBe('तुमच्या ठिकाणाजवळील स्थळे');
    expect(collect($nearby['profiles'])->pluck('id')->all())->toContain($targetProfile->id);

    $nearbyRow = collect($nearby['profiles'])->firstWhere('id', $targetProfile->id);
    expect($nearbyRow)->toBeArray();
    expect($nearbyRow['display']['card'])->toHaveKeys([
        'name',
        'age',
        'age_label',
        'primary_photo_url',
        'photo_count',
        'verified',
        'premium',
    ]);
    expect($nearbyRow['display']['actions'])->toHaveKey('can_send_interest');

    $nearbyJson = json_encode($nearby, JSON_THROW_ON_ERROR);
    expect($nearbyJson)->not->toContain('phone');
    expect($nearbyJson)->not->toContain('email');
    expect(mb_strtolower($nearbyJson))->not->toContain('whatsapp');
    expect(mb_strtolower($nearbyJson))->not->toContain('contact');

    $payloadJson = json_encode($response->json(), JSON_THROW_ON_ERROR);
    expect($payloadJson)->not->toContain('phone');
    expect($payloadJson)->not->toContain('email');
    expect(mb_strtolower($payloadJson))->not->toContain('whatsapp');
    expect(mb_strtolower($payloadJson))->not->toContain('contact');
    expect(mb_strtolower($payloadJson))->not->toContain('contact_unlock');
});

test('MobileProfile more sections labels female viewer target as groom', function () {
    $viewerUser = User::factory()->create(['name' => 'Female More Sections Viewer']);
    $targetUser = User::factory()->create(['name' => 'Male More Sections Target']);
    mobileApiCreateValidActionProfile($viewerUser, 'Female More Sections Viewer', 'female');
    mobileApiCreateValidActionProfile($targetUser, 'Male More Sections Target', 'male');
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/more-sections');

    $response
        ->assertOk()
        ->assertJsonPath('viewer_context.viewer_gender', 'female')
        ->assertJsonPath('viewer_context.target_gender', 'male')
        ->assertJsonPath('viewer_context.target_singular_en', 'Groom')
        ->assertJsonPath('viewer_context.target_plural_en', 'Grooms')
        ->assertJsonPath('viewer_context.target_plural_mr', 'वर');

    $sections = collect($response->json('sections'));
    expect($sections->firstWhere('key', 'looking_for_me')['title_en'])->toBe('Grooms looking for me');
    expect($sections->firstWhere('key', 'nearby')['title_en'])->toBe('Nearby Grooms');
    expect($sections->firstWhere('key', 'nearby')['title_mr'])->toBe('जवळचे वर');
    expect($sections->firstWhere('key', 'you_may_like')['title_mr'])->toBe('तुम्हाला आवडू शकणाऱ्या वर');
});

test('MobileProfile more sections locked recent visitors does not leak visitor identity', function () {
    $ownerUser = User::factory()->create(['name' => 'Locked Owner']);
    $visitorUser = User::factory()->create(['name' => 'Locked Recent Visitor User']);
    $ownerProfile = mobileApiCreateValidActionProfile($ownerUser, 'Locked Owner Profile', 'male');
    $visitorProfile = mobileApiCreateValidActionProfile($visitorUser, 'Locked Recent Visitor Profile', 'female');
    DB::table('profile_views')->insert([
        'viewer_profile_id' => $visitorProfile->id,
        'viewed_profile_id' => $ownerProfile->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Sanctum::actingAs($ownerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/more-sections');

    $response->assertOk();
    $recentVisitors = collect($response->json('sections'))->firstWhere('key', 'recent_visitors');
    foreach (['locked', 'requires_upgrade', 'teaser_count', 'profiles', 'teasers', 'rows'] as $key) {
        expect($recentVisitors)->toHaveKey($key);
    }
    expect($recentVisitors['locked'])->toBeTrue();
    expect($recentVisitors['requires_upgrade'])->toBeTrue();
    expect($recentVisitors['teaser_count'])->toBeGreaterThanOrEqual(1);
    expect($recentVisitors['profiles'])->toBe([]);
    expect($recentVisitors['teasers'])->toBeArray()->not->toBeEmpty();
    expect($recentVisitors['rows'])->toBeArray()->not->toBeEmpty();

    $teaser = $recentVisitors['teasers'][0];
    expect($teaser)->toBeArray();
    expect($teaser)->toHaveKey('headline');
    expect($teaser)->toHaveKey('viewed_summary');
    expect($teaser)->toHaveKey('avatar_style');
    expect($recentVisitors['rows'][0]['mode'])->toBe('teaser');
    expect($recentVisitors['rows'][0]['teaser'])->toBeArray();
    expect(collect($recentVisitors['rows'])->pluck('mode')->all())->toContain('teaser');
    expect(collect($recentVisitors['rows'])->pluck('mode')->all())->not->toContain('profile');

    $forbiddenTeaserKeys = [
        'id',
        'profile_id',
        'viewer_profile_id',
        'user_id',
        'display',
        'actions',
        'contact',
        'phone',
        'email',
        'whatsapp',
        'paid_contact',
        'primary_cta',
    ];
    $teaserKeys = mobileApiRecursivePayloadKeys($teaser);
    foreach ($forbiddenTeaserKeys as $key) {
        expect($teaserKeys)->not->toContain($key);
    }

    $recentVisitorsJson = json_encode($recentVisitors, JSON_THROW_ON_ERROR);
    expect($recentVisitorsJson)->not->toContain('Locked Recent Visitor Profile');
    expect($recentVisitorsJson)->not->toContain('phone');
    expect($recentVisitorsJson)->not->toContain('email');
    expect(mb_strtolower($recentVisitorsJson))->not->toContain('whatsapp');
});

test('MobileProfile more sections full recent visitors access returns safe profile rows', function () {
    $ownerUser = User::factory()->create(['name' => 'Full Access Owner']);
    $visitorUser = User::factory()->create(['name' => 'Full Access Visitor User']);
    $ownerProfile = mobileApiCreateValidActionProfile($ownerUser, 'Full Access Owner Profile', 'male');
    $visitorProfile = mobileApiCreateValidActionProfile($visitorUser, 'Full Access Visitor Profile', 'female');
    DB::table('profile_views')->insert([
        'viewer_profile_id' => $visitorProfile->id,
        'viewed_profile_id' => $ownerProfile->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->mock(FeatureUsageService::class, function ($mock): void {
        $mock->shouldReceive('canUse')
            ->withAnyArgs()
            ->andReturnUsing(fn (int $userId, string $featureKey): bool => $featureKey === FeatureUsageService::FEATURE_WHO_VIEWED_ME_ACCESS);
        $mock->shouldReceive('whoViewedMeHasFullViewerList')
            ->withAnyArgs()
            ->andReturn(true);
        $mock->shouldReceive('whoViewedMePreviewWindow')
            ->withAnyArgs()
            ->andReturn(['since' => null, 'window_days' => null, 'uses_month_copy' => false]);
    });

    Sanctum::actingAs($ownerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/more-sections');

    $response->assertOk();
    $recentVisitors = collect($response->json('sections'))->firstWhere('key', 'recent_visitors');
    expect($recentVisitors['locked'])->toBeFalse();
    expect($recentVisitors['requires_upgrade'])->toBeFalse();
    expect($recentVisitors['teasers'])->toBe([]);
    expect(collect($recentVisitors['profiles'])->pluck('id')->all())->toContain($visitorProfile->id);

    $row = collect($recentVisitors['rows'])->firstWhere('mode', 'profile');
    expect($row)->toBeArray();
    expect($row['profile']['id'])->toBe($visitorProfile->id);
    expect($row['profile']['display']['card'])->toHaveKey('name');
    expect($row['profile']['display']['actions'])->toHaveKey('can_send_interest');

    $payloadJson = json_encode($recentVisitors, JSON_THROW_ON_ERROR);
    expect($payloadJson)->not->toContain('phone');
    expect($payloadJson)->not->toContain('email');
    expect(mb_strtolower($payloadJson))->not->toContain('whatsapp');
});

test('MobileProfile display contact keeps own profile unlock disabled', function () {
    $user = User::factory()->create(['name' => 'Own Contact Account']);
    $profile = mobileApiCreateValidActionProfile($user, 'Own Contact Candidate', 'male');
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/matrimony-profile');

    $response
        ->assertOk()
        ->assertJsonPath('profile.id', $profile->id)
        ->assertJsonPath('display.contact.enabled', false)
        ->assertJsonPath('display.contact.state', 'unavailable')
        ->assertJsonPath('display.contact.phone', null)
        ->assertJsonPath('display.contact.email', null)
        ->assertJsonPath('display.contact.primary_cta', null)
        ->assertJsonPath('display.contact.whatsapp_response.visible', false);
});

test('MobileProfile display contact exposes safe shape for other profile without leaking contact', function () {
    [$viewerUser, , , $targetProfile] = mobileApiProfileActionPair();
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $response
        ->assertOk()
        ->assertJsonStructure([
            'display' => [
                'contact' => [
                    'enabled',
                    'title',
                    'state',
                    'message',
                    'phone',
                    'email',
                    'primary_cta',
                    'whatsapp_response' => [
                        'visible',
                        'label',
                        'message',
                        'enabled',
                    ],
                ],
            ],
        ])
        ->assertJsonPath('display.contact.title', 'Contact Information')
        ->assertJsonPath('display.contact.phone', null)
        ->assertJsonPath('display.contact.email', null)
        ->assertJsonPath('display.contact.whatsapp_response.label', 'WhatsApp Response');

    expect($response->json('display.contact.state'))->toBeIn([
        'locked',
        'unlock_available',
        'upgrade_required',
        'whatsapp_response_available',
        'unavailable',
    ]);
});

test('MobileProfile display contact reveals phone only from ContactAccessService context', function () {
    [$viewerUser, , , $targetProfile] = mobileApiProfileActionPair();
    Sanctum::actingAs($viewerUser);

    $this->app->instance(ContactAccessService::class, new class
    {
        public function resolveViewerContext(...$args): array
        {
            return [
                'paid_contact_phone' => '9876543210',
                'paid_contact_email' => 'candidate@example.test',
                'show_mediator_cta' => false,
                'needs_upgrade' => false,
                'show_paid_reveal_button' => false,
                'blocked' => false,
                'reveal_blocked_reason' => null,
                'paid_reveal_blocked_pending_matchmaking' => false,
            ];
        }
    });

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $response
        ->assertOk()
        ->assertJsonPath('display.contact.state', 'revealed')
        ->assertJsonPath('display.contact.phone', '9876543210')
        ->assertJsonPath('display.contact.email', 'candidate@example.test')
        ->assertJsonPath('display.contact.primary_cta', null);
});

test('MobileProfile display contact maps upgrade and WhatsApp Response without revealing phone', function () {
    [$viewerUser, , , $targetProfile] = mobileApiProfileActionPair();
    Sanctum::actingAs($viewerUser);

    $this->app->instance(ContactAccessService::class, new class
    {
        public function resolveViewerContext(...$args): array
        {
            return [
                'paid_contact_phone' => null,
                'paid_contact_email' => null,
                'show_mediator_cta' => true,
                'needs_upgrade' => true,
                'show_paid_reveal_button' => false,
                'blocked' => true,
                'reveal_blocked_reason' => null,
                'paid_reveal_blocked_pending_matchmaking' => false,
            ];
        }
    });

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $response
        ->assertOk()
        ->assertJsonPath('display.contact.state', 'upgrade_required')
        ->assertJsonPath('display.contact.phone', null)
        ->assertJsonPath('display.contact.email', null)
        ->assertJsonPath('display.contact.primary_cta.label', 'Upgrade to View Contact')
        ->assertJsonPath('display.contact.primary_cta.action', 'upgrade')
        ->assertJsonPath('display.contact.primary_cta.enabled', false)
        ->assertJsonPath('display.contact.whatsapp_response.visible', true)
        ->assertJsonPath('display.contact.whatsapp_response.enabled', false);
});

test('MobileProfile GET api v1 profile detail returns clean comparison payload for target preferences', function () {
    $viewerUser = User::factory()->create(['name' => 'Comparison Viewer']);
    $targetUser = User::factory()->create(['name' => 'Comparison Target']);
    $viewerProfile = mobileApiCreateValidActionProfile($viewerUser, 'Comparison Viewer', 'male');
    $targetProfile = mobileApiCreateValidActionProfile($targetUser, 'Comparison Target', 'female');
    mobileApiAddTargetPartnerPreferences($targetProfile, $viewerProfile);
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('display.comparison.title', 'You & Her')
        ->assertJsonStructure([
            'profile',
            'display' => [
                'hero',
                'sections',
                'share',
                'comparison' => [
                    'enabled',
                    'title',
                    'summary',
                    'viewer' => ['name', 'photo_url'],
                    'target' => ['name', 'photo_url'],
                    'matched_count',
                    'total_count',
                    'rows',
                    'items',
                ],
            ],
        ]);

    $comparison = $response->json('display.comparison');
    expect($comparison['enabled'])->toBeTrue();
    expect($comparison['viewer']['name'])->toBe('You');
    expect($comparison['target']['name'])->toBe('Comparison Target');
    expect($comparison['matched_count'])->toBeGreaterThanOrEqual(1);
    expect($comparison['total_count'])->toBeGreaterThanOrEqual($comparison['matched_count']);
    expect($comparison['summary'])->toBe($comparison['matched_count'].' जुळणारे मुद्दे');
    expect($comparison['rows'])->toBeArray()->not->toBeEmpty();
    expect($comparison['items'])->toBeArray()->not->toBeEmpty();

    foreach ($comparison['rows'] as $row) {
        expect($row['key'])->toBeString();
        expect($row['label'])->toBeString();
        expect($row['status'])->toBeString();
        expect($row['status_label'])->toBeString();
        expect($row['target_value'])->toBeString();
        expect($row['viewer_value'])->toBeString();
        expect($row['is_counted'])->toBeBool();
        expect(in_array($row['status'], ['strong', 'match', 'near', 'neutral'], true))->toBeTrue();
        expect((bool) preg_match('/^\s*[\{\[]|=>|\bcreated_at\b|\bupdated_at\b/i', $row['target_value']))->toBeFalse();
        expect((bool) preg_match('/^\s*[\{\[]|=>|\bcreated_at\b|\bupdated_at\b/i', $row['viewer_value']))->toBeFalse();
        expect($row['target_value'])->not->toBe('0 years');
        expect($row['viewer_value'])->not->toBe('0 years');
    }

    $partnerMatchSection = collect($response->json('display.sections'))->firstWhere('key', 'partner_match');
    expect($partnerMatchSection)->not->toBeNull();
    expect($partnerMatchSection['title'])->toBe('You & Her');
});

test('MobileProfile GET api v1 profile detail returns basic comparison without target preferences', function () {
    [$viewerUser, , , $targetProfile] = mobileApiProfileActionPair();
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('display.comparison.enabled', true)
        ->assertJsonStructure([
            'profile',
            'display' => [
                'hero',
                'sections',
                'share',
                'actions',
                'comparison' => [
                    'title',
                    'summary',
                    'viewer',
                    'target',
                    'rows',
                ],
            ],
        ]);

    $comparison = $response->json('display.comparison');
    $keys = collect($comparison['rows'])->pluck('key')->all();

    expect($comparison['title'])->toBe('You & Her');
    expect($keys)->toContain('age')->toContain('location')->toContain('community');
    expect(collect($comparison['rows'])->where('key', 'location')->first()['status'])->toBe('neutral');
    expect(collect($response->json('display.sections'))->firstWhere('key', 'partner_match'))->not->toBeNull();
});

test('MobileProfile comparison marks same taluka location as strong', function () {
    $chain = mobileApiProfileTestLocationChain();
    $targetLeaf = mobileApiProfileTestLocationNode('village', 'Pimple Saudagar', $chain['taluka']);
    [$viewerUser, , , $targetProfile] = mobileApiCreateComparisonProfilesAt($chain['leaf'], $targetLeaf);
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $row = mobileApiLocationComparisonRow($response->json('display.comparison'));
    expect($row['status'])->toBe('strong');
    expect($row['status_label'])->toBe('Strong');
    expect($row['is_counted'])->toBeTrue();
});

test('MobileProfile comparison marks nearby taluka location as strong using lat lng', function () {
    $base = mobileApiProfileTestLocationChain(
        talukaExtra: ['lat' => 18.5912, 'lng' => 73.7400]
    );
    $targetTaluka = mobileApiProfileTestLocationNode(
        'taluka',
        'Mulshi',
        $base['district'],
        ['lat' => 18.5990, 'lng' => 73.7520]
    );
    $targetLeaf = mobileApiProfileTestLocationNode('village', 'Hinjewadi', $targetTaluka);
    [$viewerUser, , , $targetProfile] = mobileApiCreateComparisonProfilesAt($base['leaf'], $targetLeaf);
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $row = mobileApiLocationComparisonRow($response->json('display.comparison'));
    expect($row['status'])->toBe('strong');
    expect($row['status_label'])->toBe('Strong');
    expect($row['is_counted'])->toBeTrue();
});

test('MobileProfile comparison marks same district different taluka as match', function () {
    $base = mobileApiProfileTestLocationChain();
    $targetTaluka = mobileApiProfileTestLocationNode('taluka', 'Maval', $base['district']);
    $targetLeaf = mobileApiProfileTestLocationNode('village', 'Talegaon', $targetTaluka);
    [$viewerUser, , , $targetProfile] = mobileApiCreateComparisonProfilesAt($base['leaf'], $targetLeaf);
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $row = mobileApiLocationComparisonRow($response->json('display.comparison'));
    expect($row['status'])->toBe('match');
    expect($row['status_label'])->toBe('Match');
    expect($row['is_counted'])->toBeTrue();
});

test('MobileProfile comparison marks nearby district location as near using lat lng', function () {
    $country = mobileApiProfileTestLocationNode('country', 'India');
    $state = mobileApiProfileTestLocationNode('state', 'Maharashtra', $country);
    $viewerDistrict = mobileApiProfileTestLocationNode('district', 'Pune', $state, ['lat' => 18.5204, 'lng' => 73.8567]);
    $targetDistrict = mobileApiProfileTestLocationNode('district', 'Satara', $state, ['lat' => 18.6100, 'lng' => 73.9100]);
    $viewerTaluka = mobileApiProfileTestLocationNode('taluka', 'Haveli', $viewerDistrict);
    $targetTaluka = mobileApiProfileTestLocationNode('taluka', 'Wai', $targetDistrict);
    $viewerLeaf = mobileApiProfileTestLocationNode('village', 'Wakad', $viewerTaluka);
    $targetLeaf = mobileApiProfileTestLocationNode('village', 'Wai City', $targetTaluka);
    [$viewerUser, , , $targetProfile] = mobileApiCreateComparisonProfilesAt($viewerLeaf, $targetLeaf);
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $row = mobileApiLocationComparisonRow($response->json('display.comparison'));
    expect($row['status'])->toBe('near');
    expect($row['status_label'])->toBe('Near');
    expect($row['is_counted'])->toBeTrue();
});

test('MobileProfile comparison keeps same state only location neutral and uncounted', function () {
    $country = mobileApiProfileTestLocationNode('country', 'India');
    $state = mobileApiProfileTestLocationNode('state', 'Maharashtra', $country);
    $viewerDistrict = mobileApiProfileTestLocationNode('district', 'Pune', $state, ['lat' => 18.5204, 'lng' => 73.8567]);
    $targetDistrict = mobileApiProfileTestLocationNode('district', 'Nagpur', $state, ['lat' => 21.1458, 'lng' => 79.0882]);
    $viewerTaluka = mobileApiProfileTestLocationNode('taluka', 'Haveli', $viewerDistrict);
    $targetTaluka = mobileApiProfileTestLocationNode('taluka', 'Nagpur Rural', $targetDistrict);
    $viewerLeaf = mobileApiProfileTestLocationNode('village', 'Wakad', $viewerTaluka);
    $targetLeaf = mobileApiProfileTestLocationNode('village', 'Nagpur City', $targetTaluka);
    [$viewerUser, , , $targetProfile] = mobileApiCreateComparisonProfilesAt($viewerLeaf, $targetLeaf);
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $row = mobileApiLocationComparisonRow($response->json('display.comparison'));
    expect($row['status'])->toBe('neutral');
    expect($row['status_label'])->toBe('Basic');
    expect($row['is_counted'])->toBeFalse();
});

test('MobileProfile comparison does not treat pincode without lat lng as nearby', function () {
    $country = mobileApiProfileTestLocationNode('country', 'India');
    $state = mobileApiProfileTestLocationNode('state', 'Maharashtra', $country);
    $viewerDistrict = mobileApiProfileTestLocationNode('district', 'Pune', $state, ['pincode' => '411001']);
    $targetDistrict = mobileApiProfileTestLocationNode('district', 'Raigad', $state, ['pincode' => '411001']);
    $viewerTaluka = mobileApiProfileTestLocationNode('taluka', 'Haveli', $viewerDistrict, ['pincode' => '411057']);
    $targetTaluka = mobileApiProfileTestLocationNode('taluka', 'Panvel', $targetDistrict, ['pincode' => '411057']);
    $viewerLeaf = mobileApiProfileTestLocationNode('village', 'Wakad', $viewerTaluka, ['pincode' => '411057']);
    $targetLeaf = mobileApiProfileTestLocationNode('village', 'Panvel City', $targetTaluka, ['pincode' => '411057']);
    [$viewerUser, , , $targetProfile] = mobileApiCreateComparisonProfilesAt($viewerLeaf, $targetLeaf);
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $row = mobileApiLocationComparisonRow($response->json('display.comparison'));
    expect($row['status'])->toBe('neutral');
    expect($row['status_label'])->toBe('Basic');
    expect($row['is_counted'])->toBeFalse();
});

test('MobileProfile GET api v1 profile detail labels male target comparison as You and Him', function () {
    $viewerUser = User::factory()->create(['name' => 'Male Label Viewer']);
    $targetUser = User::factory()->create(['name' => 'Male Label Target']);
    mobileApiCreateValidActionProfile($viewerUser, 'Male Label Viewer', 'female');
    $targetProfile = mobileApiCreateValidActionProfile($targetUser, 'Male Label Target', 'male');
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $response
        ->assertOk()
        ->assertJsonPath('display.comparison.title', 'You & Him');
});

test('MobileProfile presenter keeps comparison null when viewer has no profile', function () {
    [, $viewerProfile, , $targetProfile] = mobileApiProfileActionPair();
    mobileApiAddTargetPartnerPreferences($targetProfile, $viewerProfile);

    $viewerWithoutProfile = User::factory()->create(['name' => 'No Profile Viewer']);
    $display = app(MobileProfileDisplayPresenter::class)->forProfile($targetProfile, $viewerWithoutProfile);

    expect($display['comparison'])->toBeNull();
});

test('MobileProfile comparison shows same sub caste as counted match', function () {
    [$religion, $caste, $subCaste] = mobileApiProfileTestCommunity();
    $viewerUser = User::factory()->create(['name' => 'Sub Caste Viewer']);
    $targetUser = User::factory()->create(['name' => 'Sub Caste Target']);
    $viewerProfile = mobileApiCreateValidActionProfile($viewerUser, 'Sub Caste Viewer', 'male', null, [
        'religion_id' => $religion->id,
        'caste_id' => $caste->id,
        'sub_caste_id' => $subCaste->id,
    ]);
    $targetProfile = mobileApiCreateValidActionProfile($targetUser, 'Sub Caste Target', 'female', null, [
        'religion_id' => $religion->id,
        'caste_id' => $caste->id,
        'sub_caste_id' => $subCaste->id,
    ]);
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $row = mobileApiComparisonRow($response->json('display.comparison'), 'same_sub_caste');
    expect($row)->toBeArray();
    expect($row['status'])->toBe('match');
    expect($row['status_label'])->toBe('Match');
    expect($row['is_counted'])->toBeTrue();
    expect($row['viewer_value'])->toBe($row['target_value']);
});

test('MobileProfile comparison hides sub caste row when sub caste is missing or different', function () {
    [$viewerUser, , , $targetProfile] = mobileApiProfileActionPair();
    $targetProfile->forceFill(['sub_caste_id' => null])->save();
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    expect(mobileApiComparisonRow($response->json('display.comparison'), 'same_sub_caste'))->toBeNull();
});

test('MobileProfile comparison marks same education degree as match', function () {
    $degree = mobileApiProfileTestEducationDegree('B.A.', 50);
    $viewerUser = User::factory()->create(['name' => 'Education Viewer']);
    $targetUser = User::factory()->create(['name' => 'Education Target']);
    mobileApiCreateValidActionProfile($viewerUser, 'Education Viewer', 'male', null, [
        'highest_education' => $degree->code,
    ]);
    $targetProfile = mobileApiCreateValidActionProfile($targetUser, 'Education Target', 'female', null, [
        'highest_education' => $degree->code,
    ]);
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $row = mobileApiComparisonRow($response->json('display.comparison'), 'education');
    expect($row)->toBeArray();
    expect($row['status'])->toBe('match');
    expect($row['status_label'])->toBe('Match');
    expect($row['is_counted'])->toBeTrue();
});

test('MobileProfile comparison marks nearby education degree sort order as near', function () {
    $viewerDegree = mobileApiProfileTestEducationDegree('B.Com.', 60);
    $targetDegree = mobileApiProfileTestEducationDegree('B.Sc.', 61);
    $viewerUser = User::factory()->create(['name' => 'Near Education Viewer']);
    $targetUser = User::factory()->create(['name' => 'Near Education Target']);
    mobileApiCreateValidActionProfile($viewerUser, 'Near Education Viewer', 'male', null, [
        'highest_education' => $viewerDegree->code,
    ]);
    $targetProfile = mobileApiCreateValidActionProfile($targetUser, 'Near Education Target', 'female', null, [
        'highest_education' => $targetDegree->code,
    ]);
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $row = mobileApiComparisonRow($response->json('display.comparison'), 'education');
    expect($row)->toBeArray();
    expect($row['status'])->toBe('near');
    expect($row['status_label'])->toBe('Near');
    expect($row['is_counted'])->toBeTrue();
});

test('MobileProfile comparison hides far education degree row', function () {
    $viewerDegree = mobileApiProfileTestEducationDegree('SSC', 10);
    $targetDegree = mobileApiProfileTestEducationDegree('MBA', 90);
    $viewerUser = User::factory()->create(['name' => 'Far Education Viewer']);
    $targetUser = User::factory()->create(['name' => 'Far Education Target']);
    mobileApiCreateValidActionProfile($viewerUser, 'Far Education Viewer', 'male', null, [
        'highest_education' => $viewerDegree->code,
    ]);
    $targetProfile = mobileApiCreateValidActionProfile($targetUser, 'Far Education Target', 'female', null, [
        'highest_education' => $targetDegree->code,
    ]);
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    expect(mobileApiComparisonRow($response->json('display.comparison'), 'education'))->toBeNull();
});

test('MobileProfile comparison shows income when viewer income is within target expectation', function () {
    $viewerUser = User::factory()->create(['name' => 'Income Viewer']);
    $targetUser = User::factory()->create(['name' => 'Income Target']);
    mobileApiCreateValidActionProfile($viewerUser, 'Income Viewer', 'male', null, [
        'annual_income' => 800000,
        'income_private' => false,
    ]);
    $targetProfile = mobileApiCreateValidActionProfile($targetUser, 'Income Target', 'female');
    DB::table('profile_preference_criteria')->updateOrInsert(
        ['profile_id' => $targetProfile->id],
        [
            'preferred_income_min' => 700000,
            'preferred_income_max' => 900000,
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $row = mobileApiComparisonRow($response->json('display.comparison'), 'income');
    expect($row)->toBeArray();
    expect($row['status'])->toBe('match');
    expect($row['is_counted'])->toBeTrue();
    expect($row['viewer_value'])->toBe('₹8 L');
});

test('MobileProfile comparison hides income when viewer income is outside target expectation', function () {
    $viewerUser = User::factory()->create(['name' => 'Outside Income Viewer']);
    $targetUser = User::factory()->create(['name' => 'Outside Income Target']);
    mobileApiCreateValidActionProfile($viewerUser, 'Outside Income Viewer', 'male', null, [
        'annual_income' => 500000,
        'income_private' => false,
    ]);
    $targetProfile = mobileApiCreateValidActionProfile($targetUser, 'Outside Income Target', 'female');
    DB::table('profile_preference_criteria')->updateOrInsert(
        ['profile_id' => $targetProfile->id],
        [
            'preferred_income_min' => 700000,
            'preferred_income_max' => 900000,
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    expect(mobileApiComparisonRow($response->json('display.comparison'), 'income'))->toBeNull();
});

test('MobileProfile comparison shows gunamilan when available points are above threshold', function () {
    $this->app->instance(GunamilanService::class, new class
    {
        public function calculate(): array
        {
            return [
                'available' => true,
                'total_points' => 24.0,
                'max_points' => 36.0,
            ];
        }
    });
    [$viewerUser, , , $targetProfile] = mobileApiProfileActionPair();
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $row = mobileApiComparisonRow($response->json('display.comparison'), 'gunamilan');
    expect($row)->toBeArray();
    expect($row['status'])->toBe('match');
    expect($row['viewer_value'])->toBe('24/36');
    expect($row['target_value'])->toBe('Compatible');
    expect($row['is_counted'])->toBeTrue();
});

test('MobileProfile comparison hides gunamilan when unavailable or below threshold', function () {
    $this->app->instance(GunamilanService::class, new class
    {
        public function calculate(): array
        {
            return [
                'available' => true,
                'total_points' => 18.0,
                'max_points' => 36.0,
            ];
        }
    });
    [$viewerUser, , , $targetProfile] = mobileApiProfileActionPair();
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    expect(mobileApiComparisonRow($response->json('display.comparison'), 'gunamilan'))->toBeNull();
});

test('MobileProfile comparison hides gunamilan when service fails without breaking API', function () {
    $this->app->instance(GunamilanService::class, new class
    {
        public function calculate(): array
        {
            throw new RuntimeException('Gunamilan fixture failure');
        }
    });
    [$viewerUser, , , $targetProfile] = mobileApiProfileActionPair();
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $response->assertOk();
    expect(mobileApiComparisonRow($response->json('display.comparison'), 'gunamilan'))->toBeNull();
});

test('MobileProfile POST api v1 profile action can shortlist safely', function () {
    [$viewerUser, $viewerProfile, , $targetProfile] = mobileApiProfileActionPair();
    Sanctum::actingAs($viewerUser);

    $response = $this->postJson('/api/v1/matrimony-profiles/'.$targetProfile->id.'/shortlist');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('state.shortlisted', true)
        ->assertJsonPath('state.hidden', false)
        ->assertJsonPath('state.blocked', false);

    expect(Shortlist::query()
        ->where('owner_profile_id', $viewerProfile->id)
        ->where('shortlisted_profile_id', $targetProfile->id)
        ->count())->toBe(1);

    $duplicate = $this->postJson('/api/v1/matrimony-profiles/'.$targetProfile->id.'/shortlist');

    $duplicate
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('state.shortlisted', true);

    expect(Shortlist::query()
        ->where('owner_profile_id', $viewerProfile->id)
        ->where('shortlisted_profile_id', $targetProfile->id)
        ->count())->toBe(1);
});

test('MobileProfile DELETE api v1 profile action can unshortlist safely', function () {
    [$viewerUser, $viewerProfile, , $targetProfile] = mobileApiProfileActionPair();
    Sanctum::actingAs($viewerUser);

    Shortlist::query()->create([
        'owner_profile_id' => $viewerProfile->id,
        'shortlisted_profile_id' => $targetProfile->id,
    ]);

    $response = $this->deleteJson('/api/v1/matrimony-profiles/'.$targetProfile->id.'/shortlist');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('state.shortlisted', false);

    expect(Shortlist::query()
        ->where('owner_profile_id', $viewerProfile->id)
        ->where('shortlisted_profile_id', $targetProfile->id)
        ->exists())->toBeFalse();
});

test('MobileProfile POST api v1 profile action can hide safely', function () {
    [$viewerUser, $viewerProfile, , $targetProfile] = mobileApiProfileActionPair();
    Sanctum::actingAs($viewerUser);

    $response = $this->postJson('/api/v1/matrimony-profiles/'.$targetProfile->id.'/hide');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('state.hidden', true);

    $duplicate = $this->postJson('/api/v1/matrimony-profiles/'.$targetProfile->id.'/hide');

    $duplicate
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('state.hidden', true);

    expect(HiddenProfile::query()
        ->where('owner_profile_id', $viewerProfile->id)
        ->where('hidden_profile_id', $targetProfile->id)
        ->count())->toBe(1);
});

test('MobileProfile POST api v1 profile action can block and cleanup interests and shortlists', function () {
    [$viewerUser, $viewerProfile, , $targetProfile] = mobileApiProfileActionPair();
    Sanctum::actingAs($viewerUser);

    Interest::query()->create([
        'sender_profile_id' => $viewerProfile->id,
        'receiver_profile_id' => $targetProfile->id,
        'status' => 'pending',
    ]);
    Interest::query()->create([
        'sender_profile_id' => $targetProfile->id,
        'receiver_profile_id' => $viewerProfile->id,
        'status' => 'pending',
    ]);
    Shortlist::query()->create([
        'owner_profile_id' => $viewerProfile->id,
        'shortlisted_profile_id' => $targetProfile->id,
    ]);
    Shortlist::query()->create([
        'owner_profile_id' => $targetProfile->id,
        'shortlisted_profile_id' => $viewerProfile->id,
    ]);

    $response = $this->postJson('/api/v1/matrimony-profiles/'.$targetProfile->id.'/block');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('state.shortlisted', false)
        ->assertJsonPath('state.blocked', true);

    expect(Interest::query()
        ->whereIn('sender_profile_id', [$viewerProfile->id, $targetProfile->id])
        ->whereIn('receiver_profile_id', [$viewerProfile->id, $targetProfile->id])
        ->count())->toBe(0);
    expect(Shortlist::query()
        ->whereIn('owner_profile_id', [$viewerProfile->id, $targetProfile->id])
        ->whereIn('shortlisted_profile_id', [$viewerProfile->id, $targetProfile->id])
        ->count())->toBe(0);
    expect(Block::query()
        ->where('blocker_profile_id', $viewerProfile->id)
        ->where('blocked_profile_id', $targetProfile->id)
        ->count())->toBe(1);

    $duplicate = $this->postJson('/api/v1/matrimony-profiles/'.$targetProfile->id.'/block');

    $duplicate
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('state.blocked', true);

    expect(Block::query()
        ->where('blocker_profile_id', $viewerProfile->id)
        ->where('blocked_profile_id', $targetProfile->id)
        ->count())->toBe(1);
});

test('MobileProfile DELETE api v1 profile action can unblock without restoring related rows', function () {
    [$viewerUser, $viewerProfile, , $targetProfile] = mobileApiProfileActionPair();
    Sanctum::actingAs($viewerUser);

    Block::query()->create([
        'blocker_profile_id' => $viewerProfile->id,
        'blocked_profile_id' => $targetProfile->id,
    ]);

    $response = $this->deleteJson('/api/v1/matrimony-profiles/'.$targetProfile->id.'/block');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('state.blocked', false);

    expect(Block::query()
        ->where('blocker_profile_id', $viewerProfile->id)
        ->where('blocked_profile_id', $targetProfile->id)
        ->exists())->toBeFalse();
});

test('MobileProfile api v1 profile actions reject self action', function () {
    $user = User::factory()->create(['name' => 'Self Action User']);
    $profile = mobileApiCreateValidActionProfile($user, 'Self Action User');
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/matrimony-profiles/'.$profile->id.'/shortlist')
        ->assertStatus(422)
        ->assertJsonPath('success', false);
    $this->postJson('/api/v1/matrimony-profiles/'.$profile->id.'/hide')
        ->assertStatus(422)
        ->assertJsonPath('success', false);
    $this->postJson('/api/v1/matrimony-profiles/'.$profile->id.'/block')
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('MobileProfile api v1 profile actions require authentication', function () {
    [, , , $targetProfile] = mobileApiProfileActionPair();

    $this->postJson('/api/v1/matrimony-profiles/'.$targetProfile->id.'/shortlist')
        ->assertUnauthorized();
});
