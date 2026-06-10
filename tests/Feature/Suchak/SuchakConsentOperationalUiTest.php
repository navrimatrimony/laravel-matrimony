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
use Tests\TestCase;

class SuchakConsentOperationalUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_can_request_resend_record_and_verify_otp_consent_from_dashboard_routes(): void
    {
        [$suchakUser, $account, $representation] = $this->pendingRepresentationFixture();

        $this->actingAs($suchakUser)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertSee('Request consent', false);

        $this->actingAs($suchakUser)
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_channel' => SuchakConsent::CHANNEL_SMS_OTP,
                'consent_given_by_name' => 'Candidate Parent',
                'relationship_to_candidate' => 'father',
                'consent_mobile_number' => '9876543210',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Consent request recorded.');

        $consent = SuchakConsent::query()->where('representation_id', $representation->id)->firstOrFail();
        $originalTokenHash = $consent->token_hash;

        $this->assertSame(SuchakConsent::STATUS_REQUESTED, $consent->consent_status);
        $this->assertSame(SuchakProfileRepresentation::STATUS_CONSENT_PENDING, $representation->fresh()->representation_status);
        $this->assertNull($consent->otp_hash);

        $this->actingAs($suchakUser)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertSee('Open consent #'.$consent->id, false)
            ->assertSee('Record OTP sent', false)
            ->assertSee('Verify consent', false);

        $this->actingAs($suchakUser)
            ->post(route('suchak.consents.resend', $consent))
            ->assertRedirect()
            ->assertSessionHas('success', 'Consent request resent.');

        $this->assertNotSame($originalTokenHash, $consent->fresh()->token_hash);

        $this->actingAs($suchakUser)
            ->post(route('suchak.consents.send-otp', $consent), [
                'otp' => '123456',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Consent OTP hash recorded.');

        $withOtp = $consent->fresh();
        $this->assertSame(SuchakConsent::STATUS_OTP_SENT, $withOtp->consent_status);
        $this->assertNotSame('123456', $withOtp->otp_hash);

        $this->actingAs($suchakUser)
            ->post(route('suchak.consents.verify-otp', $consent), [
                'otp' => '123456',
                'consent_given_by_name' => 'Candidate Parent',
                'relationship_to_candidate' => 'father',
                'consent_mobile_number' => '9876543210',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Consent OTP verified.');

        $accepted = $consent->fresh();
        $this->assertSame(SuchakConsent::STATUS_ACCEPTED, $accepted->consent_status);
        $this->assertSame(SuchakProfileRepresentation::STATUS_ACTIVE, $representation->fresh()->representation_status);
        $this->assertSame(SuchakProfileRepresentation::CONSENT_ACCEPTED, $representation->fresh()->consent_status);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'action_type' => SuchakActivityLog::ACTION_CONSENT_OTP_SENT,
            'target_type' => 'suchak_consent',
            'target_id' => $consent->id,
        ]);

        $this->actingAs($suchakUser)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertSee('Consent timeline', false)
            ->assertSee('Renew consent', false)
            ->assertSee('Revoke consent', false)
            ->assertDontSee($accepted->token_hash, false)
            ->assertDontSee($accepted->otp_hash, false);
    }

    public function test_suchak_can_accept_manual_offline_consent_proof_without_otp(): void
    {
        [$suchakUser, , $representation] = $this->pendingRepresentationFixture();

        $this->actingAs($suchakUser)
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_channel' => SuchakConsent::CHANNEL_OFFLINE_PROOF,
                'consent_given_by_name' => 'Candidate Brother',
                'relationship_to_candidate' => 'brother',
                'consent_mobile_number' => '9876543211',
            ])
            ->assertRedirect();

        $consent = SuchakConsent::query()->where('representation_id', $representation->id)->firstOrFail();

        $this->actingAs($suchakUser)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertSee('Accept manual proof', false)
            ->assertDontSee('Record OTP sent', false);

        $this->actingAs($suchakUser)
            ->post(route('suchak.consents.manual-accept', $consent), [
                'consent_given_by_name' => 'Candidate Brother',
                'relationship_to_candidate' => 'brother',
                'consent_mobile_number' => '9876543211',
                'evidence_note' => 'Candidate brother signed physical consent form.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Manual consent proof accepted.');

        $accepted = $consent->fresh();

        $this->assertSame(SuchakConsent::STATUS_ACCEPTED, $accepted->consent_status);
        $this->assertNull($accepted->otp_hash);
        $this->assertSame(SuchakProfileRepresentation::STATUS_ACTIVE, $representation->fresh()->representation_status);

        $this->assertDatabaseHas('suchak_consent_events', [
            'consent_id' => $consent->id,
            'event_type' => SuchakConsentEvent::EVENT_CONSENT_ACCEPTED,
            'actor_type' => SuchakConsentEvent::ACTOR_SUCHAK,
            'actor_id' => $suchakUser->id,
            'event_note' => 'Candidate brother signed physical consent form.',
        ]);
    }

    public function test_suchak_can_renew_active_consent_and_revoke_existing_consent_from_ui_routes(): void
    {
        [$suchakUser, , $representation, $acceptedConsent] = $this->activeRepresentationFixture();

        $this->actingAs($suchakUser)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertSee('Renew consent', false)
            ->assertSee('Revoke consent', false);

        $this->actingAs($suchakUser)
            ->post(route('suchak.representations.consents.renew', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_channel' => SuchakConsent::CHANNEL_SMS_OTP,
                'consent_given_by_name' => 'Renewal Parent',
                'relationship_to_candidate' => 'mother',
                'consent_mobile_number' => '9876543212',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Consent renewal request recorded.');

        $this->assertSame(SuchakProfileRepresentation::STATUS_ACTIVE, $representation->fresh()->representation_status);
        $this->assertSame(SuchakProfileRepresentation::CONSENT_ACCEPTED, $representation->fresh()->consent_status);
        $this->assertSame(1, SuchakConsent::query()
            ->where('representation_id', $representation->id)
            ->where('consent_status', SuchakConsent::STATUS_REQUESTED)
            ->count());

        $this->actingAs($suchakUser)
            ->post(route('suchak.consents.revoke', $acceptedConsent), [
                'reason' => 'Candidate asked to stop Suchak representation.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Consent revoked.');

        $this->assertSame(SuchakConsent::STATUS_REVOKED, $acceptedConsent->fresh()->consent_status);
        $this->assertSame(SuchakProfileRepresentation::STATUS_REVOKED, $representation->fresh()->representation_status);
        $this->assertSame(SuchakProfileRepresentation::CONSENT_REVOKED, $representation->fresh()->consent_status);
    }

    public function test_admin_can_inspect_consent_evidence_without_rendering_hash_values(): void
    {
        [$suchakUser, $account, $representation] = $this->pendingRepresentationFixture();
        $admin = User::factory()->create(['is_admin' => true]);
        $service = app(SuchakConsentService::class);
        $consent = $service->recordOtpSent(
            $service->requestConsent($representation, $suchakUser, [
                'consent_channel' => SuchakConsent::CHANNEL_SMS_OTP,
            ])['consent'],
            '123456',
            $suchakUser,
        )->fresh();

        $this->actingAs($admin)
            ->get(route('admin.suchak.accounts.show', $account))
            ->assertOk()
            ->assertSee('Consent Evidence', false)
            ->assertSee('Consent #'.$consent->id, false)
            ->assertSee('Sms otp', false)
            ->assertSee('Hashed OTP stored', false)
            ->assertSee('Otp sent', false)
            ->assertDontSee($consent->token_hash, false)
            ->assertDontSee($consent->otp_hash, false);
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakProfileRepresentation}
     */
    private function pendingRepresentationFixture(): array
    {
        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'highest_education' => 'B.Com',
        ]);
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
            'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
        ]);

        return [$suchakUser, $account, $representation];
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakProfileRepresentation, 3: SuchakConsent}
     */
    private function activeRepresentationFixture(): array
    {
        [$suchakUser, $account, $representation] = $this->pendingRepresentationFixture();

        $representation->update([
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now()->subMonth(),
            'consent_verified_at' => now()->subMonth(),
            'consent_valid_until' => now()->addYear(),
        ]);

        $acceptedConsent = SuchakConsent::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $representation->matrimony_profile_id,
            'representation_id' => $representation->id,
            'consent_status' => SuchakConsent::STATUS_ACCEPTED,
            'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
            'consent_channel' => SuchakConsent::CHANNEL_SMS_OTP,
            'consent_given_by_name' => 'Candidate Parent',
            'relationship_to_candidate' => 'father',
            'consent_mobile_number' => '9876543210',
            'accepted_at' => now()->subMonth(),
            'used_at' => now()->subMonth(),
            'otp_verified_at' => now()->subMonth(),
            'valid_from' => now()->subMonth(),
            'valid_until' => $representation->fresh()->consent_valid_until,
        ]);

        SuchakConsentEvent::factory()->create([
            'consent_id' => $acceptedConsent->id,
            'event_type' => SuchakConsentEvent::EVENT_CONSENT_ACCEPTED,
            'actor_type' => SuchakConsentEvent::ACTOR_CANDIDATE,
            'actor_id' => null,
        ]);

        return [$suchakUser, $account, $representation->fresh(), $acceptedConsent];
    }
}
