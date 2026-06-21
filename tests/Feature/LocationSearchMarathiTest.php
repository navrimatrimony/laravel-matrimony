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
    $city = \App\Models\City::query()->where('name', 'Pune City')->firstOrFail();
    $district = Location::query()->where('hierarchy', 'district')->where('name', 'Pune')->firstOrFail();
    $state = Location::query()->where('hierarchy', 'state')->where('name', 'Maharashtra')->firstOrFail();

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
    $city = \App\Models\City::query()->where('name', 'Pune City')->firstOrFail();
    $district = Location::query()->where('hierarchy', 'district')->where('name', 'Pune')->firstOrFail();
    $state = Location::query()->where('hierarchy', 'state')->where('name', 'Maharashtra')->firstOrFail();
    $city->update(['name_mr' => 'पुणे']);
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
        'hierarchy' => 'state',
        'parent_id' => null,
        'is_active' => true,
    ]);
    $district = Location::query()->create([
        'name' => 'PincodeSearchDist',
        'slug' => 'pincode-search-dist-'.uniqid(),
        'hierarchy' => 'district',
        'parent_id' => $state->id,
        'is_active' => true,
    ]);
    $taluka = Location::query()->create([
        'name' => 'PincodeSearchTal',
        'slug' => 'pincode-search-tal-'.uniqid(),
        'hierarchy' => 'taluka',
        'parent_id' => $district->id,
        'is_active' => true,
    ]);
    $village = Location::query()->create([
        'name' => 'Pincode Village X',
        'slug' => 'pincode-village-'.uniqid(),
        'hierarchy' => 'village',
        'parent_id' => $taluka->id,
        'pincode' => '415309',
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/location/search?q=415309');

    $response->assertOk();
    $response->assertJsonFragment(['id' => $village->id]);
});

test('location multi-word search matches village plus ancestor district token', function () {
    $suffix = str_replace('.', '_', uniqid('mt_', true));
    $state = Location::query()->create([
        'name' => 'Maharashtra',
        'slug' => 'mh-mt-'.$suffix,
        'hierarchy' => 'state',
        'parent_id' => null,
        'is_active' => true,
    ]);
    $district = Location::query()->create([
        'name' => 'Sangli',
        'slug' => 'sangli-mt-'.$suffix,
        'hierarchy' => 'district',
        'parent_id' => $state->id,
        'is_active' => true,
    ]);
    $taluka = Location::query()->create([
        'name' => 'Miraj MT '.$suffix,
        'slug' => 'miraj-mt-'.$suffix,
        'hierarchy' => 'taluka',
        'parent_id' => $district->id,
        'is_active' => true,
    ]);
    $village = Location::query()->create([
        'name' => 'Islampur',
        'slug' => 'islampur-mt-'.$suffix,
        'hierarchy' => 'village',
        'parent_id' => $taluka->id,
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/location/search?q='.rawurlencode('islampur sangli'));

    $response->assertOk();
    $response->assertJsonFragment(['id' => $village->id]);
});

test('location search prefers requested state while keeping all india results', function () {
    $suffix = str_replace('.', '_', uniqid('pref_', true));
    $maharashtra = Location::query()->where('hierarchy', 'state')->where('name', 'Maharashtra')->firstOrFail();
    $gujarat = Location::query()->where('hierarchy', 'state')->where('name', 'Gujarat')->firstOrFail();

    $maharashtraDistrict = Location::query()->create([
        'name' => 'Preferred Sangli '.$suffix,
        'slug' => 'preferred-sangli-'.$suffix,
        'hierarchy' => 'district',
        'parent_id' => $maharashtra->id,
        'is_active' => true,
    ]);
    $maharashtraTaluka = Location::query()->create([
        'name' => 'Preferred Vita '.$suffix,
        'slug' => 'preferred-vita-'.$suffix,
        'hierarchy' => 'taluka',
        'parent_id' => $maharashtraDistrict->id,
        'is_active' => true,
    ]);
    $maharashtraTown = Location::query()->create([
        'name' => 'Shared Preferred Town '.$suffix,
        'slug' => 'shared-preferred-town-mh-'.$suffix,
        'hierarchy' => 'village',
        'tag' => 'city',
        'parent_id' => $maharashtraTaluka->id,
        'is_active' => true,
    ]);

    $gujaratDistrict = Location::query()->create([
        'name' => 'Preferred Ahmedabad '.$suffix,
        'slug' => 'preferred-ahmedabad-'.$suffix,
        'hierarchy' => 'district',
        'parent_id' => $gujarat->id,
        'is_active' => true,
    ]);
    $gujaratTaluka = Location::query()->create([
        'name' => 'Preferred Daskroi '.$suffix,
        'slug' => 'preferred-daskroi-'.$suffix,
        'hierarchy' => 'taluka',
        'parent_id' => $gujaratDistrict->id,
        'is_active' => true,
    ]);
    $gujaratTown = Location::query()->create([
        'name' => 'Shared Preferred Town '.$suffix,
        'slug' => 'shared-preferred-town-gj-'.$suffix,
        'hierarchy' => 'village',
        'tag' => 'city',
        'parent_id' => $gujaratTaluka->id,
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/location/search?q='.rawurlencode('Shared Preferred Town '.$suffix).'&preferred_state_id='.$maharashtra->id.'&limit=10');

    $response->assertOk();
    $ids = array_column($response->json(), 'id');

    expect($ids)->toContain($maharashtraTown->id)
        ->and($ids)->toContain($gujaratTown->id)
        ->and($ids[0])->toBe($maharashtraTown->id);

    $response->assertJsonPath('0.preferred_state', true);
    $response->assertJsonPath('0.state_id', $maharashtra->id);

    $defaultResponse = $this->getJson('/api/location/search?q='.rawurlencode('Shared Preferred Town '.$suffix).'&limit=10');
    $defaultResponse->assertOk();
    $defaultIds = array_column($defaultResponse->json(), 'id');

    expect($defaultIds)->toContain($maharashtraTown->id)
        ->and($defaultIds)->toContain($gujaratTown->id)
        ->and($defaultIds[0])->toBe($maharashtraTown->id);
});
