<?php

namespace Tests\Feature;

use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @see resources/js/onboarding-dob-picker.js
 * @see .cursor/rules/ONBOARDING-DOB-PICKER.mdc
 */
class OnboardingDobPickerGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_step_two_exposes_dob_picker_metadata_and_no_default_date_when_empty(): void
    {
        $this->seed(\Database\Seeders\MasterLookupSeeder::class);

        $user = User::factory()->create();
        MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'date_of_birth' => null,
        ]);

        $anchorYear = (int) now()->subYears(25)->format('Y');

        $response = $this->actingAs($user)
            ->get(route('matrimony.onboarding.show', ['step' => 2]));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('data-onboarding-dob-wrap', $html);
        $this->assertStringContainsString('data-onboarding-dob-display', $html);
        $this->assertStringContainsString('data-onboarding-dob-calendar', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertStringContainsString('data-dob-anchor-year="'.$anchorYear.'"', $html);
        $this->assertStringContainsString('<input type="hidden" name="date_of_birth" value=""', $html);
    }
}
