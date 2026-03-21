<?php

use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Models\State;
use App\Models\Taluka;
use App\Models\User;
use Database\Seeders\MinimalLocationSeeder;

beforeEach(function () {
    $this->seed(\Database\Seeders\MasterLookupSeeder::class);
    $this->seed(MinimalLocationSeeder::class);
});

test('basic info reads flat city_id when core[] is present but has no location keys', function () {
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
    $genderId = MasterGender::where('key', 'male')->where('is_active', true)->value('id');
    $neverId = MasterMaritalStatus::where('key', 'never_married')->value('id');
    if (! $genderId || ! $neverId) {
        $this->markTestSkipped('Master lookups not seeded.');
    }

    $country = Country::where('name', 'India')->firstOrFail();
    $state = State::where('name', 'Maharashtra')->firstOrFail();
    $district = District::where('name', 'Pune')->firstOrFail();
    $taluka = Taluka::where('name', 'Haveli')->firstOrFail();
    $city = City::where('name', 'Pune City')->firstOrFail();

    $response = $this->actingAs($user)->post(route('matrimony.profile.wizard.store', ['section' => 'basic-info']), [
        'full_name' => 'Core Array User',
        'gender_id' => $genderId,
        'marital_status_id' => $neverId,
        'marriages' => [
            ['id' => null, 'marriage_year' => '', 'divorce_year' => '', 'divorce_status' => ''],
        ],
        'core' => ['_noise' => '1'],
        'country_id' => (string) $country->id,
        'state_id' => (string) $state->id,
        'district_id' => (string) $district->id,
        'taluka_id' => (string) $taluka->id,
        'city_id' => (string) $city->id,
        'address_line' => 'Line only',
    ]);

    $response->assertSessionHasNoErrors();
    $profile->refresh();
    expect($profile->city_id)->toBe($city->id);
});

test('basic info save persists residence core fields', function () {
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
    $genderId = MasterGender::where('key', 'male')->where('is_active', true)->value('id');
    $neverId = MasterMaritalStatus::where('key', 'never_married')->value('id');
    if (! $genderId || ! $neverId) {
        $this->markTestSkipped('Master lookups not seeded.');
    }

    $country = Country::where('name', 'India')->firstOrFail();
    $state = State::where('name', 'Maharashtra')->firstOrFail();
    $district = District::where('name', 'Pune')->firstOrFail();
    $taluka = Taluka::where('name', 'Haveli')->firstOrFail();
    $city = City::where('name', 'Pune City')->firstOrFail();

    $response = $this->actingAs($user)->post(route('matrimony.profile.wizard.store', ['section' => 'basic-info']), [
        'full_name' => 'Location Test User',
        'gender_id' => $genderId,
        'marital_status_id' => $neverId,
        'marriages' => [
            ['id' => null, 'marriage_year' => '', 'divorce_year' => '', 'divorce_status' => ''],
        ],
        'country_id' => (string) $country->id,
        'state_id' => (string) $state->id,
        'district_id' => (string) $district->id,
        'taluka_id' => (string) $taluka->id,
        'city_id' => (string) $city->id,
        'address_line' => 'Flat 1, Sample Society, Pune',
    ]);

    $response->assertSessionHasNoErrors();

    $profile->refresh();
    expect($profile->country_id)->toBe($country->id)
        ->and($profile->state_id)->toBe($state->id)
        ->and($profile->district_id)->toBe($district->id)
        ->and($profile->taluka_id)->toBe($taluka->id)
        ->and($profile->city_id)->toBe($city->id)
        ->and($profile->address_line)->toBe('Flat 1, Sample Society, Pune');
});

test('basic info GET shows saved residence line in typeahead value', function () {
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
    $country = Country::where('name', 'India')->firstOrFail();
    $state = State::where('name', 'Maharashtra')->firstOrFail();
    $district = District::where('name', 'Pune')->firstOrFail();
    $taluka = Taluka::where('name', 'Haveli')->firstOrFail();
    $city = City::where('name', 'Pune City')->firstOrFail();

    $profile->update([
        'country_id' => $country->id,
        'state_id' => $state->id,
        'district_id' => $district->id,
        'taluka_id' => $taluka->id,
        'city_id' => $city->id,
        'address_line' => 'Saved society line',
    ]);

    $line = $profile->fresh()->residenceLocationDisplayLine();
    expect($line)->toContain('Pune City')->and($line)->toContain('Maharashtra');

    $this->actingAs($user)
        ->get(route('matrimony.profile.wizard.section', ['section' => 'basic-info']))
        ->assertOk()
        ->assertSee('Pune City', false)
        ->assertSee('Saved society line', false);
});

test('onboarding step 5 GET shows saved residence line in typeahead value', function () {
    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
    $country = Country::where('name', 'India')->firstOrFail();
    $state = State::where('name', 'Maharashtra')->firstOrFail();
    $district = District::where('name', 'Pune')->firstOrFail();
    $taluka = Taluka::where('name', 'Haveli')->firstOrFail();
    $city = City::where('name', 'Pune City')->firstOrFail();

    $profile->update([
        'country_id' => $country->id,
        'state_id' => $state->id,
        'district_id' => $district->id,
        'taluka_id' => $taluka->id,
        'city_id' => $city->id,
        'address_line' => 'Step5 visible line',
    ]);

    $this->actingAs($user)
        ->get(route('matrimony.onboarding.show', ['step' => 5]))
        ->assertOk()
        ->assertSee('Pune City', false)
        ->assertSee('Step5 visible line', false);
});
