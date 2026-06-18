<?php

use App\Models\Block;
use App\Models\Caste;
use App\Models\HiddenProfile;
use App\Models\Interest;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\Shortlist;
use App\Models\SubCaste;
use App\Models\User;
use App\Services\Api\MobileProfileDisplayPresenter;
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
    $viewerProfile = mobileApiCreateValidActionProfile($viewerUser, 'Mobile Action Viewer');
    $targetProfile = mobileApiCreateValidActionProfile($targetUser, 'Mobile Action Target');

    return [$viewerUser, $viewerProfile, $targetUser, $targetProfile];
}

function mobileApiCreateValidActionProfile(User $user, string $name): MatrimonyProfile
{
    mobileApiProfileTestSeedCurrentAddressType();
    $location = mobileApiProfileTestLeafLocation();
    [$religion, $caste, $subCaste] = mobileApiProfileTestCommunity();

    $profile = app(MutationService::class)->createDraftProfileForUser($user);
    app(MutationService::class)->applyManualSnapshot($profile, [
        'core' => [
            'full_name' => $name,
            'date_of_birth' => '1995-01-05',
            'highest_education' => 'B.A.',
            'location_id' => $location->id,
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
            'sub_caste_id' => $subCaste->id,
        ],
    ], (int) $user->id, 'manual');

    $profile->refresh();
    $profile->lifecycle_state = 'active';
    $profile->is_suspended = false;
    $profile->save();

    return $profile->refresh();
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

test('MobileProfile POST api v1 matrimony-profile accepts canonical community ids', function () {
    mobileApiProfileTestSeedCurrentAddressType();
    $user = User::factory()->create(['name' => 'Canonical Account']);
    $location = mobileApiProfileTestLeafLocation();
    [$religion, $caste, $subCaste] = mobileApiProfileTestCommunity();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/matrimony-profile', [
        'full_name' => 'Canonical Mobile Candidate',
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

    expect((int) $profile->religion_id)->toBe((int) $religion->id);
    expect((int) $profile->caste_id)->toBe((int) $caste->id);
    expect((int) $profile->sub_caste_id)->toBe((int) $subCaste->id);
});

test('MobileProfile POST api v1 matrimony-profile rejects caste religion mismatch', function () {
    mobileApiProfileTestSeedCurrentAddressType();
    $user = User::factory()->create(['name' => 'Mismatch Account']);
    $location = mobileApiProfileTestLeafLocation();
    [$religion, $caste] = mobileApiProfileTestCommunity();
    [$otherReligion] = mobileApiProfileTestCommunity();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/matrimony-profile', [
        'full_name' => 'Mismatch Mobile Candidate',
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
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/matrimony-profile', [
        'full_name' => 'Sub Caste Mismatch Candidate',
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
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/matrimony-profile', [
        'full_name' => 'Mobile Candidate',
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
                'highest_education' => 'B.E.',
                'caste_id' => $caste->id,
            ],
        ]);

    $profile = MatrimonyProfile::where('user_id', $user->id)->firstOrFail();

    expect($profile->full_name)->toBe('Mobile Candidate');
    expect(substr((string) $profile->date_of_birth, 0, 10))->toBe('1998-04-15');
    expect($profile->highest_education)->toBe('B.E.');
    expect((int) $profile->location_id)->toBe((int) $location->id);
    expect((int) $profile->caste_id)->toBe((int) $caste->id);
    expect(DB::table('profile_change_history')
        ->where('profile_id', $profile->id)
        ->whereIn('field_name', ['full_name', 'date_of_birth', 'highest_education', 'location_id', 'caste_id'])
        ->count())->toBeGreaterThanOrEqual(5);
});

