<?php

namespace Tests\Feature;

use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
