<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Partner preferences drafted for the REPRESENTED candidate (PO 2026-07-22).
 *
 * The member endpoint derives them for $request->user(); for a Suchak that
 * would be the Suchak's own profile — the wrong person. This adapter passes
 * the candidate's user to the same RegistrationPartnerPreferenceService.
 */
class SuchakPartnerPreferenceAutoDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_draft_targets_the_candidate_not_the_suchak(): void
    {
        [$representationId, $profileId, $suchakUserId] = $this->createRepresentation('9876506201', '9876506202');

        $this->postJson("/api/v1/suchak/nxt/{$representationId}/preferences/auto-draft")
            ->assertOk()
            ->assertJsonPath('representation_id', $representationId)
            ->assertJsonPath('profile_id', $profileId);

        // Whatever was written must belong to the candidate's profile, never
        // the Suchak's own.
        $suchakProfileId = DB::table('matrimony_profiles')
            ->where('user_id', $suchakUserId)
            ->value('id');
        if ($suchakProfileId !== null) {
            $this->assertNotSame(
                (int) $suchakProfileId,
                $profileId,
                'Candidate and Suchak must be different profiles for this test to mean anything.'
            );
            $this->assertSame(
                0,
                DB::table('profile_preference_criteria')->where('profile_id', $suchakProfileId)->count(),
                'Preferences leaked onto the Suchak own profile.'
            );
        }
    }

    public function test_other_suchak_cannot_draft_for_foreign_representation(): void
    {
        [$representationId] = $this->createRepresentation('9876506203', '9876506204');

        $intruder = User::factory()->create(['mobile' => '9876506205', 'mobile_verified_at' => now()]);
        SuchakAccount::factory()->create([
            'user_id' => $intruder->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        Sanctum::actingAs($intruder);

        $this->postJson("/api/v1/suchak/nxt/{$representationId}/preferences/auto-draft")
            ->assertNotFound();
    }

    /** @return array{0: int, 1: int, 2: int} representation, profile, suchak user */
    private function createRepresentation(string $suchakMobile, string $candidateMobile): array
    {
        MasterGender::query()->firstOrCreate(['key' => 'female'], ['label' => 'Female', 'is_active' => true]);

        $user = User::factory()->create(['mobile' => $suchakMobile, 'mobile_verified_at' => now()]);
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Preference Candidate',
            'candidate_mobile' => $candidateMobile,
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated();

        return [
            (int) $create->json('data.representation_id'),
            (int) $create->json('data.profile_id'),
            (int) $user->id,
        ];
    }
}