test('MobileProfile PUT api v1 matrimony-profile accepts mobile core fields through MutationService', function () {
    mobileApiProfileTestSeedCurrentAddressType();
    $user = User::factory()->create(['name' => 'Existing Account']);
    $profile = app(MutationService::class)->createDraftProfileForUser($user);
    $location = mobileApiProfileTestLeafLocation();
    $caste = mobileApiProfileTestCaste();
    Sanctum::actingAs($user);

    $response = $this->putJson('/api/v1/matrimony-profile', [
        'full_name' => 'Updated Mobile Candidate',
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
                'highest_education' => 'MCA',
                'caste_id' => $caste->id,
                'location_id' => $location->id,
            ],
        ]);

    $profile->refresh();

    expect($profile->full_name)->toBe('Updated Mobile Candidate');
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
    Sanctum::actingAs($user);

    $create = $this->postJson('/api/v1/matrimony-profile', [
        'full_name' => 'Display Mobile Candidate',
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

    $detail = $this->getJson('/api/v1/matrimony-profiles/'.$profile->id);
    $detail
        ->assertOk()
        ->assertJsonPath('profile.id', $profile->id)
        ->assertJsonPath('profile.location_id', $location->id)
        ->assertJsonPath('display.version', 1)
        ->assertJsonStructure(['profile', 'display' => ['hero', 'sections']]);
});

test('MobileProfile GET api v1 profile detail returns clean comparison payload for target preferences', function () {
    $viewerUser = User::factory()->create(['name' => 'Comparison Viewer', 'gender' => 'male']);
    $targetUser = User::factory()->create(['name' => 'Comparison Target', 'gender' => 'female']);
    $viewerProfile = mobileApiCreateValidActionProfile($viewerUser, 'Comparison Viewer');
    $targetProfile = mobileApiCreateValidActionProfile($targetUser, 'Comparison Target');
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
                    'title',
                    'summary',
                    'matched_count',
                    'total_count',
                    'items',
                ],
            ],
        ]);

    $comparison = $response->json('display.comparison');
    expect($comparison['matched_count'])->toBeGreaterThanOrEqual(1);
    expect($comparison['total_count'])->toBeGreaterThanOrEqual($comparison['matched_count']);
    expect($comparison['summary'])->toBe('You match '.$comparison['matched_count'].'/'.$comparison['total_count'].' preferences');
    expect($comparison['items'])->toBeArray()->not->toBeEmpty();

    foreach ($comparison['items'] as $item) {
        expect($item['key'])->toBeString();
        expect($item['label'])->toBeString();
        expect($item['target_preference'])->toBeString();
        expect($item['viewer_value'])->toBeString();
        expect((bool) preg_match('/^\s*[\{\[]|=>|\bcreated_at\b|\bupdated_at\b/i', $item['target_preference']))->toBeFalse();
        expect((bool) preg_match('/^\s*[\{\[]|=>|\bcreated_at\b|\bupdated_at\b/i', $item['viewer_value']))->toBeFalse();
        expect(in_array($item['matched'], [true, false, null], true))->toBeTrue();
    }

    $partnerMatchSection = collect($response->json('display.sections'))->firstWhere('key', 'partner_match');
    expect($partnerMatchSection)->not->toBeNull();
    expect($partnerMatchSection['title'])->toBe('You & Her');
});

test('MobileProfile GET api v1 profile detail keeps comparison null without target preferences', function () {
    [$viewerUser, , , $targetProfile] = mobileApiProfileActionPair();
    Sanctum::actingAs($viewerUser);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$targetProfile->id);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('display.comparison', null)
        ->assertJsonStructure([
            'profile',
            'display' => [
                'hero',
                'sections',
                'share',
                'actions',
            ],
        ]);

    expect(collect($response->json('display.sections'))->firstWhere('key', 'partner_match'))->toBeNull();
});

test('MobileProfile presenter keeps comparison null when viewer has no profile', function () {
    [, $viewerProfile, , $targetProfile] = mobileApiProfileActionPair();
    mobileApiAddTargetPartnerPreferences($targetProfile, $viewerProfile);

    $viewerWithoutProfile = User::factory()->create(['name' => 'No Profile Viewer']);
    $display = app(MobileProfileDisplayPresenter::class)->forProfile($targetProfile, $viewerWithoutProfile);

    expect($display['comparison'])->toBeNull();
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
