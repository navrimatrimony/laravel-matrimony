<?php

use App\Models\Caste;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\SubCaste;
use App\Models\User;
use App\Services\MutationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $compiledViews = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-matrimony-public-share-views';

    if (! is_dir($compiledViews)) {
        mkdir($compiledViews, 0777, true);
    }

    config(['view.compiled' => $compiledViews]);
});

function publicShareTestSeedCurrentAddressType(): void
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

function publicShareTestLeafLocation(): Location
{
    $suffix = strtolower(str_replace('.', '-', uniqid('public-share-', true)));

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
        'name' => 'Sangli '.$suffix,
        'slug' => 'sangli-'.$suffix,
        'hierarchy' => 'district',
        'parent_id' => $state->id,
        'is_active' => true,
    ]);
    $taluka = Location::create([
        'name' => 'Khanapur '.$suffix,
        'slug' => 'khanapur-'.$suffix,
        'hierarchy' => 'taluka',
        'parent_id' => $district->id,
        'is_active' => true,
    ]);

    return Location::create([
        'name' => 'Vita '.$suffix,
        'slug' => 'vita-'.$suffix,
        'hierarchy' => 'village',
        'tag' => 'city',
        'parent_id' => $taluka->id,
        'is_active' => true,
    ]);
}

function publicShareTestCommunity(): array
{
    $suffix = strtolower(str_replace('.', '-', uniqid('public-share-community-', true)));
    $religionData = [
        'key' => 'hindu-'.$suffix,
        'label' => 'Hindu '.$suffix,
        'is_active' => true,
    ];
    if (Schema::hasColumn('master_religions', 'label_en')) {
        $religionData['label_en'] = 'Hindu';
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
        $casteData['label_en'] = 'Maratha';
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
        $subCasteData['label_en'] = 'Deshmukh';
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

function publicShareTestVisibleProfile(?User $user = null): MatrimonyProfile
{
    publicShareTestSeedCurrentAddressType();
    $user ??= User::factory()->create([
        'name' => 'Prakash Account',
        'mobile' => '9876543210',
    ]);
    $location = publicShareTestLeafLocation();
    [$religion, $caste, $subCaste] = publicShareTestCommunity();

    $profile = app(MutationService::class)->createDraftProfileForUser($user);
    app(MutationService::class)->applyManualSnapshot($profile, [
        'core' => [
            'full_name' => 'Prakash Bagal',
            'date_of_birth' => '1995-01-05',
            'highest_education' => 'B.A.',
            'location_id' => $location->id,
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
            'sub_caste_id' => $subCaste->id,
            'height_cm' => 170,
        ],
        'extended_narrative' => [
            'narrative_about_me' => 'Respectful family-focused profile summary.',
        ],
    ], (int) $user->id, 'manual');

    $profile->refresh();
    $profile->lifecycle_state = 'active';
    $profile->is_suspended = false;
    $profile->save();

    return $profile->refresh();
}

test('PublicProfileShare GET share profile renders privacy safe OpenGraph preview', function () {
    $profile = publicShareTestVisibleProfile();

    $response = $this->get('/share/profile/'.$profile->id);

    $response
        ->assertOk()
        ->assertSee('<meta property="og:title"', false)
        ->assertSee('<meta property="og:description"', false)
        ->assertSee('<meta property="og:image"', false)
        ->assertSee('<meta property="og:url"', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
        ->assertSee(route('profile.share.public', ['id' => $profile->id]), false)
        ->assertSee('Prakash Bagal')
        ->assertSee('View profile on')
        ->assertDontSee('9876543210')
        ->assertDontSee('contact_number')
        ->assertDontSee('primary_contact_number');
});

test('PublicProfileShare GET share profile returns 404 when profile is suspended', function () {
    $profile = publicShareTestVisibleProfile();
    $profile->lifecycle_state = 'suspended';
    $profile->is_suspended = true;
    $profile->save();

    $this->get('/share/profile/'.$profile->id)->assertNotFound();
});

test('PublicProfileShare API profile detail includes display share payload', function () {
    $viewer = User::factory()->create(['name' => 'Share Viewer']);
    publicShareTestVisibleProfile($viewer);
    $target = publicShareTestVisibleProfile(User::factory()->create(['name' => 'Share Target']));
    Sanctum::actingAs($viewer);

    $response = $this->getJson('/api/v1/matrimony-profiles/'.$target->id);

    $shareUrl = route('profile.share.public', ['id' => $target->id]);
    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('display.share.url', $shareUrl);

    expect($response->json('display.share.title'))->toContain('Prakash Bagal');
    expect($response->json('display.share.title'))->toContain('Navri Mile Navryala');
    expect($response->json('display.share.text'))->toContain($shareUrl);
});
