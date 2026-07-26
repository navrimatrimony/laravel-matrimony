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
 * SECURITY (2026-07-26): the represented-profile write endpoints authorised on
 * OWNERSHIP alone (suchak_account_id), never on consent. A Suchak could link a
 * self-registered member through the duplicate → "use existing profile" branch
 * and immediately start editing that person's profile, before they had agreed
 * to anything.
 *
 * The gate: representations in a CONSENT_GATED_EDIT_MODE (matched_existing_
 * profile — a person who already existed) are READ-ONLY for the Suchak until
 * hasValidConsent() is true. Suchak-created manual profiles are unaffected.
 *
 * This test also pins the consent hand-off the link response must carry, since
 * the Suchak app deep-links into the existing consent sheet from it.
 */
class SuchakRepresentedProfileConsentGateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_existing_profile_response_carries_the_consent_handoff(): void
    {
        $this->actingAsVerifiedSuchak('9876507001');
        $this->existingMember('9876507002', 'Linked Candidate');

        $response = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Linked Candidate',
            'candidate_mobile' => '9876507002',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
            'use_existing_profile' => true,
        ])->assertOk()->assertJsonPath('data.outcome', 'linked_existing');

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

        // Reads stay open — the Suchak still needs to see who they linked.
        $this->getJson("/api/v1/suchak/nxt/{$representationId}/profile")->assertOk();

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
        $representation = SuchakProfileRepresentation::query()->findOrFail($representationId);
        /** @var SuchakConsent $consent */
        $consent = SuchakConsent::query()
            ->where('representation_id', $representationId)
            ->latest('id')
            ->firstOrFail();
        app(SuchakConsentService::class)->recordPublicConsentDecision($consent, SuchakConsent::STATUS_ACCEPTED);

        $this->assertTrue($representation->fresh()->hasValidConsent());

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
    }

    public function test_revoked_consent_closes_the_gate_again(): void
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

        /** @var SuchakConsent $consent */
        $consent = SuchakConsent::query()->where('representation_id', $representationId)->latest('id')->firstOrFail();
        app(SuchakConsentService::class)->recordPublicConsentDecision($consent, SuchakConsent::STATUS_ACCEPTED);
        $this->postJson("/api/v1/suchak/nxt/{$representationId}/preferences/auto-draft")->assertOk();

        SuchakProfileRepresentation::query()->whereKey($representationId)->update(['revoked_at' => now()]);

        $this->postJson("/api/v1/suchak/nxt/{$representationId}/preferences/auto-draft")
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'consent_required');
    }

    private function actingAsVerifiedSuchak(string $mobile): SuchakAccount
    {
        foreach (['male' => 'Male', 'female' => 'Female'] as $key => $label) {
            MasterGender::query()->firstOrCreate(['key' => $key], ['label' => $label, 'is_active' => true]);
        }

        $user = User::factory()->create(['mobile' => $mobile, 'mobile_verified_at' => now()]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        Sanctum::actingAs($user);

        return $account;
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
