<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakConsent;
use App\Models\SuchakConsentEvent;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakConsentFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_consent_tables_include_secure_link_evidence_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_consents'));
        $this->assertTrue(Schema::hasTable('suchak_consent_events'));

        foreach ([
            'suchak_account_id',
            'matrimony_profile_id',
            'representation_id',
            'consent_status',
            'consent_type',
            'consent_text_snapshot',
            'consent_template_version',
            'consent_text_version',
            'consent_given_by_name',
            'relationship_to_candidate',
            'consent_giver_relation',
            'consent_mobile_number',
            'intended_mobile',
            'submitted_mobile',
            'mobile_match',
            'token_hash',
            'token_expires_at',
            'expires_at',
            'accepted_at',
            'rejected_at',
            'revoked_at',
            'used_at',
            'public_token_used_at',
            'decided_at',
            'consent_channel',
            'consent_method',
            'valid_from',
            'valid_until',
            'revocation_reason',
            'ip_address',
            'user_agent',
            'proof_file_path',
            'proof_original_name',
            'proof_uploaded_at',
            'delivery_status',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_consents', $column), $column);
        }

        foreach ([
            'consent_id',
            'event_type',
            'event_note',
            'actor_type',
            'actor_id',
            'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_consent_events', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_consents', 'raw_token'));
        $this->assertFalse(Schema::hasColumn('suchak_consents', 'otp'));
        $this->assertFalse(Schema::hasColumn('suchak_consents', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_consent_events', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('suchak_consent_events', 'deleted_at'));
    }

    public function test_suchak_relayed_consent_creates_secure_link_request_with_intended_mobile(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();

        $result = app(SuchakConsentService::class)->createSuchakRelayedLinkConsent(
            $representation,
            $user,
            [
                'consent_given_by_name' => 'Candidate Parent',
                'consent_giver_relation' => 'father',
                'intended_mobile' => '+91 98765 43210',
            ],
            '127.0.0.1',
            'Secure link feature test',
        );

        $consent = $result['consent'];
        $rawToken = $result['raw_token'];

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $rawToken);
        $this->assertStringContainsString('/suchak/consent/'.$rawToken, $result['consent_url']);
        $this->assertStringContainsString($result['consent_url'], $result['message']);
        $this->assertSame(hash('sha256', $rawToken), $consent->token_hash);
        $this->assertDatabaseMissing('suchak_consents', ['token_hash' => $rawToken]);

        $this->assertDatabaseHas('suchak_consents', [
            'id' => $consent->id,
            'suchak_account_id' => $representation->suchak_account_id,
            'matrimony_profile_id' => $representation->matrimony_profile_id,
            'representation_id' => $representation->id,
            'consent_status' => SuchakConsent::STATUS_REQUESTED,
            'consent_method' => SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
            'consent_channel' => SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
            'intended_mobile' => '9876543210',
            'consent_mobile_number' => '9876543210',
            'consent_given_by_name' => 'Candidate Parent',
            'consent_giver_relation' => 'father',
            'mobile_match' => false,
            'consent_text_version' => SuchakConsent::CONSENT_TEXT_VERSION_V1,
        ]);

        $this->assertDatabaseHas('suchak_consent_events', [
            'consent_id' => $consent->id,
            'event_type' => SuchakConsentEvent::EVENT_REQUESTED,
            'actor_type' => SuchakConsentEvent::ACTOR_SUCHAK,
            'actor_id' => $user->id,
        ]);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $representation->suchak_account_id,
            'actor_user_id' => $user->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_CONSENT_REQUESTED,
            'target_type' => 'suchak_consent',
            'target_id' => $consent->id,
        ]);

        $this->assertSame(SuchakProfileRepresentation::STATUS_CONSENT_PENDING, $representation->fresh()->representation_status);
        $this->assertSame(SuchakProfileRepresentation::CONSENT_REQUESTED, $representation->fresh()->consent_status);
    }

    public function test_public_yes_marks_consent_accepted_with_mobile_match_and_device_evidence(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();
        $service = app(SuchakConsentService::class);
        $result = $service->createSuchakRelayedLinkConsent($representation, $user, [
            'consent_given_by_name' => 'Candidate Parent',
            'consent_giver_relation' => 'father',
            'intended_mobile' => '9876543210',
        ]);

        $consent = $service->resolvePublicConsentToken($result['raw_token']);
        $accepted = $service->recordPublicConsentDecision($consent, SuchakConsent::STATUS_ACCEPTED, '203.0.113.10', 'Consent Browser');

        $this->assertSame(SuchakConsent::STATUS_ACCEPTED, $accepted->consent_status);
        $this->assertSame('9876543210', $accepted->submitted_mobile);
        $this->assertTrue($accepted->mobile_match);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertNotNull($accepted->public_token_used_at);
        $this->assertSame('203.0.113.10', $accepted->ip_address);
        $this->assertSame('Consent Browser', $accepted->user_agent);
        $this->assertTrue($accepted->isAcceptedAndValid());

        $freshRepresentation = $representation->fresh();
        $this->assertSame(SuchakProfileRepresentation::STATUS_ACTIVE, $freshRepresentation->representation_status);
        $this->assertSame(SuchakProfileRepresentation::CONSENT_ACCEPTED, $freshRepresentation->consent_status);
        $this->assertNotNull($freshRepresentation->consent_verified_at);

        $this->assertDatabaseHas('suchak_consent_events', [
            'consent_id' => $accepted->id,
            'event_type' => SuchakConsentEvent::EVENT_CONSENT_ACCEPTED,
            'actor_type' => SuchakConsentEvent::ACTOR_CANDIDATE,
        ]);
    }

    public function test_public_no_marks_consent_rejected(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();
        $service = app(SuchakConsentService::class);
        $result = $service->createSuchakRelayedLinkConsent($representation, $user, [
            'consent_given_by_name' => 'Candidate Mother',
            'consent_giver_relation' => 'mother',
            'intended_mobile' => '9876543211',
        ]);

        $consent = $service->resolvePublicConsentToken($result['raw_token']);
        $rejected = $service->recordPublicConsentDecision($consent, SuchakConsent::STATUS_REJECTED, '203.0.113.11', 'Reject Browser');

        $this->assertSame(SuchakConsent::STATUS_REJECTED, $rejected->consent_status);
        $this->assertSame('9876543211', $rejected->submitted_mobile);
        $this->assertTrue($rejected->mobile_match);
        $this->assertNotNull($rejected->rejected_at);
        $this->assertSame(SuchakProfileRepresentation::STATUS_REJECTED, $representation->fresh()->representation_status);
        $this->assertSame(SuchakProfileRepresentation::CONSENT_REJECTED, $representation->fresh()->consent_status);
    }

    public function test_public_token_cannot_be_reused_after_decision(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();
        $service = app(SuchakConsentService::class);
        $result = $service->createSuchakRelayedLinkConsent($representation, $user, [
            'consent_given_by_name' => 'Candidate Parent',
            'consent_giver_relation' => 'father',
            'intended_mobile' => '9876543210',
        ]);

        $consent = $service->recordPublicConsentDecision(
            $service->resolvePublicConsentToken($result['raw_token']),
            SuchakConsent::STATUS_ACCEPTED,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Consent link has already been used.');

        $service->recordPublicConsentDecision($consent->fresh(), SuchakConsent::STATUS_REJECTED);
    }

    public function test_expired_public_token_cannot_be_accepted(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();
        $service = app(SuchakConsentService::class);
        $result = $service->createSuchakRelayedLinkConsent($representation, $user, [
            'consent_given_by_name' => 'Candidate Parent',
            'consent_giver_relation' => 'father',
            'intended_mobile' => '9876543210',
        ]);

        $consent = $service->resolvePublicConsentToken($result['raw_token']);
        $consent->forceFill([
            'token_expires_at' => now()->subMinute(),
            'expires_at' => now()->subMinute(),
        ])->save();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Consent link has expired.');

        $service->recordPublicConsentDecision($consent->fresh(), SuchakConsent::STATUS_ACCEPTED);
    }

    public function test_offline_proof_requires_file_upload(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Signed consent proof file is required.');

        app(SuchakConsentService::class)->createOfflineProofConsent($representation, $user, [
            'consent_given_by_name' => 'Candidate Brother',
            'consent_giver_relation' => 'brother',
            'intended_mobile' => '9876543212',
            'declaration' => '1',
        ]);
    }

    public function test_offline_proof_file_accepts_consent_without_mobile_match_claim(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();
        Storage::fake('local');

        $accepted = app(SuchakConsentService::class)->createOfflineProofConsent($representation, $user, [
            'consent_given_by_name' => 'Candidate Brother',
            'consent_giver_relation' => 'brother',
            'intended_mobile' => '9876543212',
            'proof_document' => UploadedFile::fake()->create('signed-consent.pdf', 128, 'application/pdf'),
            'evidence_note' => 'Signed physical form received.',
        ]);

        $this->assertSame(SuchakConsent::STATUS_ACCEPTED, $accepted->consent_status);
        $this->assertSame(SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF, $accepted->consent_method);
        $this->assertFalse($accepted->mobile_match);
        $this->assertNotNull($accepted->proof_file_path);
        Storage::disk('local')->assertExists($accepted->proof_file_path);
    }

    public function test_platform_assisted_link_creates_pending_request_without_code_delivery(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();

        $result = app(SuchakConsentService::class)->createPlatformAssistedLinkConsent($representation, $user, [
            'consent_given_by_name' => 'Candidate Guardian',
            'consent_giver_relation' => 'guardian',
            'intended_mobile' => '9876543213',
        ]);

        $consent = $result['consent'];

        $this->assertSame(SuchakConsent::STATUS_REQUESTED, $consent->consent_status);
        $this->assertSame(SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK, $consent->consent_method);
        $this->assertSame('manual_delivery_pending', $consent->delivery_status);
        $this->assertNull($consent->otp_hash);
        $this->assertSame(0, $consent->otp_attempts);
    }

    public function test_duplicate_open_consent_for_same_representation_is_blocked(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();

        app(SuchakConsentService::class)->createSuchakRelayedLinkConsent($representation, $user, [
            'consent_given_by_name' => 'Candidate Parent',
            'consent_giver_relation' => 'father',
            'intended_mobile' => '9876543210',
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(SuchakConsentService::class)->createPlatformAssistedLinkConsent($representation->fresh(), $user, [
            'consent_given_by_name' => 'Candidate Parent',
            'consent_giver_relation' => 'father',
            'intended_mobile' => '9876543210',
        ]);
    }

    public function test_non_owner_suchak_actor_cannot_create_consent(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();
        $otherUser = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(SuchakConsentService::class)->createSuchakRelayedLinkConsent($representation, $otherUser, [
            'consent_given_by_name' => 'Candidate Parent',
            'consent_giver_relation' => 'father',
            'intended_mobile' => '9876543210',
        ]);
    }

    public function test_revoke_consent_revokes_representation_and_writes_trace(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();
        $service = app(SuchakConsentService::class);
        $result = $service->createSuchakRelayedLinkConsent($representation, $user, [
            'consent_given_by_name' => 'Candidate Parent',
            'consent_giver_relation' => 'father',
            'intended_mobile' => '9876543210',
        ]);
        $accepted = $service->recordPublicConsentDecision(
            $service->resolvePublicConsentToken($result['raw_token']),
            SuchakConsent::STATUS_ACCEPTED,
        );

        $revoked = $service->revoke($accepted, $user, 'Candidate withdrew consent.');

        $this->assertSame(SuchakConsent::STATUS_REVOKED, $revoked->consent_status);
        $this->assertNotNull($revoked->revoked_at);

        $freshRepresentation = $representation->fresh();
        $this->assertSame(SuchakProfileRepresentation::STATUS_REVOKED, $freshRepresentation->representation_status);
        $this->assertSame(SuchakProfileRepresentation::CONSENT_REVOKED, $freshRepresentation->consent_status);
        $this->assertNotNull($freshRepresentation->revoked_at);

        $this->assertDatabaseHas('suchak_consent_events', [
            'consent_id' => $revoked->id,
            'event_type' => SuchakConsentEvent::EVENT_CONSENT_REVOKED,
            'actor_type' => SuchakConsentEvent::ACTOR_SUCHAK,
            'actor_id' => $user->id,
        ]);
    }

    public function test_revoked_consent_can_be_requested_again_without_deleting_evidence(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();
        $service = app(SuchakConsentService::class);
        $first = $service->createSuchakRelayedLinkConsent($representation, $user, [
            'consent_given_by_name' => 'Candidate Parent',
            'consent_giver_relation' => 'father',
            'intended_mobile' => '9876543210',
        ]);
        $accepted = $service->recordPublicConsentDecision(
            $service->resolvePublicConsentToken($first['raw_token']),
            SuchakConsent::STATUS_ACCEPTED,
        );
        $revoked = $service->revoke($accepted, $user, 'Candidate withdrew consent.');

        $second = $service->createSuchakRelayedLinkConsent($representation->fresh(), $user, [
            'consent_given_by_name' => 'Candidate Parent',
            'consent_giver_relation' => 'father',
            'intended_mobile' => '9876543210',
        ]);

        $this->assertSame(SuchakConsent::STATUS_REVOKED, $revoked->fresh()->consent_status);
        $this->assertNotSame($revoked->id, $second['consent']->id);
        $this->assertSame(SuchakConsent::STATUS_REQUESTED, $second['consent']->consent_status);
        $this->assertSame(2, SuchakConsent::query()->where('representation_id', $representation->id)->count());

        $freshRepresentation = $representation->fresh();
        $this->assertSame(SuchakProfileRepresentation::STATUS_CONSENT_PENDING, $freshRepresentation->representation_status);
        $this->assertSame(SuchakProfileRepresentation::CONSENT_REQUESTED, $freshRepresentation->consent_status);
        $this->assertNull($freshRepresentation->revoked_at);
        $this->assertNull($freshRepresentation->consent_valid_until);
        $this->assertNull($freshRepresentation->consent_verified_at);
    }

    public function test_consent_events_are_immutable(): void
    {
        $event = SuchakConsentEvent::factory()->create();

        $this->expectException(RuntimeException::class);

        $event->update(['event_type' => SuchakConsentEvent::EVENT_CONSENT_REVOKED]);
    }

    /**
     * @return array{0: User, 1: SuchakProfileRepresentation}
     */
    private function pendingRepresentationFixture(): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => now(),
        ]);

        $profile = MatrimonyProfile::factory()->create([
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'highest_education' => 'B.Com',
        ]);
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
            'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
        ]);

        return [$user, $representation];
    }
}
