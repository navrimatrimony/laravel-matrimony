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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SuchakConsentOperationalUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_can_request_platform_otp_and_verify_customer_reply_from_dashboard_routes(): void
    {
        [$suchakUser, $account, $representation] = $this->pendingRepresentationFixture();
        $manageUrl = route('suchak.dashboard', [
            'dashboard_tab' => 'profiles',
            'manage_representation' => $representation->id,
        ]);

        $this->actingAs($suchakUser)
            ->get($manageUrl)
            ->assertOk()
            ->assertSee('Get consent', false)
            ->assertSee('Send OTP to customer', false)
            ->assertSee('Upload signed proof', false)
            ->assertSee('Platform-assisted consent', false);

        $response = $this->actingAs($suchakUser)
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_channel' => SuchakConsent::CHANNEL_SMS_OTP,
                'consent_given_by_name' => 'Candidate Parent',
                'relationship_to_candidate' => 'father',
                'consent_mobile_number' => '9876543210',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Consent request recorded. Platform OTP sent to customer.')
            ->assertSessionHas('suchak_consent_otp_display');

        $otp = (string) $response->getSession()->get('suchak_consent_otp_display');

        $consent = SuchakConsent::query()->where('representation_id', $representation->id)->firstOrFail();
        $originalTokenHash = $consent->token_hash;
        $originalOtpHash = $consent->otp_hash;

        $this->assertSame(SuchakConsent::STATUS_OTP_SENT, $consent->consent_status);
        $this->assertSame(SuchakProfileRepresentation::STATUS_CONSENT_PENDING, $representation->fresh()->representation_status);
        $this->assertNotNull($consent->otp_hash);
        $this->assertNotSame($otp, $consent->otp_hash);

        $this->actingAs($suchakUser)
            ->get($manageUrl)
            ->assertOk()
            ->assertSee('Open consent #'.$consent->id, false)
            ->assertSee('Enter customer OTP', false)
            ->assertSee('Send new platform OTP', false)
            ->assertDontSee('Record OTP sent', false);

        $this->actingAs($suchakUser)
            ->post(route('suchak.consents.resend', $consent))
            ->assertRedirect()
            ->assertSessionHas('success', 'Consent request resent.');

        $this->assertNotSame($originalTokenHash, $consent->fresh()->token_hash);

        $otpResponse = $this->actingAs($suchakUser)
            ->post(route('suchak.consents.send-otp', $consent))
            ->assertRedirect()
            ->assertSessionHas('success', 'Platform OTP sent to customer.')
            ->assertSessionHas('suchak_consent_otp_display');

        $withOtp = $consent->fresh();
        $this->assertSame(SuchakConsent::STATUS_OTP_SENT, $withOtp->consent_status);
        $this->assertNotSame($originalOtpHash, $withOtp->otp_hash);

        $otp = (string) $otpResponse->getSession()->get('suchak_consent_otp_display');

        $this->actingAs($suchakUser)
            ->post(route('suchak.consents.verify-otp', $consent), [
                'otp' => $otp,
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
            ->get($manageUrl)
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
        Storage::fake('local');
        $manageUrl = route('suchak.dashboard', [
            'dashboard_tab' => 'profiles',
            'manage_representation' => $representation->id,
        ]);

        $this->actingAs($suchakUser)
            ->post(route('suchak.representations.consents.request', $representation), [
                'consent_type' => SuchakConsent::TYPE_ONE_YEAR,
                'consent_channel' => SuchakConsent::CHANNEL_OFFLINE_PROOF,
                'consent_given_by_name' => 'Candidate Brother',
                'relationship_to_candidate' => 'brother',
                'consent_mobile_number' => '9876543211',
                'evidence_note' => 'Candidate brother signed physical consent form.',
                'proof_document' => UploadedFile::fake()->create('signed-consent.pdf', 128, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Signed proof uploaded and consent accepted.');

        $consent = SuchakConsent::query()->where('representation_id', $representation->id)->firstOrFail();

        $this->actingAs($suchakUser)
            ->get($manageUrl)
            ->assertOk()
            ->assertSee('Renew consent', false)
            ->assertDontSee('Record OTP sent', false);

        $accepted = $consent->fresh();

        $this->assertSame(SuchakConsent::STATUS_ACCEPTED, $accepted->consent_status);
        $this->assertNull($accepted->otp_hash);
        $this->assertSame(SuchakProfileRepresentation::STATUS_ACTIVE, $representation->fresh()->representation_status);

        $event = SuchakConsentEvent::query()
            ->where('consent_id', $consent->id)
            ->where('event_type', SuchakConsentEvent::EVENT_CONSENT_ACCEPTED)
            ->firstOrFail();

        $this->assertStringContainsString('Candidate brother signed physical consent form.', (string) $event->event_note);
        $this->assertStringContainsString('Proof file: suchak/consent-proofs/', (string) $event->event_note);
        $proofPath = trim((string) str($event->event_note)->after('Proof file: ')->before("\n"));
        Storage::disk('local')->assertExists($proofPath);

        $this->assertDatabaseHas('suchak_consent_events', [
            'consent_id' => $consent->id,
            'event_type' => SuchakConsentEvent::EVENT_CONSENT_ACCEPTED,
            'actor_type' => SuchakConsentEvent::ACTOR_SUCHAK,
            'actor_id' => $suchakUser->id,
        ]);
    }

    public function test_suchak_can_renew_active_consent_and_revoke_existing_consent_from_ui_routes(): void
    {
        [$suchakUser, , $representation, $acceptedConsent] = $this->activeRepresentationFixture();
        $manageUrl = route('suchak.dashboard', [
            'dashboard_tab' => 'profiles',
            'manage_representation' => $representation->id,
        ]);

        $this->actingAs($suchakUser)
            ->get($manageUrl)
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
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $service = app(SuchakConsentService::class);
        $consent = $service->issuePlatformOtp(
            $service->requestConsent($representation, $suchakUser, [
                'consent_channel' => SuchakConsent::CHANNEL_SMS_OTP,
                'consent_mobile_number' => '9876543210',
            ])['consent'],
            $suchakUser,
        )['consent']->fresh();

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
