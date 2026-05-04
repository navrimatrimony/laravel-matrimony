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
    $state = Location::query()->where('type', 'state')->where('name', 'Maharashtra')->first();
    $district = Location::query()->where('type', 'district')->where('name', 'Pune')->first();
    expect($state)->not->toBeNull()->and($district)->not->toBeNull();

    $city = Location::query()->create([
        'name' => 'Pune',
        'slug' => 'fmt-pune-'.uniqid(),
        'type' => 'city',
        'parent_id' => $district->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
        'category' => 'city',
    ]);

    $line = app(LocationFormatterService::class)->formatLocation((int) $city->id);
    expect($line)->toBe('Pune, Maharashtra');
});

test('rural tag includes taluka district and optional pincode', function () {
    $state = Location::query()->where('type', 'state')->where('name', 'Maharashtra')->firstOrFail();
    $district = Location::query()->where('type', 'district')->where('name', 'Pune')->firstOrFail();
    $taluka = Location::query()->where('type', 'taluka')->where('name', 'Haveli')->firstOrFail();

    $village = Location::query()->create([
        'name' => 'Testwadi',
        'slug' => 'fmt-wadi-'.uniqid(),
        'type' => 'village',
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

test('suburban tag uses suburb and city with pincode when present', function () {
    $taluka = Location::query()->where('type', 'taluka')->where('name', 'Haveli')->firstOrFail();
    $city = Location::query()->create([
        'name' => 'Pune Central',
        'slug' => 'fmt-pune-central-'.uniqid(),
        'type' => 'city',
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
        'type' => 'suburb',
        'parent_id' => $city->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
        'tag' => 'suburban',
    ]);

    $line = app(LocationFormatterService::class)->formatLocation((int) $suburb->id);
    expect($line)->toContain('Baner')
        ->and($line)->toContain('Pune Central')
        ->and($line)->toContain('411001');
});
