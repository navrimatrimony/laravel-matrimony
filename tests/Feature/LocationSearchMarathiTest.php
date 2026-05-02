<?php

use App\Models\Location;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(MinimalLocationSeeder::class);
});

test('location search returns marathi display labels when locale is mr', function () {
    $legacyCityId = \App\Models\City::query()->where('name', 'Pune City')->value('id');
    expect($legacyCityId)->not->toBeNull();

    $state = Location::query()->create([
        'name' => 'Maharashtra',
        'slug' => 'mh-mr-test-'.$legacyCityId,
        'type' => 'state',
        'parent_id' => null,
        'state_code' => 'MH',
        'district_code' => null,
        'is_active' => true,
    ]);

    $district = Location::query()->create([
        'name' => 'Pune',
        'slug' => 'pune-dist-mr-'.$legacyCityId,
        'type' => 'district',
        'parent_id' => $state->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
    ]);

    $city = Location::query()->create([
        'id' => (int) $legacyCityId,
        'name' => 'Pune',
        'slug' => 'pune-city-mr-'.$legacyCityId,
        'type' => 'city',
        'parent_id' => $district->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
    ]);

    $state->update(['name_mr' => 'महाराष्ट्र']);
    $district->update(['name_mr' => 'पुणे']);
    $city->update(['name_mr' => 'पुणे']);

    $response = $this->getJson('/api/location/search?q=pune&locale=mr');

    $response->assertOk();
    $response->assertJsonFragment([
        'display_label' => 'पुणे, महाराष्ट्र',
    ]);
});

test('location search matches marathi name_mr text', function () {
    $legacyCityId = \App\Models\City::query()->where('name', 'Pune City')->value('id');
    $state = Location::query()->create([
        'name' => 'Maharashtra',
        'slug' => 'mh-mr-search-'.$legacyCityId,
        'type' => 'state',
        'parent_id' => null,
        'state_code' => 'MH',
        'district_code' => null,
        'is_active' => true,
    ]);
    $district = Location::query()->create([
        'name' => 'Pune',
        'slug' => 'pune-dist-search-'.$legacyCityId,
        'type' => 'district',
        'parent_id' => $state->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
    ]);
    $city = Location::query()->create([
        'id' => (int) $legacyCityId,
        'name' => 'Pune',
        'slug' => 'pune-city-search-'.$legacyCityId,
        'type' => 'city',
        'parent_id' => $district->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
        'name_mr' => 'पुणे',
    ]);
    $state->update(['name_mr' => 'महाराष्ट्र']);
    $district->update(['name_mr' => 'पुणे']);

    $response = $this->getJson('/api/location/search?q='.rawurlencode('पुणे').'&locale=mr');

    $response->assertOk();
    $response->assertJsonFragment(['id' => $city->id]);
});

test('location search matches addresses.pincode when column exists', function () {
    $geo = Location::geoTable();
    if (! Schema::hasColumn($geo, 'pincode')) {
        $this->markTestSkipped($geo.'.pincode column not present');
    }

    $state = Location::query()->create([
        'name' => 'PincodeSearchState',
        'slug' => 'pincode-search-state-'.uniqid(),
        'type' => 'state',
        'parent_id' => null,
        'is_active' => true,
    ]);
    $district = Location::query()->create([
        'name' => 'PincodeSearchDist',
        'slug' => 'pincode-search-dist-'.uniqid(),
        'type' => 'district',
        'parent_id' => $state->id,
        'is_active' => true,
    ]);
    $taluka = Location::query()->create([
        'name' => 'PincodeSearchTal',
        'slug' => 'pincode-search-tal-'.uniqid(),
        'type' => 'taluka',
        'parent_id' => $district->id,
        'is_active' => true,
    ]);
    $village = Location::query()->create([
        'name' => 'Pincode Village X',
        'slug' => 'pincode-village-'.uniqid(),
        'type' => 'village',
        'parent_id' => $taluka->id,
        'pincode' => '415309',
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/location/search?q=415309');

    $response->assertOk();
    $response->assertJsonFragment(['id' => $village->id]);
});
