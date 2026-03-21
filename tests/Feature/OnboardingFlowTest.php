<?php

namespace Tests\Feature;

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
            'email' => 'onboard-test@example.com',
            'mobile' => '9876543210',
            'gender' => 'male',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'registering_for' => 'son',
            'relation_to_profile' => 'Father',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'onboard-test@example.com',
            'registering_for' => 'son',
            'relation_to_profile' => 'Father',
        ]);
    }
}
