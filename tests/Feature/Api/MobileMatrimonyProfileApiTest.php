<?php

use App\Models\Caste;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\User;
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

test('POST api v1 matrimony-profile creates through governed mutation path', function () {
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

test('PUT api v1 matrimony-profile accepts mobile core fields through MutationService', function () {
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
