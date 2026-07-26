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
 * SECURITY (2026-07-26): the represented-profile endpoints authorised on
 * OWNERSHIP alone (suchak_account_id), never on consent. A Suchak could link a
 * self-registered member through the duplicate → "use existing profile" branch
 * and immediately start reading/editing that person's profile, before they had
 * agreed to anything.
 *
 * CONSENT-FIRST LINKING (PO rule, same day): the fix is not just a gate — the
 * LINK itself must not exist before consent. Asking to represent an existing
 * person now creates only a pending CLAIM (matched_existing_profile + no valid
 * consent) plus the consent request. That claim:
 *   - never appears in the customer list or customer detail,
 *   - is not readable or writable,
 *   - becomes a real representation ONLY when consent is accepted.
 *
 * A Suchak's own manual profiles are unaffected — they link immediately.
 */
class SuchakRepresentedProfileConsentGateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_consent_response_carries_the_consent_handoff_but_links_nothing(): void
    {
        $this->actingAsVerifiedSuchak('9876507001');
        $this->existingMember('9876507002', 'Linked Candidate');

        $response = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Linked Candidate',
            'candidate_mobile' => '9876507002',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
            'use_existing_profile' => true,
        ])->assertOk()
            ->assertJsonPath('data.outcome', 'consent_requested')
            ->assertJsonPath('data.linked', false)
            ->assertJsonPath('data.pending_claim', true);

        $consentId = $response->json('data.consent_id');
        $this->assertIsInt($consentId, 'The app cannot open a consent sheet without an id.');
        $this->assertSame(SuchakConsent::STATUS_REQUESTED, $response->json('data.consent_status'));
        $this->assertSame(SuchakConsent::METHOD_SUCHAK_RELAYED_LINK, $response->json('data.consent_method'));
        $this->assertNotNull($response->json('data.consent_url'));
        $this->assertNotNull($response->json('data.whatsapp_url'));
        $this->assertNotNull($response->json('data.forward_message'));
        $this->assertTrue($response->json('data.consent_link_available'));
        $this->assertFalse($response->json('data.consent_reused'));

        $this->assertDatabaseHas('suchak_consents', [
            'id' => $consentId,
            'representation_id' => $response->json('data.representation_id'),
        ]);
    }

    public function test_requesting_consent_creates_no_usable_customer(): void
    {
        $this->actingAsVerifiedSuchak('9876507011');
        $this->existingMember('9876507012', 'Not A Customer Yet');

        $link = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Not A Customer Yet',
            'candidate_mobile' => '9876507012',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
            'use_existing_profile' => true,
        ])->assertOk();

        $representationId = (int) $link->json('data.representation_id');

        // Not in the customer list.
        $this->getJson('/api/v1/suchak/customers')
            ->assertOk()
            ->assertJsonPath('data.customers', []);

        // Not reachable as a customer.
        $this->getJson("/api/v1/suchak/customers/{$representationId}")->assertStatus(404);
        $this->getJson("/api/v1/suchak/customers/{$representationId}/share-card")->assertStatus(404);

        // Not readable — the gate now covers reads, not only writes.
        $this->getJson("/api/v1/suchak/nxt/{$representationId}/profile")
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'consent_required');
        $this->getJson("/api/v1/suchak/nxt/{$representationId}/consent-contacts")
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'consent_required');

        // Not writable.
        $this->postJson("/api/v1/suchak/nxt/{$representationId}/preferences/auto-draft")
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'consent_required');

        // No customer record exists at all yet.
        $this->assertDatabaseMissing('suchak_customer_contexts', [
            'representation_id' => $representationId,
        ]);
        $this->assertTrue(
            SuchakProfileRepresentation::query()->findOrFail($representationId)->isPendingConsentClaim(),
        );
    }

    public function test_accepted_consent_promotes_the_claim_into_a_real_customer(): void
    {
        $this->actingAsVerifiedSuchak('9876507013');
        $this->existingMember('9876507014', 'Accepting Candidate');

        $link = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Accepting Candidate',
            'candidate_mobile' => '9876507014',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
            'use_existing_profile' => true,
        ])->assertOk();
        $representationId = (int) $link->json('data.representation_id');

        $this->acceptConsentFor($representationId);

        $representation = SuchakProfileRepresentation::query()->findOrFail($representationId);
        $this->assertSame(SuchakProfileRepresentation::STATUS_ACTIVE, $representation->representation_status);
        $this->assertTrue($representation->hasValidConsent());
        $this->assertFalse($representation->isPendingConsentClaim());

        // The customer appears the moment consent is accepted — not before.
        $this->getJson('/api/v1/suchak/customers')
            ->assertOk()
            ->assertJsonPath('data.customers.0.representation_id', $representationId);
        $this->getJson("/api/v1/suchak/customers/{$representationId}")->assertOk();
        $this->getJson("/api/v1/suchak/nxt/{$representationId}/profile")->assertOk();
        $this->postJson("/api/v1/suchak/nxt/{$representationId}/preferences/auto-draft")->assertOk();

        $this->assertDatabaseHas('suchak_customer_contexts', [
            'representation_id' => $representationId,
        ]);
    }

    public function test_rejected_consent_leaves_no_link_at_all(): void
    {
        $this->actingAsVerifiedSuchak('9876507015');
        $this->existingMember('9876507016', 'Refusing Candidate');

        $link = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Refusing Candidate',
            'candidate_mobile' => '9876507016',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
            'use_existing_profile' => true,
        ])->assertOk();
        $representationId = (int) $link->json('data.representation_id');

        /** @var SuchakConsent $consent */
        $consent = SuchakConsent::query()->where('representation_id', $representationId)->latest('id')->firstOrFail();
        app(SuchakConsentService::class)->recordPublicConsentDecision($consent, SuchakConsent::STATUS_REJECTED);

        $representation = SuchakProfileRepresentation::query()->findOrFail($representationId);
        $this->assertSame(SuchakProfileRepresentation::STATUS_REJECTED, $representation->representation_status);
        $this->assertTrue($representation->isPendingConsentClaim());

        $this->getJson('/api/v1/suchak/customers')
            ->assertOk()
            ->assertJsonPath('data.customers', []);
        $this->getJson("/api/v1/suchak/customers/{$representationId}")->assertStatus(404);
        $this->getJson("/api/v1/suchak/nxt/{$representationId}/profile")
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'consent_required');
        $this->assertDatabaseMissing('suchak_customer_contexts', [
            'representation_id' => $representationId,
        ]);
    }

    public function test_consent_cannot_be_requested_for_someone_another_suchak_already_holds(): void
    {
        $profileId = $this->existingMember('9876507018', 'Taken Candidate');
        $otherAccount = $this->verifiedSuchakAccount('9876507017', 'Rival Suchak Office');
        SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $otherAccount->id,
            'matrimony_profile_id' => $profileId,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'representation_mode' => SuchakProfileRepresentation::MODE_MATCHED_EXISTING_PROFILE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        $this->actingAsVerifiedSuchak('9876507019');

        $response = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Taken Candidate',
            'candidate_mobile' => '9876507018',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
            'use_existing_profile' => true,
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'represented_by_other_suchak')
            ->assertJsonPath('data.owner_type', 'other_suchak')
            ->assertJsonPath('data.can_link_existing', false);

        // The other Suchak is publicly routable, so naming them is allowed.
        $this->assertSame('Rival Suchak Office', $response->json('data.owner_suchak_name'));
        $this->assertStringContainsString('Rival Suchak Office', (string) $response->json('message'));

        // No competing claim and no rival consent request were created.
        $this->assertSame(
            0,
            SuchakProfileRepresentation::query()
                ->where('matrimony_profile_id', $profileId)
                ->where('suchak_account_id', '!=', $otherAccount->id)
                ->count(),
        );
        $this->assertDatabaseCount('suchak_consents', 0);
    }

    public function test_duplicate_check_refuses_to_offer_consent_for_another_suchaks_customer(): void
    {
        $profileId = $this->existingMember('9876507020', 'Duplicate Owned');
        $otherAccount = $this->verifiedSuchakAccount('9876507021', 'Other Office');
        SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $otherAccount->id,
            'matrimony_profile_id' => $profileId,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'representation_mode' => SuchakProfileRepresentation::MODE_MATCHED_EXISTING_PROFILE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        $this->actingAsVerifiedSuchak('9876507022');

        $this->postJson('/api/v1/suchak/manual-profiles/duplicate-check', [
            'candidate_name' => 'Duplicate Owned',
            'candidate_mobile' => '9876507020',
            'candidate_gender' => 'female',
        ])->assertOk()
            ->assertJsonPath('data.matches.0.owner_type', 'other_suchak')
            ->assertJsonPath('data.matches.0.can_link_existing', false);
    }

    public function test_relinking_reuses_the_open_consent_instead_of_creating_a_second_one(): void
    {
        $this->actingAsVerifiedSuchak('9876507003');
        $this->existingMember('9876507004', 'Twice Linked');

        $payload = [
            'candidate_name' => 'Twice Linked',
            'candidate_mobile' => '9876507004',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
            'use_existing_profile' => true,
        ];

        $first = $this->postJson('/api/v1/suchak/manual-profiles', $payload)->assertOk();
        $second = $this->postJson('/api/v1/suchak/manual-profiles', $payload)->assertOk();

        $this->assertSame($first->json('data.consent_id'), $second->json('data.consent_id'));
        $this->assertTrue($second->json('data.consent_reused'));
        // Hashed token cannot be replayed — the app must call the resend endpoint.
        $this->assertNull($second->json('data.consent_url'));
        $this->assertFalse($second->json('data.consent_link_available'));
        $this->assertSame(
            1,
            DB::table('suchak_consents')
                ->where('representation_id', $first->json('data.representation_id'))
                ->count(),
        );
    }

    public function test_writes_are_blocked_before_consent_and_allowed_after(): void
    {
        $this->actingAsVerifiedSuchak('9876507005');
        $this->existingMember('9876507006', 'Gated Candidate');

        $link = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Gated Candidate',
            'candidate_mobile' => '9876507006',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
            'use_existing_profile' => true,
        ])->assertOk();

        $representationId = (int) $link->json('data.representation_id');

        // Every write surface is closed.
        $this->postJson("/api/v1/suchak/nxt/{$representationId}/preferences/auto-draft")
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'consent_required')
            ->assertJsonPath('data.consent_required', true)
            ->assertJsonPath('data.representation_id', $representationId);

        $this->postJson("/api/v1/suchak/nxt/{$representationId}/profile/save-step", [
            'step' => 'astro',
            'data' => ['manglik' => 'no'],
        ])->assertStatus(403)->assertJsonPath('error_code', 'consent_required');

        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", [
            'full_name' => 'Hijacked Name',
        ])->assertStatus(403)->assertJsonPath('error_code', 'consent_required');

        // The candidate accepts through the real consent engine.
        $this->acceptConsentFor($representationId);

        $this->assertTrue(
            SuchakProfileRepresentation::query()->findOrFail($representationId)->hasValidConsent(),
        );

        $this->postJson("/api/v1/suchak/nxt/{$representationId}/preferences/auto-draft")->assertOk();
    }

    public function test_suchak_own_manual_profile_is_never_gated(): void
    {
        $this->actingAsVerifiedSuchak('9876507007');

        $create = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Own Manual Candidate',
            'candidate_mobile' => '9876507008',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated();

        $representationId = (int) $create->json('data.representation_id');
        $this->assertSame(
            SuchakProfileRepresentation::MODE_MANUAL_FORM_BY_SUCHAK,
            SuchakProfileRepresentation::query()->findOrFail($representationId)->representation_mode,
        );

        // No consent yet, and none needed — nobody else's data is involved.
        $this->postJson("/api/v1/suchak/nxt/{$representationId}/preferences/auto-draft")->assertOk();
        $this->getJson('/api/v1/suchak/customers')
            ->assertOk()
            ->assertJsonPath('data.customers.0.representation_id', $representationId);
    }

    public function test_revoked_consent_closes_the_gate_and_delists_the_customer_again(): void
    {
        $this->actingAsVerifiedSuchak('9876507009');
        $this->existingMember('9876507010', 'Revoked Candidate');

        $link = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Revoked Candidate',
            'candidate_mobile' => '9876507010',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
            'use_existing_profile' => true,
        ])->assertOk();
        $representationId = (int) $link->json('data.representation_id');

        $this->acceptConsentFor($representationId);
        $this->postJson("/api/v1/suchak/nxt/{$representationId}/preferences/auto-draft")->assertOk();

        SuchakProfileRepresentation::query()->whereKey($representationId)->update(['revoked_at' => now()]);

        $this->postJson("/api/v1/suchak/nxt/{$representationId}/preferences/auto-draft")
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'consent_required');
        $this->getJson('/api/v1/suchak/customers')
            ->assertOk()
            ->assertJsonPath('data.customers', []);
    }

    private function acceptConsentFor(int $representationId): void
    {
        /** @var SuchakConsent $consent */
        $consent = SuchakConsent::query()
            ->where('representation_id', $representationId)
            ->latest('id')
            ->firstOrFail();

        app(SuchakConsentService::class)->recordPublicConsentDecision($consent, SuchakConsent::STATUS_ACCEPTED);
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
