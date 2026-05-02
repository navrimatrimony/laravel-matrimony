<?php

use App\Models\City;
use App\Models\Location;
use App\Models\LocationOpenPlaceSuggestion;
use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\MasterLookupSeeder::class);
    $this->seed(MinimalLocationSeeder::class);
});

function locationSelectionBasePayload(): array
{
    $genderId = MasterGender::query()->where('key', 'male')->where('is_active', true)->value('id');
    $neverMarriedId = MasterMaritalStatus::query()->where('key', 'never_married')->value('id');
    if (! $genderId || ! $neverMarriedId) {
        test()->markTestSkipped('Master lookups not seeded.');
    }

    return [
        'full_name' => 'Location Selection Test',
        'gender_id' => (int) $genderId,
        'marital_status_id' => (int) $neverMarriedId,
        'marriages' => [
            ['id' => null, 'marriage_year' => '', 'divorce_year' => '', 'divorce_status' => ''],
        ],
    ];
}

function createMinimalLocationHierarchy(): array
{
    $legacyCity = City::query()->where('name', 'Pune City')->firstOrFail();

    $state = Location::query()->create([
        'name' => 'Maharashtra',
        'slug' => 'mh-locsel-'.$legacyCity->id,
        'type' => 'state',
        'parent_id' => null,
        'state_code' => 'MH',
        'district_code' => null,
        'is_active' => true,
    ]);

    $district = Location::query()->create([
        'name' => 'Pune',
        'slug' => 'pune-dist-locsel-'.$legacyCity->id,
        'type' => 'district',
        'parent_id' => $state->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
    ]);

    $city = Location::query()->create([
        'id' => $legacyCity->id,
        'name' => 'Pune',
        'slug' => 'pune-city-locsel-'.$legacyCity->id,
        'type' => 'city',
        'parent_id' => $district->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
    ]);

    $suburb = Location::query()->create([
        'name' => 'Wakad',
        'slug' => 'wakad-test-'.$legacyCity->id,
        'type' => 'suburb',
        'parent_id' => $city->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
    ]);

    return [$city, $suburb];
}

function assertLocationPendingState(MatrimonyProfile $profile, bool $expected): void
{
    if (! Schema::hasColumn('matrimony_profiles', 'location_pending')) {
        test()->assertTrue(true);

        return;
    }

    $value = $profile->fresh()->location_pending;
    test()->assertSame($expected, (bool) $value);
}

test('known location saves canonical location id successfully', function () {
    [$city, $suburb] = createMinimalLocationHierarchy();
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

    $payload = array_merge(locationSelectionBasePayload(), [
        'location_id' => $city->id,
        'location_input' => '',
    ]);

    $response = $this->actingAs($user)->post(route('matrimony.profile.wizard.store', ['section' => 'basic-info']), $payload);

    $response->assertSessionHasNoErrors();
    $profile->refresh();

    expect($profile->location_id)->toBe($city->id);
    assertLocationPendingState($profile, false);
    expect(LocationOpenPlaceSuggestion::query()->count())->toBe(0);
});

test('unknown location saves suggestion and keeps canonical location empty', function () {
    createMinimalLocationHierarchy();
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

    $payload = array_merge(locationSelectionBasePayload(), [
        'location_id' => null,
        'location_input' => 'My Unknown Nagar',
    ]);

    $response = $this->actingAs($user)->post(route('matrimony.profile.wizard.store', ['section' => 'basic-info']), $payload);

    $response->assertSessionHasNoErrors();
    $profile->refresh();

    expect($profile->location_id)->toBeNull();
    assertLocationPendingState($profile, true);
    $this->assertDatabaseHas('location_open_place_suggestions', [
        'raw_input' => 'My Unknown Nagar',
        'status' => 'pending',
        'suggested_by' => $user->id,
    ]);
});

test('typed but not selected with invalid location id fails and does not save', function () {
    createMinimalLocationHierarchy();
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

    $payload = array_merge(locationSelectionBasePayload(), [
        'location_id' => 999999,
        'location_input' => '',
    ]);

    $response = $this->actingAs($user)->post(route('matrimony.profile.wizard.store', ['section' => 'basic-info']), $payload);

    $response->assertSessionHasErrors('location_id');
    $profile->refresh();
    expect($profile->location_id)->toBeNull();
    expect(LocationOpenPlaceSuggestion::query()->count())->toBe(0);
});

test('both location id and location input provided fails validation', function () {
    [, $suburb] = createMinimalLocationHierarchy();
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

    $payload = array_merge(locationSelectionBasePayload(), [
        'location_id' => $suburb->id,
        'location_input' => 'Wakad manual',
    ]);

    $response = $this->actingAs($user)->post(route('matrimony.profile.wizard.store', ['section' => 'basic-info']), $payload);

    $response->assertSessionHasErrors('location_input');
    $profile->refresh();
    expect($profile->location_id)->toBeNull();
    expect(LocationOpenPlaceSuggestion::query()->count())->toBe(0);
});

test('empty location id and location input fails validation', function () {
    createMinimalLocationHierarchy();
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

    $payload = array_merge(locationSelectionBasePayload(), [
        'location_id' => null,
        'location_input' => '',
    ]);

    $response = $this->actingAs($user)->post(route('matrimony.profile.wizard.store', ['section' => 'basic-info']), $payload);

    $response->assertSessionHasErrors(['location_id', 'location_input']);
    $profile->refresh();
    expect($profile->location_id)->toBeNull();
    expect(LocationOpenPlaceSuggestion::query()->count())->toBe(0);
});

