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
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakConsentFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_consent_tables_exist_with_day_7_columns(): void
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
            'consent_given_by_name',
            'relationship_to_candidate',
            'consent_mobile_number',
            'token_hash',
            'token_expires_at',
            'otp_hash',
            'otp_attempts',
            'last_otp_sent_at',
            'accepted_at',
            'rejected_at',
            'revoked_at',
            'used_at',
            'otp_verified_at',
            'consent_channel',
            'valid_from',
            'valid_until',
            'revocation_reason',
            'ip_address',
            'user_agent',
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

    public function test_consent_request_stores_only_token_hash_and_writes_events_and_activity(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();

        $result = app(SuchakConsentService::class)->requestConsent(
            $representation,
            $user,
            ['consent_mobile_number' => '9876543210'],
            '127.0.0.1',
            'Day-7 feature test',
        );

        $consent = $result['consent'];
        $rawToken = $result['raw_token'];

        $this->assertNotSame('', $rawToken);
        $this->assertNotSame($rawToken, $consent->token_hash);
        $this->assertSame(hash('sha256', $rawToken), $consent->token_hash);
        $this->assertNull($consent->otp_hash);

        $this->assertDatabaseHas('suchak_consents', [
            'id' => $consent->id,
            'suchak_account_id' => $representation->suchak_account_id,
            'matrimony_profile_id' => $representation->matrimony_profile_id,
            'representation_id' => $representation->id,
            'consent_status' => SuchakConsent::STATUS_REQUESTED,
            'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
            'consent_channel' => SuchakConsent::CHANNEL_WHATSAPP_DEEP_LINK,
        ]);

        $this->assertDatabaseMissing('suchak_consents', ['token_hash' => $rawToken]);

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
            'matrimony_profile_id' => $representation->matrimony_profile_id,
        ]);

        $this->assertSame(SuchakProfileRepresentation::STATUS_CONSENT_PENDING, $representation->fresh()->representation_status);
        $this->assertSame(SuchakProfileRepresentation::CONSENT_REQUESTED, $representation->fresh()->consent_status);
    }

    public function test_duplicate_open_consent_for_same_representation_is_blocked(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();

        app(SuchakConsentService::class)->requestConsent($representation, $user);

        $this->expectException(InvalidArgumentException::class);

        app(SuchakConsentService::class)->requestConsent($representation->fresh(), $user);
    }

    public function test_otp_is_hashed_and_valid_otp_accepts_consent_and_activates_representation(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();
        $service = app(SuchakConsentService::class);
        $consent = $service->requestConsent($representation, $user)['consent'];

        $withOtp = $service->recordOtpSent($consent, '123456', $user);

        $this->assertNotSame('123456', $withOtp->otp_hash);
        $this->assertSame(SuchakConsent::STATUS_OTP_SENT, $withOtp->consent_status);

        $accepted = $service->verifyOtpAndAccept($withOtp, '123456', [
            'consent_given_by_name' => 'Candidate Parent',
            'relationship_to_candidate' => 'father',
            'consent_mobile_number' => '9876543210',
        ]);

        $this->assertSame(SuchakConsent::STATUS_ACCEPTED, $accepted->consent_status);
        $this->assertNotNull($accepted->valid_from);
        $this->assertNotNull($accepted->valid_until);
        $this->assertTrue($accepted->isAcceptedAndValid());

        $freshRepresentation = $representation->fresh();
        $this->assertSame(SuchakProfileRepresentation::STATUS_ACTIVE, $freshRepresentation->representation_status);
        $this->assertSame(SuchakProfileRepresentation::CONSENT_ACCEPTED, $freshRepresentation->consent_status);
        $this->assertNotNull($freshRepresentation->consent_verified_at);

        $this->assertDatabaseHas('suchak_consent_events', [
            'consent_id' => $accepted->id,
            'event_type' => SuchakConsentEvent::EVENT_OTP_VERIFIED,
            'actor_type' => SuchakConsentEvent::ACTOR_CANDIDATE,
        ]);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'action_type' => SuchakActivityLog::ACTION_CONSENT_VERIFIED,
            'target_type' => 'suchak_consent',
            'target_id' => $accepted->id,
        ]);
    }

    public function test_invalid_otp_increments_attempts_without_accepting(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();
        $service = app(SuchakConsentService::class);
        $consent = $service->recordOtpSent($service->requestConsent($representation, $user)['consent'], '123456', $user);

        try {
            $service->verifyOtpAndAccept($consent, '000000');

            $this->fail('Invalid OTP should fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Invalid OTP for Suchak consent.', $exception->getMessage());
        }

        $this->assertSame(1, $consent->fresh()->otp_attempts);
        $this->assertSame(SuchakConsent::STATUS_OTP_SENT, $consent->fresh()->consent_status);
        $this->assertSame(SuchakProfileRepresentation::STATUS_CONSENT_PENDING, $representation->fresh()->representation_status);
    }

    public function test_non_owner_suchak_actor_cannot_manage_consent(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();
        $otherUser = User::factory()->create();
        $service = app(SuchakConsentService::class);
        $consent = $service->requestConsent($representation, $user)['consent'];

        $this->expectException(InvalidArgumentException::class);

        $service->recordOtpSent($consent, '123456', $otherUser);
    }

    public function test_revoke_consent_revokes_representation_and_writes_trace(): void
    {
        [$user, $representation] = $this->pendingRepresentationFixture();
        $service = app(SuchakConsentService::class);
        $accepted = $service->verifyOtpAndAccept(
            $service->recordOtpSent($service->requestConsent($representation, $user)['consent'], '123456', $user),
            '123456',
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

        $this->assertDatabaseHas('suchak_activity_logs', [
            'action_type' => SuchakActivityLog::ACTION_CONSENT_REVOKED,
            'target_type' => 'suchak_consent',
            'target_id' => $revoked->id,
        ]);
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

        $profile = MatrimonyProfile::factory()->create();
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
            'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
        ]);

        return [$user, $representation];
    }
}
