<?php

namespace Tests\Feature;

use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract / regression guard for onboarding validation redirects.
 *
 * Historically, redirecting to the "earliest" card that matched error keys sent users
 * from POST step 3 back to step 2 (UI felt like "step 2 submit → step 1").
 * Validation failures must return to the submitted URL step only.
 *
 * @see \App\Http\Controllers\OnboardingController::store
 */
class OnboardingValidationRedirectGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_failure_on_step_three_never_redirects_to_step_two(): void
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

        $response->assertSessionHasErrors();
        $response->assertRedirect(route('matrimony.onboarding.show', ['step' => 3]));

        $path = (string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH);
        $this->assertStringContainsString('onboarding/3', $path, 'Must redirect to submitted step 3');
        $this->assertStringNotContainsString('onboarding/2', $path, 'Must not redirect to an earlier onboarding card');
    }
}
