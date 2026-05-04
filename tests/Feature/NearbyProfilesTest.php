<?php

use App\Models\City;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\Taluka;
use App\Models\User;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(MinimalLocationSeeder::class);
});

function setupNearbyProfilesFixture(): array
{
    $puneCity = City::query()->where('name', 'Pune City')->firstOrFail();
    $ahmedabadCity = City::query()->where('name', 'Ahmedabad City')->firstOrFail();
    $haveli = Taluka::query()->where('name', 'Haveli')->firstOrFail();

    $banerCity = City::query()->create([
        'taluka_id' => $haveli->id,
        'name' => 'Baner City',
    ]);

    $source = Location::query()->create([
        'name' => 'Wakad Source',
        'slug' => 'wakad-source-test-'.uniqid(),
        'type' => 'suburb',
        'parent_id' => $puneCity->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
    ]);

    Location::query()->whereKey($source->id)->update([
        'pincode' => '411057',
        'latitude' => 18.5912,
        'longitude' => 73.7400,
    ]);
    Location::query()->whereKey($banerCity->id)->update([
        'pincode' => '411045',
        'latitude' => 18.5590,
        'longitude' => 73.7868,
    ]);
    Location::query()->whereKey($puneCity->id)->update([
        'pincode' => '411001',
        'latitude' => 18.5204,
        'longitude' => 73.8567,
    ]);
    Location::query()->whereKey($ahmedabadCity->id)->update([
        'pincode' => '380001',
        'latitude' => 23.0225,
        'longitude' => 72.5714,
    ]);

    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $u3 = User::factory()->create();

    $banerProfile = MatrimonyProfile::query()->create([
        'user_id' => $u1->id,
        'full_name' => 'Baner Nearby',
        'location_id' => $banerCity->id,
    ]);
    $puneProfile = MatrimonyProfile::query()->create([
        'user_id' => $u2->id,
        'full_name' => 'Pune Nearby',
        'location_id' => $puneCity->id,
    ]);
    $ahmedabadProfile = MatrimonyProfile::query()->create([
        'user_id' => $u3->id,
        'full_name' => 'Ahmedabad Far',
        'location_id' => $ahmedabadCity->id,
    ]);

    return [
        'source_location_id' => $source->id,
        'baner_profile_id' => $banerProfile->id,
        'pune_profile_id' => $puneProfile->id,
        'ahmedabad_profile_id' => $ahmedabadProfile->id,
    ];
}

test('valid location returns nearby profiles', function () {
    $fx = setupNearbyProfilesFixture();

    $response = $this->getJson('/api/profiles/nearby?location_id='.$fx['source_location_id'].'&radius=25');
    $response->assertOk();

    $rows = $response->json();
    expect($rows)->toBeArray()->not->toBeEmpty();

    $profileIds = array_column($rows, 'profile_id');
    expect($profileIds)->toContain($fx['baner_profile_id'])
        ->toContain($fx['pune_profile_id'])
        ->not->toContain($fx['ahmedabad_profile_id']);

    foreach ($rows as $row) {
        expect($row)->toHaveKeys(['profile_id', 'name', 'location_id', 'location_label', 'distance_km']);
        expect((float) $row['distance_km'])->toBeGreaterThan(0.0);
    }
});

test('invalid location id returns validation error', function () {
    setupNearbyProfilesFixture();

    $response = $this->getJson('/api/profiles/nearby?location_id=999999&radius=25');
    $response->assertStatus(422)->assertJsonValidationErrors(['location_id']);
});

test('radius filter works', function () {
    $fx = setupNearbyProfilesFixture();

    $small = $this->getJson('/api/profiles/nearby?location_id='.$fx['source_location_id'].'&radius=8');
    $small->assertOk();
    $smallIds = array_column($small->json(), 'profile_id');
    expect($smallIds)->toContain($fx['baner_profile_id'])
        ->not->toContain($fx['pune_profile_id']);

    $large = $this->getJson('/api/profiles/nearby?location_id='.$fx['source_location_id'].'&radius=25');
    $large->assertOk();
    $largeIds = array_column($large->json(), 'profile_id');
    expect($largeIds)->toContain($fx['baner_profile_id'])
        ->toContain($fx['pune_profile_id']);
});

test('type filter runs without error', function () {
    $fx = setupNearbyProfilesFixture();

    $this->getJson('/api/profiles/nearby?location_id='.$fx['source_location_id'].'&radius=25&type=city')
        ->assertOk();
});
