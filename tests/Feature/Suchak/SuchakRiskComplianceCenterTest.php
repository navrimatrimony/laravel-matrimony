<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakCustomerPaymentCorrection;
use App\Models\SuchakDirectPaymentEvidence;
use App\Models\SuchakDispute;
use App\Models\SuchakGrowthAttribution;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakQrToken;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakRiskComplianceCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Suchak\Support\CreatesSuchakAdmin;
use Tests\TestCase;

class SuchakRiskComplianceCenterTest extends TestCase
{
    use CreatesSuchakAdmin;
    use RefreshDatabase;

    public function test_day_53_admin_risk_compliance_center_links_evidence_without_public_leak(): void
    {
        $admin = $this->createSuchakSuperAdmin();
        $nonAdmin = User::factory()->create(['is_admin' => false]);
        [$suchakUser, $account, $payment, $correction, $dispute, $qrToken, $attribution, $agreement] = $this->riskFixture($admin);

        $summary = app(SuchakRiskComplianceCenterService::class)->summary();

        $this->assertSame(1, $summary['stats']['high_bypass']);
        $this->assertSame(1, $summary['stats']['high_refund']);
        $this->assertSame(1, $summary['stats']['cash_proof_risk']);
        $this->assertSame(1, $summary['stats']['direct_payment_complaint_queue']);
        $this->assertSame(1, $summary['stats']['qr_pdf_abuse_signals']);
        $this->assertSame(1, $summary['stats']['coupon_referral_suspicious_signals']);

        $panelTitles = collect($summary['panels'])->pluck('title')->all();
        $this->assertContains('High Bypass Dashboard', $panelTitles);
        $this->assertContains('High Refund Dashboard', $panelTitles);
        $this->assertContains('Cash / Proof Risk', $panelTitles);
        $this->assertContains('Direct Payment Complaint Queue', $panelTitles);
        $this->assertContains('QR / PDF Abuse Signals', $panelTitles);
        $this->assertContains('Coupon / Referral Suspicious Signals', $panelTitles);

        $encoded = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Customer Agreement #'.$agreement->id, $encoded);
        $this->assertStringContainsString('Payment Correction #'.$correction->id, $encoded);
        $this->assertStringContainsString('Customer Payment #'.$payment->id, $encoded);
        $this->assertStringContainsString('Dispute #'.$dispute->id, $encoded);
        $this->assertStringContainsString('QR Token #'.$qrToken->id, $encoded);
        $this->assertStringContainsString('Growth Attribution #'.$attribution->id, $encoded);
        $this->assertStringContainsString(route('admin.suchak.accounts.show', $account, true), $encoded);

        $this->actingAs($admin)
            ->get(route('admin.suchak.safety.index'))
            ->assertOk()
            ->assertSee('Risk + Compliance Center', false)
            ->assertSee('High Bypass Dashboard', false)
            ->assertSee('High Refund Dashboard', false)
            ->assertSee('Cash / Proof Risk', false)
            ->assertSee('Direct Payment Complaint Queue', false)
            ->assertSee('QR / PDF Abuse Signals', false)
            ->assertSee('Coupon / Referral Suspicious Signals', false)
            ->assertSee('Customer Agreement #'.$agreement->id, false)
            ->assertSee('Payment Correction #'.$correction->id, false)
            ->assertSee('Customer Payment #'.$payment->id, false)
            ->assertSee('Dispute #'.$dispute->id, false)
            ->assertSee('QR Token #'.$qrToken->id, false)
            ->assertSee('Growth Attribution #'.$attribution->id, false)
            ->assertSee(route('admin.suchak.accounts.show', $account, true), false)
            ->assertSee('Open evidence queue', false);

        $this->actingAs($nonAdmin)
            ->get(route('admin.suchak.safety.index'))
            ->assertForbidden();

        $this->actingAs($suchakUser)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertDontSee('Risk + Compliance Center', false)
            ->assertDontSee('High Bypass Dashboard', false)
            ->assertDontSee('Coupon / Referral Suspicious Signals', false);
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakCustomerPayment, 3: SuchakCustomerPaymentCorrection, 4: SuchakDispute, 5: SuchakQrToken, 6: SuchakGrowthAttribution, 7: SuchakCustomerAgreement}
     */
    private function riskFixture(User $admin): array
    {
        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'suchak_name' => 'Day53 Risk Suchak',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Day53 Risk Candidate',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'payer_name' => 'Customer family',
            'payer_relationship_to_candidate' => 'Parent',
            'service_context' => SuchakCustomerContext::SERVICE_PACKAGE_LEAD,
            'source_owner' => SuchakCustomerContext::SOURCE_OWNER_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $suchakUser->id,
            'classified_by_user_id' => $suchakUser->id,
            'classified_at' => now(),
            'opened_at' => now(),
        ]);
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'package_name' => 'Day-53 Risk Package',
            'package_description' => 'Risk center package fixture.',
            'price_amount' => '12000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $suchakUser->id,
            'published_at' => now(),
        ]);
        $agreement = SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'service_package_id' => $package->id,
            'agreement_revision' => 1,
            'terms_status' => SuchakCustomerAgreement::TERMS_BYPASSED,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => hash('sha256', 'day53-agreement'),
            'package_name' => $package->package_name,
            'package_description' => $package->package_description,
            'price_amount' => $package->price_amount,
            'currency' => 'INR',
            'agreement_title' => 'Day-53 bypass agreement',
            'agreement_body' => 'Agreement body retained for audit.',
            'invoice_note' => 'Terms bypassed after offline signed copy.',
            'created_by_user_id' => $suchakUser->id,
            'bypassed_by_user_id' => $admin->id,
            'bypassed_at' => now(),
            'bypass_reason' => 'Customer signed offline paper copy before payment.',
        ]);
        $paymentContext = SuchakPaymentContext::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'matrimony_profile_id' => $profile->id,
            'source_owner' => SuchakPaymentContext::SOURCE_SUCHAK,
            'payment_collector' => SuchakPaymentContext::COLLECTOR_SUCHAK,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $suchakUser->id,
            'resolution_note' => 'Day-53 risk fixture.',
        ]);
        $paymentRequest = SuchakPaymentRequest::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'service_package_id' => $package->id,
            'customer_agreement_id' => $agreement->id,
            'payment_context_id' => $paymentContext->id,
            'requested_by_user_id' => $suchakUser->id,
            'request_token_hash' => hash('sha256', 'day53-payment-request'),
            'payment_status' => SuchakPaymentRequest::STATUS_PENDING,
            'payment_detail_visibility_policy' => SuchakPaymentRequest::VISIBILITY_TERMS_SATISFIED_ONLY,
            'request_title' => 'Day-53 payment request',
            'request_note' => 'Admin risk fixture.',
            'amount_due' => '12000',
            'currency' => 'INR',
            'collector_disclosure' => 'Suchak collects this customer payment.',
            'sent_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
        $payment = SuchakCustomerPayment::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'service_package_id' => $package->id,
            'customer_agreement_id' => $agreement->id,
            'payment_context_id' => $paymentContext->id,
            'payment_request_id' => $paymentRequest->id,
            'recorded_by_user_id' => $suchakUser->id,
            'collection_channel' => SuchakCustomerPayment::CHANNEL_SUCHAK_DIRECT,
            'payment_mode' => SuchakCustomerPayment::MODE_CASH,
            'payment_status' => SuchakCustomerPayment::STATUS_PARTIALLY_PAID,
            'amount_due' => '12000',
            'amount_received' => '5000',
            'balance_amount' => '7000',
            'currency' => 'INR',
            'payment_received_at' => now(),
            'proof_status' => SuchakCustomerPayment::PROOF_SUBMITTED,
            'proof_note' => 'Cash receipt photo submitted.',
            'collection_note' => 'Cash collection needs proof review.',
        ]);
        $correction = SuchakCustomerPaymentCorrection::query()->create([
            'customer_payment_id' => $payment->id,
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'payment_request_id' => $paymentRequest->id,
            'correction_type' => SuchakCustomerPaymentCorrection::TYPE_REFUND,
            'correction_status' => SuchakCustomerPaymentCorrection::STATUS_REQUESTED,
            'amount' => '1000',
            'currency' => 'INR',
            'reason' => 'Customer requested refund after service scope dispute.',
            'requested_by_user_id' => $admin->id,
            'requested_at' => now(),
        ]);
        $dispute = SuchakDispute::query()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'customer_context_id' => $customerContext->id,
            'payment_context_id' => $paymentContext->id,
            'opened_by_user_id' => $admin->id,
            'dispute_type' => SuchakDispute::TYPE_DIRECT_PAYMENT_REQUEST,
            'status' => SuchakDispute::STATUS_OPEN,
            'priority' => SuchakDispute::PRIORITY_HIGH,
            'risk_source' => SuchakDispute::RISK_SOURCE_ADMIN_CASE,
            'summary' => 'Customer reported direct payment pressure.',
            'evidence_summary' => 'Structured evidence attached.',
            'opened_at' => now(),
        ]);
        SuchakDirectPaymentEvidence::query()->create([
            'suchak_dispute_id' => $dispute->id,
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'payment_context_id' => $paymentContext->id,
            'submitted_by_user_id' => $admin->id,
            'evidence_type' => SuchakDirectPaymentEvidence::TYPE_PAYMENT_REQUEST_MESSAGE,
            'evidence_reference' => 'admin-note-53',
            'evidence_note' => 'Message reference retained for admin evidence review.',
            'submitted_at' => now(),
        ]);
        $representation = SuchakProfileRepresentation::query()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'representation_mode' => SuchakProfileRepresentation::MODE_UPLOADED_BY_SUCHAK,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_uploaded_at' => now(),
            'first_identified_at' => now(),
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);
        $export = SuchakBiodataExport::query()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'export_type' => SuchakBiodataExport::TYPE_BIODATA_PDF,
            'file_path' => null,
            'generated_by_user_id' => $suchakUser->id,
            'downloaded_at' => now(),
            'shared_at' => now(),
            'created_at' => now(),
        ]);
        $qrToken = SuchakQrToken::query()->create([
            'token_hash' => hash('sha256', Str::random(64)),
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'export_id' => $export->id,
            'expires_at' => now()->addDays(30),
            'scan_count' => 12,
            'last_scanned_at' => now(),
        ]);
        $attribution = SuchakGrowthAttribution::query()->create([
            'suchak_account_id' => $account->id,
            'attributed_user_id' => $suchakUser->id,
            'matrimony_profile_id' => $profile->id,
            'customer_context_id' => $customerContext->id,
            'payment_context_id' => $paymentContext->id,
            'attribution_source' => SuchakGrowthAttribution::SOURCE_COUPON_CODE,
            'attribution_policy' => SuchakGrowthAttribution::POLICY_COUPON_PRIORITY,
            'attribution_key' => 'DAY53-COUPON',
            'coupon_code' => 'DAY53-COUPON',
            'attribution_status' => SuchakGrowthAttribution::STATUS_REVIEW_REQUIRED,
            'fraud_status' => SuchakGrowthAttribution::FRAUD_REVIEW_REQUIRED,
            'fraud_flags' => ['duplicate_device'],
            'attribution_note' => 'Coupon attribution needs fraud review.',
            'attributed_by_admin_user_id' => $admin->id,
            'attributed_at' => now(),
        ]);

        return [$suchakUser, $account, $payment, $correction, $dispute, $qrToken, $attribution, $agreement];
    }
}
