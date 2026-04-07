<?php

namespace Tests\Feature;

use App\Models\Caste;
use App\Models\City;
use App\Models\EducationDegree;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\SubCaste;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Full path: registration → onboarding steps 2–7 → one photo → DB + full wizard visibility.
 */
class FullOnboardingRegistrationE2ETest extends TestCase
{
    use RefreshDatabase;

    /** Fixed markers so you can grep logs / manual QA notes */
    private const E2E_USER_NAME = 'E2E FullFlow User';

    private const E2E_FULL_NAME = 'E2E FullFlow Candidate';

    private const E2E_ABOUT = 'E2E intro: family-oriented, values honesty.';

    private const E2E_EXPECT = 'E2E expectations: understanding partner, mutual respect.';

    public function test_registration_through_onboarding_photo_persists_and_full_wizard_shows_data(): void
    {
        $this->seed(\Database\Seeders\ReligionCasteSubCasteSeeder::class);
        $this->seed(\Database\Seeders\MasterLookupSeeder::class);
        $this->seed(\Database\Seeders\LocationSeeder::class);
        $this->seed(\Database\Seeders\EducationSeeder::class);
        $this->seed(\Database\Seeders\EducationCareerTemporarySeeder::class);

        $mobile = '9660033445';
        $password = 'Password1!';

        $religion = Religion::where('label', 'Hindu')->where('is_active', true)->first();
        $caste = Caste::where('religion_id', $religion->id)->where('label', 'Maratha')->first();
        $subCaste = SubCaste::where('caste_id', $caste->id)->where('label', '96 Kuli')->first();
        $genderId = DB::table('master_genders')->where('key', 'male')->where('is_active', true)->value('id');
        $neverMarriedId = DB::table('master_marital_statuses')->where('key', 'never_married')->value('id');
        $degreeCode = EducationDegree::where('code', 'B.Com')->value('code') ?? EducationDegree::query()->value('code');

        $puneCity = City::where('name', 'Pune')->first();
        $this->assertNotNull($puneCity, 'Pune city required (LocationSeeder).');
        $taluka = DB::table('talukas')->where('id', $puneCity->taluka_id)->first();
        $district = DB::table('districts')->where('id', $taluka->district_id)->first();
        $state = DB::table('states')->where('id', $district->state_id)->first();
        $country = DB::table('countries')->where('id', $state->country_id)->first();

        $wwId = DB::table('working_with_types')->where('slug', 'private_company')->value('id');
        $profId = DB::table('professions')->where('slug', 'software-professional')->value('id');
        $this->assertNotNull($wwId);
        $this->assertNotNull($profId);

        $reg = $this->post(route('register'), [
            'name' => self::E2E_USER_NAME,
            'mobile' => $mobile,
            'password' => $password,
            'password_confirmation' => $password,
            'registering_for' => 'self',
        ]);
        $reg->assertRedirect();

        $user = User::where('mobile', $mobile)->first();
        $this->assertNotNull($user);
        $this->actingAs($user);

        $step2 = $this->post(route('matrimony.onboarding.store', ['step' => 2]), [
            'full_name' => self::E2E_FULL_NAME,
            'gender_id' => $genderId,
            'date_of_birth' => '1992-06-15',
            'marital_status_id' => $neverMarriedId,
            'marriages' => [
                [
                    'marriage_year' => '',
                    'divorce_year' => '',
                    'separation_year' => '',
                    'spouse_death_year' => '',
                    'divorce_status' => '',
                ],
            ],
        ]);
        $step2->assertRedirect(route('matrimony.onboarding.show', ['step' => 3]));
        $step2->assertSessionHasNoErrors();

        $p2 = MatrimonyProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($p2?->gender_id, 'Step 2 should persist gender_id');

        $step3 = $this->post(route('matrimony.onboarding.store', ['step' => 3]), [
            'religion_id' => (string) $religion->id,
            'caste_id' => (string) $caste->id,
            'sub_caste_id' => (string) $subCaste->id,
        ]);
        $step3->assertRedirect(route('matrimony.onboarding.show', ['step' => 4]));
        $step3->assertSessionHasNoErrors();

        $step4 = $this->post(route('matrimony.onboarding.store', ['step' => 4]), [
            'highest_education' => $degreeCode,
            'working_with_type_id' => (string) $wwId,
            'profession_id' => (string) $profId,
            'company_name' => 'E2E Company Pvt Ltd',
        ]);
        $step4->assertRedirect(route('matrimony.onboarding.show', ['step' => 5]));
        $step4->assertSessionHasNoErrors();

        $step5 = $this->post(route('matrimony.onboarding.store', ['step' => 5]), [
            'height_cm' => '172',
            'country_id' => (string) $country->id,
            'state_id' => (string) $state->id,
            'district_id' => (string) $district->id,
            'taluka_id' => (string) $taluka->id,
            'city_id' => (string) $puneCity->id,
            'address_line' => 'E2E flat 1, test street',
        ]);
        $step5->assertRedirect(route('matrimony.onboarding.show', ['step' => 6]));
        $step5->assertSessionHasNoErrors();

        $step6 = $this->post(route('matrimony.onboarding.store', ['step' => 6]), [
            'extended_narrative' => [
                'narrative_about_me' => self::E2E_ABOUT,
            ],
        ]);
        $step6->assertRedirect(route('matrimony.onboarding.show', ['step' => 7]));
        $step6->assertSessionHasNoErrors();

        $step7 = $this->post(route('matrimony.onboarding.store', ['step' => 7]), [
            'preferred_age_min' => 25,
            'preferred_age_max' => 35,
            'preferred_height_min_cm' => 155,
            'preferred_height_max_cm' => 175,
            'preferred_religion_ids' => [(string) $religion->id],
            'preferred_caste_ids' => [(string) $caste->id],
            'preferred_district_ids' => [(string) $district->id],
            'education_open_all' => '1',
            'diet_open_all' => '1',
            'extended_narrative' => [
                'narrative_expectations' => self::E2E_EXPECT,
            ],
        ]);
        $step7->assertRedirect(route('matrimony.profile.upload-photo', ['from' => 'onboarding']));
        $step7->assertSessionHasNoErrors();

        $profile = MatrimonyProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($profile);

        $this->assertDatabaseHas('matrimony_profiles', [
            'id' => $profile->id,
            'full_name' => self::E2E_FULL_NAME,
            'gender_id' => $genderId,
            'marital_status_id' => $neverMarriedId,
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
            'height_cm' => 172,
            'highest_education' => $degreeCode,
            'company_name' => 'E2E Company Pvt Ltd',
        ]);

        $this->assertDatabaseHas('profile_extended_attributes', [
            'profile_id' => $profile->id,
        ]);
        $ext = DB::table('profile_extended_attributes')->where('profile_id', $profile->id)->first();
        $this->assertStringContainsString('E2E intro', (string) $ext->narrative_about_me);
        $this->assertStringContainsString('E2E expectations', (string) $ext->narrative_expectations);

        $this->assertDatabaseHas('profile_preferred_religions', [
            'profile_id' => $profile->id,
            'religion_id' => $religion->id,
        ]);
        $this->assertDatabaseHas('profile_preferred_districts', [
            'profile_id' => $profile->id,
            'district_id' => $district->id,
        ]);

        // Onboarding lock blocks the full wizard until the user finishes or explicitly completes onboarding.
        $this->get(route('matrimony.onboarding.complete'))
            ->assertRedirect(route('matrimony.profile.show', $profile->id));
        $profile->refresh();
        $this->assertNull($profile->card_onboarding_resume_step);

        // Wizard checks: verify narrative and sections in UI (photo upload still exercised below).
        $wizardSession = ['wizard_minimal' => false];

        $aboutMe = $this->withSession($wizardSession)->get(route('matrimony.profile.wizard.section', ['section' => 'about-me']));
        $aboutMe->assertOk();
        $aboutHtml = $aboutMe->getContent() ?: '';
        $this->assertStringContainsString('E2E intro', $aboutHtml);

        // Partner-preference workspace is multi-topic; narrative_expectations is asserted in DB above.
        $aboutPref = $this->withSession($wizardSession)->get(route('matrimony.profile.wizard.section', ['section' => 'about-preferences']));
        $aboutPref->assertOk();

        $edu = $this->withSession($wizardSession)->get(route('matrimony.profile.wizard.section', ['section' => 'education-career']));
        $edu->assertOk();
        $this->assertStringContainsString('E2E Company', $edu->getContent() ?: '');

        $file = UploadedFile::fake()->image('e2e_upload.jpg', 900, 900);
        $photoResponse = $this->post(route('matrimony.profile.upload-photo'), [
            'profile_photo' => $file,
        ]);
        $photoResponse->assertRedirect(route('matrimony.profile.upload-photo'));
        $photoResponse->assertSessionHasNoErrors();

        $profile->refresh();
        $this->assertNotNull($profile->profile_photo);
        $this->assertGreaterThan(0, DB::table('profile_photos')->where('profile_id', $profile->id)->count());
    }
}
