<?php

use App\Models\Location;
use App\Services\Location\LocationFormatterService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(MinimalLocationSeeder::class);
});

test('city tag formats as city and state in English', function () {
    $state = Location::query()->where('hierarchy', 'state')->where('name', 'Maharashtra')->first();
    $district = Location::query()->where('hierarchy', 'district')->where('name', 'Pune')->first();
    $taluka = Location::query()->where('hierarchy', 'taluka')->where('name', 'Haveli')->first();
    expect($state)->not->toBeNull()->and($district)->not->toBeNull()->and($taluka)->not->toBeNull();

    $city = Location::query()->create([
        'name' => 'Pune',
        'slug' => 'fmt-pune-'.uniqid(),
        'hierarchy' => 'village',
        'parent_id' => $taluka->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
        'tag' => 'city',
    ]);

    $line = app(LocationFormatterService::class)->formatLocation((int) $city->id);
    expect($line)->toBe('Pune, Maharashtra');
});

test('rural tag includes taluka district and optional pincode', function () {
    $state = Location::query()->where('hierarchy', 'state')->where('name', 'Maharashtra')->firstOrFail();
    $district = Location::query()->where('hierarchy', 'district')->where('name', 'Pune')->firstOrFail();
    $taluka = Location::query()->where('hierarchy', 'taluka')->where('name', 'Haveli')->firstOrFail();

    $village = Location::query()->create([
        'name' => 'Testwadi',
        'slug' => 'fmt-wadi-'.uniqid(),
        'hierarchy' => 'village',
        'parent_id' => $taluka->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
        'category' => 'rural',
        'pincode' => '411042',
    ]);

    $line = app(LocationFormatterService::class)->formatLocation((int) $village->id);
    expect($line)->toContain('Testwadi')
        ->and($line)->toContain('Haveli')
        ->and($line)->toContain('Pune')
        ->and($line)->toContain('411042');
});

test('rural label keeps taluka when village and taluka share the same name', function () {
    $district = Location::query()->where('hierarchy', 'district')->where('name', 'Pune')->firstOrFail();

    $taluka = Location::query()->create([
        'name' => 'Tasgaon',
        'slug' => 'fmt-dup-taluka-'.uniqid(),
        'hierarchy' => 'taluka',
        'parent_id' => $district->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
        'category' => 'city',
    ]);

    $village = Location::query()->create([
        'name' => 'Tasgaon',
        'slug' => 'fmt-dup-village-'.uniqid(),
        'hierarchy' => 'village',
        'parent_id' => $taluka->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
        'category' => 'rural',
        'pincode' => '415004',
    ]);

    $line = app(LocationFormatterService::class)->formatLocation((int) $village->id);

    expect($line)->toBe('Tasgaon, Tasgaon, Pune 415004');
});

test('suburban tag uses suburb and city with pincode when present', function () {
    $taluka = Location::query()->where('hierarchy', 'taluka')->where('name', 'Haveli')->firstOrFail();
    Location::query()->create([
        'name' => 'Pune Central',
        'slug' => 'fmt-pune-central-'.uniqid(),
        'hierarchy' => 'village',
        'parent_id' => $taluka->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
        'tag' => 'city',
        'pincode' => '411001',
    ]);

    $suburb = Location::query()->create([
        'name' => 'Baner',
        'slug' => 'fmt-baner-'.uniqid(),
        'hierarchy' => 'village',
        'parent_id' => $taluka->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
        'tag' => 'suburban',
        'pincode' => '411045',
    ]);

    $line = app(LocationFormatterService::class)->formatLocation((int) $suburb->id);
    expect($line)->toContain('Baner')
        ->and($line)->toContain('Haveli')
        ->and($line)->toContain('Pune')
        ->and($line)->toContain('411045');
});
