<?php

namespace Tests\Feature\Intake;

use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeOnboardingAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_open_intake_upload_while_card_onboarding_is_locked(): void
    {
        $user = User::factory()->create();
        MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'card_onboarding_resume_step' => 2,
        ]);

        $this->actingAs($user)
            ->get(route('intake.upload'))
            ->assertOk();
    }
}
