<?php

use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Taluka;
use App\Models\User;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(MinimalLocationSeeder::class);
});

test('internal location suggest requires authentication', function () {
    if (! Schema::hasTable('location_suggestions')) {
        $this->markTestSkipped('location_suggestions table not migrated');
    }

    $country = Country::query()->where('iso_alpha2', 'IN')->first();
    $state = State::query()->where('name', 'Maharashtra')->first();
    $district = District::query()->where('name', 'Pune')->first();
    $taluka = Taluka::query()->where('name', 'Haveli')->first();
    expect($country && $state && $district && $taluka)->toBeTrue();

    $response = $this->postJson('/api/internal/location/suggest', [
        'suggested_name' => 'Test Hamlet '.uniqid('', true),
        'state_id' => $state->id,
        'district_id' => $district->id,
        'taluka_id' => $taluka->id,
        'suggestion_type' => 'village',
    ]);

    $response->assertUnauthorized();
});

test('authenticated user can submit hierarchy location suggestion', function () {
    if (! Schema::hasTable('location_suggestions')) {
        $this->markTestSkipped('location_suggestions table not migrated');
    }

    $country = Country::query()->where('iso_alpha2', 'IN')->first();
    $state = State::query()->where('name', 'Maharashtra')->first();
    $district = District::query()->where('name', 'Pune')->first();
    $taluka = Taluka::query()->where('name', 'Haveli')->first();
    expect($country && $state && $district && $taluka)->toBeTrue();

    $user = User::factory()->create();
    $name = 'Test Hamlet '.uniqid('', true);

    $response = $this->actingAs($user)->postJson('/api/internal/location/suggest', [
        'suggested_name' => $name,
        'state_id' => $state->id,
        'district_id' => $district->id,
        'taluka_id' => $taluka->id,
        'suggestion_type' => 'village',
        'suggested_pincode' => '411001',
    ]);

    $response->assertOk();
    $response->assertJson(['success' => true]);
    $sid = $response->json('data.suggestion_id');
    expect($sid)->not->toBeNull()->and(is_numeric($sid))->toBeTrue();
});
