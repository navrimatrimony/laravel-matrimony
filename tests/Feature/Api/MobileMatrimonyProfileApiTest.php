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
use App\Models\Religion;
use App\Models\Shortlist;
use App\Models\SubCaste;
use App\Models\User;
use App\Services\Api\MobileProfileDisplayPresenter;
use App\Services\ContactAccessService;
use App\Services\FeatureUsageService;
use App\Services\Gunamilan\GunamilanService;
use App\Services\MutationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

test('MobileProfile POST api v1 matrimony-profile accepts canonical community ids', function () {
    mobileApiProfileTestSeedCurrentAddressType();
    $user = User::factory()->create(['name' => 'Canonical Account']);
    $location = mobileApiProfileTestLeafLocation();
    [$religion, $caste, $subCaste] = mobileApiProfileTestCommunity();
    $gender = mobileApiProfileTestGender('female');
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/matrimony-profile', [
        'full_name' => 'Canonical Mobile Candidate',
        'gender_id' => $gender->id,
        'date_of_birth' => '1998-04-15',
        'caste' => $caste->label,
        'highest_education' => 'B.E.',
        'location_id' => $location->id,
        'religion_id' => $religion->id,
        'caste_id' => $caste->id,
        'sub_caste_id' => $subCaste->id,
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
            ],
        ]);

    expect($response->json('profile.location_label'))->toContain('Wakad');

    $profile = MatrimonyProfile::where('user_id', $user->id)->firstOrFail();

    expect((int) $profile->gender_id)->toBe((int) $gender->id);
    expect((int) $profile->religion_id)->toBe((int) $religion->id);
    expect((int) $profile->caste_id)->toBe((int) $caste->id);
    expect((int) $profile->sub_caste_id)->toBe((int) $subCaste->id);
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
    $caste = mobileApiProfileTestCaste();
    $gender = mobileApiProfileTestGender('female');
    Sanctum::actingAs($user);

    $response = $this->putJson('/api/v1/matrimony-profile', [
        'full_name' => 'Updated Mobile Candidate',
        'gender_id' => $gender->id,
        'date_of_birth' => '1997-03-20',
        'caste' => 'Maratha',
        'highest_education' => 'MCA',
        'location_id' => $location->id,
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
            ],
        ]);

    $profile->refresh();

    expect($profile->full_name)->toBe('Updated Mobile Candidate');
    expect((int) $profile->gender_id)->toBe((int) $gender->id);
    expect(substr((string) $profile->date_of_birth, 0, 10))->toBe('1997-03-20');
    expect($profile->highest_education)->toBe('MCA');
    expect((int) $profile->location_id)->toBe((int) $location->id);
    expect((int) $profile->caste_id)->toBe((int) $caste->id);
    expect(DB::table('profile_field_locks')
        ->where('profile_id', $profile->id)
        ->where('field_key', 'caste_id')
        ->exists())->toBeTrue();
});

test('MobileProfile GET api v1 matrimony profile returns clean display payload beside profile', function () {
    mobileApiProfileTestSeedCurrentAddressType();
    $user = User::factory()->create(['name' => 'Display Account']);
    $location = mobileApiProfileTestLeafLocation();
    [$religion, $caste, $subCaste] = mobileApiProfileTestCommunity();
    $gender = mobileApiProfileTestGender('female');
    Sanctum::actingAs($user);

    $create = $this->postJson('/api/v1/matrimony-profile', [
        'full_name' => 'Display Mobile Candidate',
        'gender_id' => $gender->id,
        'date_of_birth' => '1995-01-05',
        'caste' => $caste->label,
        'highest_education' => 'B.A., B.Com.',
        'location_id' => $location->id,
        'religion_id' => $religion->id,
        'caste_id' => $caste->id,
        'sub_caste_id' => $subCaste->id,
    ]);
    $create->assertOk();

    $profile = MatrimonyProfile::where('user_id', $user->id)->firstOrFail();
    $response = $this->getJson('/api/v1/matrimony-profile');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('profile.id', $profile->id)
        ->assertJsonPath('profile.location_id', $location->id)
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

test('MobileProfile GET api v1 matrimony profiles shows only opposite gender member profiles', function () {
    $viewerUser = User::factory()->create(['name' => 'Gender Rule Viewer']);
    $viewerProfile = mobileApiCreateValidActionProfile($viewerUser, 'Gender Rule Viewer', 'male');
    $femaleUser = User::factory()->create(['name' => 'Visible Female Member']);
    $femaleProfile = mobileApiCreateValidActionProfile($femaleUser, 'Visible Female Member', 'female');
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
    expect($ids)->not->toContain($viewerProfile->id);
    expect($ids)->not->toContain($missingGenderProfile->id);
    expect($ids)->not->toContain($maleProfile->id);
    expect($ids)->not->toContain($adminProfile->id);
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
