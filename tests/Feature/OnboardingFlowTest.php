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
        ]);

        $response->assertRedirect(route('matrimony.onboarding.show', ['step' => 4]));
        $response->assertSessionHasNoErrors();
    }

    public function test_onboarding_validation_for_children_redirects_to_step_two_not_three(): void
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
        ]);

        $response->assertRedirect(route('matrimony.onboarding.show', ['step' => 2]));
        $response->assertSessionHasErrors('children');
        $response2 = $this->actingAs($user)->get(route('matrimony.onboarding.show', ['step' => 2]));
        $response2->assertOk();
        $response2->assertSee('name="marital_status_id" value="'.$divorcedId.'"', false);
        $this->assertDatabaseHas('matrimony_profiles', [
            'user_id' => $user->id,
            'marital_status_id' => $divorcedId,
        ]);
    }

    public function test_onboarding_step_six_route_is_available(): void
    {
        $user = User::factory()->create();
        MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/matrimony/onboarding/6')
            ->assertOk();
    }

    public function test_onboarding_step_five_success_redirects_to_step_six(): void
    {
        $user = User::factory()->create();
        MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('matrimony.onboarding.store', ['step' => 5]), [
            'height_cm' => '170',
        ]);

        $response->assertRedirect(route('matrimony.onboarding.show', ['step' => 6]));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', __('onboarding.saved_continue'));
    }

    public function test_onboarding_step_seven_success_redirects_to_photo_upload(): void
    {
        $this->seed(\Database\Seeders\MasterLookupSeeder::class);

        $user = User::factory()->create();
        MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('matrimony.onboarding.store', ['step' => 7]), [
            'preferred_age_min' => 24,
            'preferred_age_max' => 32,
        ]);

        $response->assertRedirect(route('matrimony.profile.upload-photo', ['from' => 'onboarding']));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('info', __('onboarding.after_step7_redirect_photos'));
    }

    public function test_onboarding_step_seven_reflects_saved_profile_district_selection(): void
    {
        $this->seed(\Database\Seeders\LocationSeeder::class);

        $district = DB::table('districts')->orderBy('id')->first();
        if (! $district) {
            $this->markTestSkipped('No district data available.');
        }

        $user = User::factory()->create();
        MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'district_id' => (int) $district->id,
        ]);

        $response = $this->actingAs($user)->get(route('matrimony.onboarding.show', ['step' => 7]));

        $response->assertOk();
        $response->assertSee('name="preferred_district_ids[]" value="'.$district->id.'"', false);
        $response->assertSee('name="preferred_district_ids[]" value="'.$district->id.'" checked', false);
    }

    public function test_profile_show_displays_child_living_with_label_when_present(): void
    {
        $this->seed(\Database\Seeders\MasterLookupSeeder::class);

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
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('matrimony.profile.show', $profile->id));

        $response->assertOk();
        $response->assertSee('Detailed coverage', false);
    }

    public function test_onboarding_complete_redirects_to_profiles_index(): void
    {
        $user = User::factory()->create();
        MatrimonyProfile::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->withSession(['wizard_minimal' => true])
            ->get(route('matrimony.onboarding.complete'));

        $response->assertRedirect(route('matrimony.profiles.index'));
        $response->assertSessionHas('success', __('onboarding.all_set'));
        $response->assertSessionMissing('wizard_minimal');
    }
}
