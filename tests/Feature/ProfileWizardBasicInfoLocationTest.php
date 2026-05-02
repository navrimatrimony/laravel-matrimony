<?php

use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\Location;
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

/**
 * Valid SSOT chain: state → district → city (ids aligned with legacy City row when needed).
 *
 * @return array{pune: Location, location_id: int}
 */
function wizardTestCreatePuneLocation(): array
{
    $legacyCity = City::query()->where('name', 'Pune City')->firstOrFail();

    $state = Location::query()->create([
        'name' => 'Maharashtra',
        'slug' => 'mh-wiz-'.$legacyCity->id,
        'type' => 'state',
        'parent_id' => null,
        'state_code' => 'MH',
        'district_code' => null,
        'is_active' => true,
    ]);

    $district = Location::query()->create([
        'name' => 'Pune',
        'slug' => 'pune-dist-wiz-'.$legacyCity->id,
        'type' => 'district',
        'parent_id' => $state->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
    ]);

    $pune = Location::query()->create([
        'id' => $legacyCity->id,
        'name' => 'Pune',
        'slug' => 'pune-city-wiz-'.$legacyCity->id,
        'type' => 'city',
        'parent_id' => $district->id,
        'state_code' => 'MH',
        'district_code' => 'PN',
        'is_active' => true,
    ]);

    return ['pune' => $pune, 'location_id' => (int) $pune->id];
}

test('basic info reads flat location_id when core[] is present but has no location keys', function () {
    ['location_id' => $locationId] = wizardTestCreatePuneLocation();

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
        'location_id' => (string) $locationId,
        'location_input' => '',
        'address_line' => 'Line only',
    ]);

    $response->assertSessionHasNoErrors();
    $profile->refresh();
    expect($profile->location_id)->toBe($locationId);
});

test('basic info save persists residence core fields', function () {
    ['location_id' => $locationId] = wizardTestCreatePuneLocation();

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
        'location_id' => (string) $locationId,
        'location_input' => '',
        'address_line' => 'Flat 1, Sample Society, Pune',
    ]);

    $response->assertSessionHasNoErrors();

    $profile->refresh();
    expect($profile->country_id)->toBe($country->id)
        ->and($profile->state_id)->toBe($state->id)
        ->and($profile->district_id)->toBe($district->id)
        ->and($profile->taluka_id)->toBe($taluka->id)
        ->and($profile->location_id)->toBe($locationId)
        ->and($profile->address_line)->toBe('Flat 1, Sample Society, Pune');
});

test('basic info GET shows saved residence line in typeahead value', function () {
    ['pune' => $pune] = wizardTestCreatePuneLocation();

    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
    $country = Country::where('name', 'India')->firstOrFail();
    $state = State::where('name', 'Maharashtra')->firstOrFail();
    $district = District::where('name', 'Pune')->firstOrFail();
    $taluka = Taluka::where('name', 'Haveli')->firstOrFail();

    $profile->update([
        'country_id' => $country->id,
        'state_id' => $state->id,
        'district_id' => $district->id,
        'taluka_id' => $taluka->id,
        'location_id' => $pune->id,
        'address_line' => 'Saved society line',
    ]);

    $line = $profile->fresh()->residenceLocationDisplayLine();
    expect($line)->not->toBe('')->and($line)->toContain('Pune');

    $this->actingAs($user)
        ->get(route('matrimony.profile.wizard.section', ['section' => 'basic-info']))
        ->assertOk()
        ->assertSee('Saved society line', false);
});

test('onboarding step 3 GET shows saved residence line in typeahead value', function () {
    ['pune' => $pune] = wizardTestCreatePuneLocation();

    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
    $country = Country::where('name', 'India')->firstOrFail();
    $state = State::where('name', 'Maharashtra')->firstOrFail();
    $district = District::where('name', 'Pune')->firstOrFail();
    $taluka = Taluka::where('name', 'Haveli')->firstOrFail();

    $profile->update([
        'country_id' => $country->id,
        'state_id' => $state->id,
        'district_id' => $district->id,
        'taluka_id' => $taluka->id,
        'location_id' => $pune->id,
        'address_line' => 'Saved detailed line (wizard only)',
    ]);

    $this->actingAs($user)
        ->get(route('matrimony.onboarding.show', ['step' => 3]))
        ->assertOk()
        ->assertSee('Pune', false);
});
