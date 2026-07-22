<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PO decision 2026-07-22: candidate_mobile is REQUIRED on Suchak manual profile
 * create — every profile needs at least one reachable number because consent
 * delivery depends on it. Previously nullable, which allowed unreachable
 * profiles to be created.
 */
class SuchakManualProfileMobileRequiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_candidate_mobile_is_rejected(): void
    {
        $this->actingAsVerifiedSuchak('9876505701');

        $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'No Mobile Candidate',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertStatus(422)->assertJsonValidationErrors(['candidate_mobile']);
    }

    public function test_malformed_candidate_mobile_is_rejected(): void
    {
        $this->actingAsVerifiedSuchak('9876505702');

        $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Bad Mobile Candidate',
            'candidate_mobile' => '12345',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertStatus(422)->assertJsonValidationErrors(['candidate_mobile']);
    }

    public function test_valid_mobile_still_creates_profile(): void
    {
        $this->actingAsVerifiedSuchak('9876505703');

        $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Good Mobile Candidate',
            'candidate_mobile' => '9876505704',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated()->assertJsonPath('data.outcome', 'created');
    }

    /**
     * The web form is the other door into the same flow — leaving it nullable
     * would let unreachable profiles in while the API rejected them.
     */
    public function test_web_manual_profile_also_requires_mobile(): void
    {
        $user = $this->actingAsVerifiedSuchak('9876505705');

        $this->actingAs($user)
            ->post(route('suchak.manual-profiles.store'), [
                'candidate_name' => 'Web No Mobile Candidate',
                'candidate_gender' => 'female',
                'registering_for' => 'self',
            ])
            ->assertSessionHasErrors(['candidate_mobile']);
    }

    private function actingAsVerifiedSuchak(string $mobile): User
    {
        MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true],
        );

        $user = User::factory()->create([
            'mobile' => $mobile,
            'mobile_verified_at' => now(),
        ]);
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        Sanctum::actingAs($user);

        return $user;
    }
}
