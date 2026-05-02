<?php

use App\Models\City;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\Pincode;
use App\Models\Taluka;
use App\Models\User;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

    $mh = Location::query()->create([
        'name' => 'Maharashtra',
        'slug' => 'mh-nearby-'.uniqid(),
        'type' => 'state',
        'parent_id' => null,
        'state_code' => 'MH',
        'is_active' => true,
    ]);
    $puneDist = Location::query()->create([
        'name' => 'Pune',
        'slug' => 'pune-dist-nearby-'.uniqid(),
        'type' => 'district',
        'parent_id' => $mh->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
    ]);
    $puneLoc = Location::query()->create([
        'id' => $puneCity->id,
        'name' => 'Pune',
        'slug' => 'pune-city-nearby-'.uniqid(),
        'type' => 'city',
        'parent_id' => $puneDist->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
    ]);

    $gj = Location::query()->create([
        'name' => 'Gujarat',
        'slug' => 'gj-nearby-'.uniqid(),
        'type' => 'state',
        'parent_id' => null,
        'state_code' => 'GJ',
        'is_active' => true,
    ]);
    $ahmDist = Location::query()->create([
        'name' => 'Ahmedabad',
        'slug' => 'ahm-dist-nearby-'.uniqid(),
        'type' => 'district',
        'parent_id' => $gj->id,
        'state_code' => 'GJ',
        'district_code' => 'AH',
        'is_active' => true,
    ]);
    Location::query()->create([
        'id' => $ahmedabadCity->id,
        'name' => 'Ahmedabad',
        'slug' => 'ahmedabad-city-nearby-'.uniqid(),
        'type' => 'city',
        'parent_id' => $ahmDist->id,
        'state_code' => 'GJ',
        'district_code' => 'AH',
        'is_active' => true,
    ]);

    Location::query()->create([
        'id' => $banerCity->id,
        'name' => 'Baner',
        'slug' => 'baner-nearby-'.uniqid(),
        'type' => 'suburb',
        'parent_id' => $puneLoc->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
    ]);

    DB::table(Location::geoTable())->insert([
        'id' => 9001,
        'name' => 'Wakad Source',
        'slug' => 'wakad-source-test-'.uniqid(),
        'type' => 'suburb',
        'parent_id' => $puneLoc->id,
        'level' => 5,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $source = Location::query()->findOrFail(9001);

    Pincode::query()->create([
        'pincode' => '411057',
        'place_id' => $source->id,
        'latitude' => 18.5912,
        'longitude' => 73.7400,
        'is_primary' => true,
    ]);
    Pincode::query()->create([
        'pincode' => '411045',
        'place_id' => $banerCity->id,
        'latitude' => 18.5590,
        'longitude' => 73.7868,
        'is_primary' => true,
    ]);
    Pincode::query()->create([
        'pincode' => '411001',
        'place_id' => $puneCity->id,
        'latitude' => 18.5204,
        'longitude' => 73.8567,
        'is_primary' => true,
    ]);
    Pincode::query()->create([
        'pincode' => '380001',
        'place_id' => $ahmedabadCity->id,
        'latitude' => 23.0225,
        'longitude' => 72.5714,
        'is_primary' => true,
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
