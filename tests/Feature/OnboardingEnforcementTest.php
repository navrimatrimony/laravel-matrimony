<?php

namespace Tests\Feature;

use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_resume_step_six_redirects_to_photo_upload(): void
    {
        $user = User::factory()->create();
        MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
            'card_onboarding_resume_step' => 6,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('matrimony.profile.upload-photo', ['from' => 'onboarding']))
            ->assertSessionHas('info', __('onboarding.resume_photo_notice'));
    }

    public function test_photo_phase_redirects_to_upload_photo_with_from_onboarding(): void
    {
        $user = User::factory()->create();
        MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
            'card_onboarding_resume_step' => MatrimonyProfile::CARD_ONBOARDING_PHOTO_RESUME_STEP,
        ]);

        $this->actingAs($user)
            ->get(route('matrimony.profiles.index'))
            ->assertRedirect(route('matrimony.profile.upload-photo', ['from' => 'onboarding']))
            ->assertSessionHas('info', __('onboarding.resume_photo_notice'));
    }

    public function test_onboarding_complete_clears_resume_pointer(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
            'card_onboarding_resume_step' => 4,
        ]);

        $this->actingAs($user)
            ->get(route('matrimony.onboarding.complete'))
            ->assertRedirect(route('matrimony.profile.show', $profile->id));

        $this->assertNull($profile->fresh()->card_onboarding_resume_step);
    }
}
