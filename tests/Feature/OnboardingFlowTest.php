<?php

namespace Tests\Feature;

use App\Models\MasterChildLivingWith;
use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OnboardingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_onboarding(): void
    {
        $this->get(route('matrimony.onboarding.show', ['step' => 2]))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_without_profile_can_open_onboarding_step_two(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('matrimony.onboarding.show', ['step' => 2]))
            ->assertOk()
            ->assertSeeText(__('onboarding.step2_title'), false);
    }

    public function test_registration_accepts_onboarding_meta_fields(): void
    {
        $this->post(route('register'), [
            'name' => 'Test Parent',
            'mobile' => '9876543210',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'registering_for' => 'parent_guardian',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'mobile' => '9876543210',
            'registering_for' => 'parent_guardian',
            'email' => null,
        ]);
    }

    public function test_draft_profile_full_name_prefills_from_user_name_only_when_registering_for_self(): void
    {
        $selfUser = User::factory()->create([
            'name' => 'Self Registrant',
            'registering_for' => 'self',
        ]);

        $this->actingAs($selfUser)
            ->get(route('matrimony.onboarding.show', ['step' => 2]))
            ->assertOk();

        $profile = MatrimonyProfile::where('user_id', $selfUser->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame('Self Registrant', $profile->full_name);
    }

    public function test_draft_profile_full_name_is_blank_when_registering_for_not_self(): void
    {
        foreach (['parent_guardian', 'sibling', 'relative', 'friend', 'other'] as $for) {
            $user = User::factory()->create([
                'name' => 'Parent Registrant',
                'registering_for' => $for,
            ]);

            $this->actingAs($user)
                ->get(route('matrimony.onboarding.show', ['step' => 2]))
                ->assertOk();

            $profile = MatrimonyProfile::where('user_id', $user->id)->first();
            $this->assertNotNull($profile, "profile for registering_for={$for}");
            $this->assertSame('', $profile->full_name, "full_name for registering_for={$for}");
        }
    }

    public function test_default_bootstrap_full_name_is_empty_when_registering_for_is_null(): void
    {
        $user = User::factory()->create([
            'name' => 'Legacy User',
            'registering_for' => null,
        ]);

        $this->assertSame('', $user->defaultBootstrapProfileFullName());
    }

    public function test_onboarding_step_three_post_uses_saved_children_without_reposting_marital_fields(): void
    {
        $this->seed(\Database\Seeders\MasterLookupSeeder::class);
        $this->seed(\Database\Seeders\ReligionCasteSubCasteSeeder::class);

        $user = User::factory()->create();
        $genderId = MasterGender::where('key', 'male')->where('is_active', true)->value('id');
        $divorcedId = MasterMaritalStatus::where('key', 'divorced')->value('id');
        $livingWithId = MasterChildLivingWith::where('key', 'with_parent')->value('id')
            ?? MasterChildLivingWith::query()->value('id');
        $religion = Religion::where('is_active', true)->first();

        if (! $genderId || ! $divorcedId || ! $livingWithId || ! $religion) {
            $this->markTestSkipped('Seed data incomplete.');
        }

        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Test User',
            'gender_id' => $genderId,
            'marital_status_id' => $divorcedId,
            'has_children' => true,
        ]);

        DB::table('profile_children')->insert([
            'profile_id' => $profile->id,
            'gender' => 'male',
            'age' => 10,
            'child_living_with_id' => $livingWithId,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->post(route('matrimony.onboarding.store', ['step' => 3]), [
            'religion_id' => (string) $religion->id,
            'caste_id' => '',
            'sub_caste_id' => '',
            'height_cm' => '170',
            'location_input' => 'Pune',
        ]);

        $response->assertRedirect(route('matrimony.onboarding.show', ['step' => 4]));
        $response->assertSessionHasNoErrors();
    }

    public function test_onboarding_step_two_does_not_require_location_fields(): void
    {
        $this->seed(\Database\Seeders\MasterLookupSeeder::class);

        $user = User::factory()->create();
        $genderId = MasterGender::where('key', 'male')->where('is_active', true)->value('id');
        $neverId = MasterMaritalStatus::where('key', 'never_married')->value('id');

        if (! $genderId || ! $neverId) {
            $this->markTestSkipped('Seed data incomplete.');
        }

        MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => '',
            'marital_status_id' => $neverId,
        ]);

        $response = $this->actingAs($user)->post(route('matrimony.onboarding.store', ['step' => 2]), [
            'full_name' => 'Card Step One',
            'gender_id' => (string) $genderId,
            'date_of_birth' => '1995-08-20',
            'marital_status_id' => (string) $neverId,
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

        $response->assertRedirect(route('matrimony.onboarding.show', ['step' => 3]));
        $response->assertSessionHasNoErrors();
    }

    public function test_onboarding_step_three_succeeds_when_has_children_yes_without_child_rows(): void
    {
        $this->seed(\Database\Seeders\MasterLookupSeeder::class);
        $this->seed(\Database\Seeders\ReligionCasteSubCasteSeeder::class);

        $user = User::factory()->create();
        $genderId = MasterGender::where('key', 'male')->where('is_active', true)->value('id');
        $divorcedId = MasterMaritalStatus::where('key', 'divorced')->value('id');
        $religion = Religion::where('is_active', true)->first();

        if (! $genderId || ! $divorcedId || ! $religion) {
            $this->markTestSkipped('Seed data incomplete.');
        }

        MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Test User',
            'gender_id' => $genderId,
            'marital_status_id' => $divorcedId,
            'has_children' => true,
        ]);

        $response = $this->actingAs($user)->post(route('matrimony.onboarding.store', ['step' => 3]), [
            'religion_id' => (string) $religion->id,
            'height_cm' => '170',
            'location_input' => 'Pune',
        ]);

        $response->assertRedirect(route('matrimony.onboarding.show', ['step' => 4]));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('matrimony_profiles', [
            'user_id' => $user->id,
            'marital_status_id' => $divorcedId,
        ]);
    }

    public function test_onboarding_step_six_is_removed(): void
    {
        $user = User::factory()->create();
        MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/matrimony/onboarding/6')
            ->assertNotFound();
    }

    public function test_onboarding_step_four_success_redirects_to_photo_upload(): void
    {
        \App\Models\AdminSetting::setValue('onboarding_photo_required', '1');

        $this->seed(\Database\Seeders\MasterLookupSeeder::class);
        $this->seed(\Database\Seeders\EducationSeeder::class);
        $this->seed(\Database\Seeders\EducationCareerTemporarySeeder::class);

        $user = User::factory()->create();
        MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $degreeCode = \App\Models\EducationDegree::query()->value('code');
        $wwId = \Illuminate\Support\Facades\DB::table('working_with_types')->where('is_active', true)->value('id');
        $profId = \Illuminate\Support\Facades\DB::table('professions')->where('is_active', true)->value('id');
        if (! $degreeCode || ! $wwId || ! $profId) {
            $this->markTestSkipped('Education / career seed incomplete.');
        }

        $response = $this->actingAs($user)->post(route('matrimony.onboarding.store', ['step' => 4]), [
            'highest_education' => $degreeCode,
            'working_with_type_id' => (string) $wwId,
            'profession_id' => (string) $profId,
        ]);

        $response->assertRedirect(route('matrimony.profile.upload-photo', ['from' => 'onboarding']));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('info', __('onboarding.after_cards_redirect_photos'));
    }

    public function test_onboarding_step_four_skips_photo_when_onboarding_photo_not_required(): void
    {
        \App\Models\AdminSetting::setValue('onboarding_photo_required', '0');

        $this->seed(\Database\Seeders\MasterLookupSeeder::class);
        $this->seed(\Database\Seeders\EducationSeeder::class);
        $this->seed(\Database\Seeders\EducationCareerTemporarySeeder::class);

        $user = User::factory()->create();
        MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $degreeCode = \App\Models\EducationDegree::query()->value('code');
        $wwId = \Illuminate\Support\Facades\DB::table('working_with_types')->where('is_active', true)->value('id');
        $profId = \Illuminate\Support\Facades\DB::table('professions')->where('is_active', true)->value('id');
        if (! $degreeCode || ! $wwId || ! $profId) {
            $this->markTestSkipped('Education / career seed incomplete.');
        }

        $response = $this->actingAs($user)->post(route('matrimony.onboarding.store', ['step' => 4]), [
            'highest_education' => $degreeCode,
            'working_with_type_id' => (string) $wwId,
            'profession_id' => (string) $profId,
        ]);

        $response->assertRedirect(route('matrimony.onboarding.complete'));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', __('onboarding.all_set'));
    }

    public function test_onboarding_step_five_route_is_removed(): void
    {
        $user = User::factory()->create();
        MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/matrimony/onboarding/5')
            ->assertNotFound();
    }

    public function test_onboarding_step_seven_is_removed(): void
    {
        $this->seed(\Database\Seeders\MasterLookupSeeder::class);

        $user = User::factory()->create();
        MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/matrimony/onboarding/7')
            ->assertNotFound();
    }

    public function test_profile_show_displays_child_living_with_label_when_present(): void
    {
        $this->seed(\Database\Seeders\MasterLookupSeeder::class);
        $this->seed(\Database\Seeders\SubscriptionPlansSeeder::class);

        $livingWithId = MasterChildLivingWith::where('key', 'with_parent')->value('id')
            ?? MasterChildLivingWith::query()->value('id');
        if (! $livingWithId) {
            $this->markTestSkipped('Missing child living-with master data.');
        }

        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        DB::table('profile_children')->insert([
            'profile_id' => $profile->id,
            'child_name' => null,
            'gender' => 'male',
            'age' => 10,
            'child_living_with_id' => $livingWithId,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('matrimony.profile.show', $profile->id));

        $response->assertOk();
        $response->assertSee('Living with', false);
    }

    public function test_profile_show_displays_detailed_completion_label_for_own_profile(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlansSeeder::class);

        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('matrimony.profile.show', $profile->id));

        $response->assertOk();
        $response->assertSee(__('profile.profile_completeness'), false);
    }

    public function test_onboarding_complete_redirects_to_my_profile(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->withSession(['wizard_minimal' => true])
            ->get(route('matrimony.onboarding.complete'));

        $response->assertRedirect(route('matrimony.profile.show', $profile->id));
        $response->assertSessionHas('success', __('onboarding.all_set'));
        $response->assertSessionMissing('wizard_minimal');
    }
}
