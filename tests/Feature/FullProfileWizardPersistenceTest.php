<?php

use App\Models\MatrimonyProfile;
use App\Models\User;
use Database\Seeders\AshtakootaMasterSeeder;
use Database\Seeders\EducationSeeder;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MasterMotherTongueDietLifestyleSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Database\Seeders\OccupationBilingualSeeder;
use Database\Seeders\ReligionCasteSubCasteSeeder;
use Illuminate\Support\Facades\DB;

test('full profile wizard persists one representative field from every rendered section', function () {
    $this->seed([
        MasterLookupSeeder::class,
        MasterMotherTongueDietLifestyleSeeder::class,
        AshtakootaMasterSeeder::class,
        ReligionCasteSubCasteSeeder::class,
        EducationSeeder::class,
        OccupationBilingualSeeder::class,
        MinimalLocationSeeder::class,
    ]);

    $id = static fn (string $table, ?string $key = null): int => (int) DB::table($table)
        ->when($key !== null, fn ($query) => $query->where('key', $key))
        ->value('id');

    $user = User::factory()->create();
    $profile = MatrimonyProfile::factory()->for($user)->create(['lifecycle_state' => 'draft']);
    $locationId = (int) DB::table('addresses')->where('type', 'city')->value('id');
    $genderId = $id('master_genders', 'female');
    $maritalStatusId = $id('master_marital_statuses', 'never_married');
    $religionId = $id('master_religions');
    $casteId = (int) DB::table('master_castes')->where('religion_id', $religionId)->value('id');
    $degreeId = $id('master_education');
    $occupationId = $id('master_occupations');
    $dietId = $id('master_diets');

    $payload = [
        'all' => '1',
        'save_only' => '1',
        'full_name' => 'Full Form Persistence',
        'gender_id' => (string) $genderId,
        'date_of_birth' => '1995-04-12',
        'birth_time' => '06:30:00',
        'birth_city_id' => (string) $locationId,
        'religion_id' => (string) $religionId,
        'caste_id' => (string) $casteId,
        'mother_tongue_id' => (string) $id('master_mother_tongues'),
        'marital_status_id' => (string) $maritalStatusId,
        'has_children' => '0',
        'marriages' => [[
            'marital_status_id' => (string) $maritalStatusId,
        ]],
        'self_addresses' => [[
            'address_type_key' => 'current',
            'address_line' => 'Flat 1, Test Society',
            'location_id' => (string) $locationId,
        ]],
        'height_cm' => '165',
        'weight_kg' => '55',
        'complexion_id' => (string) $id('master_complexions'),
        'physical_build_id' => (string) $id('master_physical_builds'),
        'blood_group_id' => (string) $id('master_blood_groups'),
        'spectacles_lens' => 'spectacles',
        'physical_condition' => 'normal',
        'diet_id' => (string) $dietId,
        'smoking_status_id' => (string) $id('master_smoking_statuses'),
        'drinking_status_id' => (string) $id('master_drinking_statuses'),
        'education_slots' => json_encode([['t' => 'd', 'id' => $degreeId]], JSON_THROW_ON_ERROR),
        'occupation_master_id' => (string) $occupationId,
        'company_name' => 'Test Company',
        'income_period' => 'annual',
        'income_value_type' => 'exact',
        'income_amount' => '600000',
        'father_name' => 'Test Father',
        'father_extra_info' => 'Retired',
        'mother_name' => 'Test Mother',
        'mother_extra_info' => 'Homemaker',
        'family_type_id' => (string) $id('master_family_types'),
        'family_status' => 'middle_class',
        'family_values' => 'traditional',
        'parents_addresses' => [[
            'address_type_key' => 'permanent',
            'address_line' => 'Parents House',
            'location_id' => (string) $locationId,
        ]],
        'has_siblings' => '1',
        'siblings' => [[
            'relation_type' => 'brother',
            'name' => 'Test Brother',
            'marital_status' => 'married',
            'occupation_master_id' => (string) $occupationId,
            'contact_number' => '9000000001',
            'contact_number_2' => '9000000002',
            'contact_number_3' => '9000000003',
            'location_input' => 'Sangli, Miraj, Sangli 416416',
            'notes' => 'youtuber',
        ]],
        'relatives_parents_family' => [[
            'relation_type' => 'paternal_uncle',
            'name' => 'Test Uncle',
            'contact_number' => '9000000004',
            'location_input' => 'Ubhi Peth, Sangli',
            'notes' => 'uncle notes',
        ]],
        'relatives_maternal_family' => [[
            'relation_type' => 'maternal_uncle',
            'name' => 'Test Maternal Uncle',
        ]],
        'other_relatives_text' => 'Other family details',
        'property_details' => "Test Property\nNear market",
        'horoscope' => [
            'rashi_id' => (string) $id('master_rashis'),
            'nakshatra_id' => (string) $id('master_nakshatras'),
            'charan' => '2',
            'gan_id' => (string) $id('master_gans'),
            'nadi_id' => (string) $id('master_nadis'),
            'yoni_id' => (string) $id('master_yonis'),
            'varna_id' => (string) $id('master_varnas'),
            'vashya_id' => (string) $id('master_vashyas'),
            'rashi_lord_id' => (string) $id('master_rashi_lords'),
            'mangal_dosh_type_id' => (string) $id('master_mangal_dosh_types'),
            'devak' => 'Test Devak',
            'kul' => 'Test Kul',
            'gotra' => 'Test Gotra',
        ],
        'extended_narrative' => [
            'narrative_about_me' => 'About test profile',
            'narrative_expectations' => 'Expected partner details',
        ],
        'preferred_age_min' => '24',
        'preferred_age_max' => '30',
        'preferred_height_min_cm' => '150',
        'preferred_height_max_cm' => '180',
        'preferred_diet_ids' => [(string) $dietId],
        'preferred_profile_managed_by' => 'self',
    ];

    $response = $this->actingAs($user)->post(
        route('matrimony.profile.wizard.store', ['section' => 'full', 'all' => 1]),
        $payload,
    );

    $response->assertSessionHasNoErrors();

    $profile->refresh();
    expect($profile->full_name)->toBe('Full Form Persistence')
        ->and((int) $profile->birth_city_id)->toBe($locationId)
        ->and((int) $profile->mother_tongue_id)->toBeGreaterThan(0)
        ->and((int) $profile->diet_id)->toBeGreaterThan(0)
        ->and((int) $profile->smoking_status_id)->toBeGreaterThan(0)
        ->and((int) $profile->drinking_status_id)->toBeGreaterThan(0)
        ->and((string) $profile->highest_education)->not->toBe('')
        ->and((int) $profile->occupation_master_id)->toBe($occupationId)
        ->and($profile->father_extra_info)->toBe('Retired')
        ->and($profile->mother_extra_info)->toBe('Homemaker')
        ->and($profile->family_status)->toBe('middle_class')
        ->and($profile->family_values)->toBe('traditional')
        ->and($profile->other_relatives_text)->toBe('Other family details')
        ->and($profile->property_details)->toBe("Test Property\nNear market");

    $this->assertDatabaseHas('profile_addresses', [
        'profile_id' => $profile->id,
        'address_scope' => 'self',
        'address_line' => 'Flat 1, Test Society',
        'location_id' => $locationId,
    ]);
    $this->assertDatabaseHas('profile_addresses', [
        'profile_id' => $profile->id,
        'address_scope' => 'parents',
        'address_line' => 'Parents House',
        'location_id' => $locationId,
    ]);
    $this->assertDatabaseHas('profile_siblings', [
        'profile_id' => $profile->id,
        'name' => 'Test Brother',
        'occupation_master_id' => $occupationId,
        'contact_number_3' => '9000000003',
        'address_line' => 'Sangli, Miraj, Sangli 416416',
        'notes' => 'youtuber',
    ]);
    $this->assertDatabaseHas('profile_relatives', [
        'profile_id' => $profile->id,
        'relation_type' => 'paternal_uncle',
        'name' => 'Test Uncle',
        'address_line' => 'Ubhi Peth, Sangli',
        'notes' => 'uncle notes',
    ]);
    $this->assertDatabaseHas('profile_relatives', [
        'profile_id' => $profile->id,
        'relation_type' => 'maternal_uncle',
        'name' => 'Test Maternal Uncle',
    ]);
    $this->assertDatabaseHas('matrimony_profiles', [
        'id' => $profile->id,
        'property_details' => "Test Property\nNear market",
    ]);
    $this->assertDatabaseHas('profile_horoscope_data', [
        'profile_id' => $profile->id,
        'charan' => 2,
        'devak' => 'Test Devak',
        'gotra' => 'Test Gotra',
    ]);
    $this->assertDatabaseHas('profile_extended_attributes', [
        'profile_id' => $profile->id,
        'narrative_about_me' => 'About test profile',
        'narrative_expectations' => 'Expected partner details',
    ]);
    $this->assertDatabaseHas('profile_preference_criteria', [
        'profile_id' => $profile->id,
        'preferred_age_min' => 24,
        'preferred_age_max' => 30,
        'preferred_profile_managed_by' => 'self',
    ]);
    $this->assertDatabaseHas('profile_preferred_diets', [
        'profile_id' => $profile->id,
        'diet_id' => $dietId,
    ]);
});
