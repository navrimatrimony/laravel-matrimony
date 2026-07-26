<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/v1/suchak/consent-requests — the ONLY surface a pending consent
 * claim is visible on.
 *
 * Consent-first linking (2026-07-26) hides an un-consented claim from the
 * customer list, customer detail, the share card and the dashboard, and 403s
 * on both read and write. Without this feed a Suchak who loses the candidate's
 * reply has no way back to the resend endpoint — a dead end. These tests pin
 * the two halves of that contract: the claim APPEARS while consent is pending,
 * and DISAPPEARS the moment it is accepted (because the person is now a real
 * customer, visible in the customer list instead).
 */
class SuchakConsentRequestsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pending_claim_is_listed_with_everything_needed_to_resend(): void
    {
        $this->actingAsVerifiedSuchak('9876508001');
        $this->existingMember('9876508002', 'Waiting Candidate');

        $created = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Waiting Candidate',
            'candidate_mobile' => '9876508002',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
            'use_existing_profile' => true,
        ])->assertOk()->assertJsonPath('data.pending_claim', true);

        $representationId = (int) $created->json('data.representation_id');
        $consentId = (int) $created->json('data.consent_id');

        // Invisible everywhere else — that is exactly why this endpoint exists.
        $this->getJson('/api/v1/suchak/customers')
            ->assertOk()
            ->assertJsonPath('data.customers', []);

        $response = $this->getJson('/api/v1/suchak/consent-requests')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $row = $response->json('data.consent_requests.0');

        $this->assertSame($representationId, $row['representation_id']);
        $this->assertSame((int) $created->json('data.profile_id'), $row['profile_id']);
        $this->assertSame($consentId, $row['consent_id']);
        $this->assertSame(SuchakConsent::STATUS_REQUESTED, $row['consent_status']);
        $this->assertTrue($row['can_resend']);
        $this->assertNotNull($row['requested_at']);

        // Masked, never raw: this person has not consented to anything yet.
        $this->assertSame('Waiting C.', $row['candidate_name']);
        $this->assertSame('98765•••02', $row['consent_mobile']);

        // The raw token is hashed at rest, so a read can never hand back a
        // usable link — the app must go through resend.
        $this->assertFalse($row['consent_link_available']);

        $this->postJson("/api/v1/suchak/consents/{$consentId}/resend")
            ->assertOk()
            ->assertJsonPath('data.consent_id', $consentId);
    }

    public function test_the_claim_leaves_the_list_once_consent_is_accepted(): void
    {
        $this->actingAsVerifiedSuchak('9876508011');
        $this->existingMember('9876508012', 'Accepting Candidate');

        $created = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Accepting Candidate',
            'candidate_mobile' => '9876508012',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
            'use_existing_profile' => true,
        ])->assertOk();

        $representationId = (int) $created->json('data.representation_id');

        $this->getJson('/api/v1/suchak/consent-requests')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->acceptConsentFor($representationId);

        // Promoted server-side: gone from here, present as a real customer.
        $this->getJson('/api/v1/suchak/consent-requests')
            ->assertOk()
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.consent_requests', []);

        $this->getJson('/api/v1/suchak/customers')
            ->assertOk()
            ->assertJsonPath('data.customers.0.representation_id', $representationId);
    }

    public function test_another_suchaks_claim_is_never_listed(): void
    {
        $rival = $this->verifiedSuchakAccount('9876508021', 'Rival Kendra');
        $profileId = $this->existingMember('9876508022', 'Rival Candidate');

        SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $rival->id,
            'matrimony_profile_id' => $profileId,
            'representation_mode' => SuchakProfileRepresentation::MODE_MATCHED_EXISTING_PROFILE,
            'representation_status' => SuchakProfileRepresentation::STATUS_CONSENT_PENDING,
            'consent_status' => SuchakProfileRepresentation::CONSENT_REQUESTED,
        ]);

        $this->actingAsVerifiedSuchak('9876508023');

        $this->getJson('/api/v1/suchak/consent-requests')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_a_suchaks_own_manual_profile_is_not_a_consent_request(): void
    {
        $this->actingAsVerifiedSuchak('9876508031');

        $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Brand New Candidate',
            'candidate_mobile' => '9876508032',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated();

        // A manual profile links immediately — it is a customer, not a claim.
        $this->getJson('/api/v1/suchak/consent-requests')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    private function acceptConsentFor(int $representationId): void
    {
        $consent = SuchakConsent::query()
            ->where('representation_id', $representationId)
            ->latest('id')
            ->firstOrFail();

        app(SuchakConsentService::class)
            ->recordPublicConsentDecision($consent, SuchakConsent::STATUS_ACCEPTED);
    }

    private function actingAsVerifiedSuchak(string $mobile): SuchakAccount
    {
        $account = $this->verifiedSuchakAccount($mobile);
        Sanctum::actingAs($account->user);

        return $account;
    }

    private function verifiedSuchakAccount(string $mobile, ?string $suchakName = null): SuchakAccount
    {
        foreach (['male' => 'Male', 'female' => 'Female'] as $key => $label) {
            MasterGender::query()->firstOrCreate(['key' => $key], ['label' => $label, 'is_active' => true]);
        }

        $user = User::factory()->create(['mobile' => $mobile, 'mobile_verified_at' => now()]);

        return SuchakAccount::factory()->create(array_filter([
            'user_id' => $user->id,
            'suchak_name' => $suchakName,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ], static fn ($value): bool => $value !== null));
    }

    private function existingMember(string $mobile, string $fullName): int
    {
        $member = User::factory()->create(['mobile' => $mobile, 'mobile_verified_at' => now()]);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $member->id,
            'full_name' => $fullName,
            'gender_id' => (int) DB::table('master_genders')->where('key', 'female')->value('id'),
        ]);

        return (int) $profile->id;
    }
}
