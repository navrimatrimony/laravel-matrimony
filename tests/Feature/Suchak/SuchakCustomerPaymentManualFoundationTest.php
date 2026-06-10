<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\Payment;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakCustomerPaymentDocument;
use App\Models\SuchakCustomerPaymentEvent;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPlanPayment;
use App\Models\SuchakPolicy;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakCustomerPaymentService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPaymentRequestService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Services\RevenueAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakCustomerPaymentManualFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_payment_tables_exist_without_payu_or_platform_gateway_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_customer_payments'));
        $this->assertTrue(Schema::hasTable('suchak_customer_payment_documents'));
        $this->assertTrue(Schema::hasTable('suchak_customer_payment_events'));

        foreach ([
            'suchak_account_id',
            'customer_context_id',
            'service_package_id',
            'customer_agreement_id',
            'payment_context_id',
            'payment_request_id',
            'ledger_entry_id',
            'recorded_by_user_id',
            'collection_channel',
            'payment_mode',
            'payment_status',
            'amount_due',
            'amount_received',
            'balance_amount',
            'currency',
            'payment_received_at',
            'payment_reference',
            'proof_status',
            'proof_document_path',
            'proof_note',
            'collection_note',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_customer_payments', $column), $column);
        }

        foreach ([
            'customer_payment_id',
            'document_type',
            'document_number',
            'fy_label',
            'sequence_no',
            'verification_code',
            'issued_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_customer_payment_documents', $column), $column);
        }

        foreach (['gateway', 'payu_txnid', 'gateway_txnid', 'response_hash', 'payload_json'] as $forbiddenColumn) {
            $this->assertFalse(Schema::hasColumn('suchak_customer_payments', $forbiddenColumn), $forbiddenColumn);
        }
    }

    public function test_suchak_records_partial_upi_payment_with_invoice_receipt_qr_and_ledger_link_without_payu(): void
    {
        [$suchakUser, , $paymentRequest] = $this->paymentRequestFixture();

        $result = app(SuchakCustomerPaymentService::class)->recordManualPayment(
            $paymentRequest,
            $suchakUser,
            [
                'payment_mode' => SuchakCustomerPayment::MODE_UPI,
                'amount_received' => '5000',
                'payment_reference' => 'UPI-REF-DAY38-001',
                'proof_note' => 'Customer shared bank app confirmation.',
            ],
            '127.0.0.1',
            'Day-38 upi test',
        );

        /** @var SuchakCustomerPayment $payment */
        $payment = $result['customer_payment'];
        $invoice = $result['invoice'];
        $receipt = $result['receipt'];

        $this->assertSame(SuchakCustomerPayment::CHANNEL_SUCHAK_DIRECT, $payment->collection_channel);
        $this->assertSame(SuchakCustomerPayment::MODE_UPI, $payment->payment_mode);
        $this->assertSame(SuchakCustomerPayment::STATUS_PARTIALLY_PAID, $payment->payment_status);
        $this->assertSame(SuchakCustomerPayment::PROOF_SUBMITTED, $payment->proof_status);
        $this->assertSame('15000.00', $payment->amount_due);
        $this->assertSame('5000.00', $payment->amount_received);
        $this->assertSame('10000.00', $payment->balance_amount);
        $this->assertNotNull($payment->ledger_entry_id);
        $this->assertSame(SuchakPaymentRequest::STATUS_PARTIALLY_PAID, $paymentRequest->fresh()->payment_status);

        $ledger = $payment->ledgerEntry;
        $this->assertSame(SuchakLedgerEntry::TYPE_CUSTOMER_PAYMENT_RECORDED, $ledger->entry_type);
        $this->assertSame(SuchakLedgerEntry::STATUS_PAID, $ledger->status);
        $this->assertSame('5000.00', $ledger->amount);
        $this->assertSame($payment->payment_context_id, $ledger->payment_context_id);

        $this->assertSame(SuchakCustomerPaymentDocument::TYPE_INVOICE, $invoice->document_type);
        $this->assertStringStartsWith('SUCHAK-CUST-INV/', $invoice->document_number);
        $this->assertSame(SuchakCustomerPaymentDocument::TYPE_RECEIPT, $receipt->document_type);
        $this->assertStringStartsWith('SUCHAK-CUST-RCP/', $receipt->document_number);
        $this->assertSame(32, strlen((string) $receipt->verification_code));
        $this->assertSame(
            route('suchak.receipts.verify', ['code' => $receipt->verification_code], true),
            $result['receipt_verification_url'],
        );

        $this->get(route('suchak.receipts.verify', ['code' => $receipt->verification_code]))
            ->assertOk()
            ->assertSee($receipt->document_number)
            ->assertSee('INR 5000.00')
            ->assertDontSee('UPI-REF-DAY38-001');

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, SuchakPlanPayment::query()->count());
        $this->assertSame([], app(RevenueAnalyticsService::class)->getDailyRevenue(now()->subDay(), now()->addDay()));

        $this->assertDatabaseHas('suchak_customer_payment_events', [
            'customer_payment_id' => $payment->id,
            'event_type' => SuchakCustomerPaymentEvent::EVENT_PAYMENT_RECORDED,
            'to_status' => SuchakCustomerPayment::STATUS_PARTIALLY_PAID,
        ]);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'actor_user_id' => $suchakUser->id,
            'action_type' => \App\Models\SuchakActivityLog::ACTION_CUSTOMER_PAYMENT_RECORDED,
            'target_type' => 'suchak_customer_payment',
            'target_id' => $payment->id,
        ]);
    }

    public function test_pending_payment_creates_invoice_only_and_proof_rules_block_unsafe_records(): void
    {
        [$suchakUser, , $pendingRequest] = $this->paymentRequestFixture();

        $pending = app(SuchakCustomerPaymentService::class)->recordManualPayment(
            $pendingRequest,
            $suchakUser,
            [
                'payment_mode' => SuchakCustomerPayment::MODE_CASH,
                'amount_received' => '0',
            ],
        );

        $this->assertSame(SuchakCustomerPayment::STATUS_PENDING, $pending['customer_payment']->payment_status);
        $this->assertSame(SuchakCustomerPayment::PROOF_NOT_REQUIRED, $pending['customer_payment']->proof_status);
        $this->assertStringStartsWith('SUCHAK-CUST-INV/', $pending['invoice']->document_number);
        $this->assertNull($pending['receipt']);
        $this->assertNull($pending['receipt_verification_url']);
        $this->assertSame(SuchakPaymentRequest::STATUS_PENDING, $pendingRequest->fresh()->payment_status);

        [$upiUser, , $upiRequest] = $this->paymentRequestFixture();
        try {
            app(SuchakCustomerPaymentService::class)->recordManualPayment(
                $upiRequest,
                $upiUser,
                [
                    'payment_mode' => SuchakCustomerPayment::MODE_BANK_TRANSFER,
                    'amount_received' => '1000',
                ],
            );

            $this->fail('Bank transfer payment proof should be mandatory.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('UPI, bank transfer, and cheque Suchak customer payments require proof reference or proof document path.', $exception->getMessage());
        }

        [$cashUser, , $cashRequest] = $this->paymentRequestFixture();
        try {
            app(SuchakCustomerPaymentService::class)->recordManualPayment(
                $cashRequest,
                $cashUser,
                [
                    'payment_mode' => SuchakCustomerPayment::MODE_CASH,
                    'amount_received' => '1000',
                ],
            );

            $this->fail('Cash payment collection note should be mandatory.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Cash Suchak customer payment requires a collection note.', $exception->getMessage());
        }
    }

    public function test_full_cash_payment_marks_request_paid_and_direct_records_cannot_be_deleted(): void
    {
        [$suchakUser, , $paymentRequest] = $this->paymentRequestFixture();

        $result = app(SuchakCustomerPaymentService::class)->recordManualPayment(
            $paymentRequest,
            $suchakUser,
            [
                'payment_mode' => SuchakCustomerPayment::MODE_CASH,
                'amount_received' => '15000',
                'collection_note' => 'Cash collected at office and counted by Suchak.',
            ],
        );

        $payment = $result['customer_payment'];
        $receipt = $result['receipt'];
        $event = $payment->events()->firstOrFail();

        $this->assertSame(SuchakCustomerPayment::STATUS_PAID, $payment->payment_status);
        $this->assertSame(SuchakPaymentRequest::STATUS_PAID, $paymentRequest->fresh()->payment_status);
        $this->assertSame('0.00', $payment->balance_amount);
        $this->assertNotNull($receipt);

        try {
            $payment->delete();
            $this->fail('Suchak customer payment delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak customer payment records cannot be deleted.', $exception->getMessage());
        }

        try {
            $receipt->delete();
            $this->fail('Suchak customer payment document delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak customer payment documents cannot be deleted.', $exception->getMessage());
        }

        try {
            $event->update(['event_note' => 'changed']);
            $this->fail('Suchak customer payment event update should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak customer payment events are immutable and cannot be modified.', $exception->getMessage());
        }
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakPaymentRequest}
     */
    private function paymentRequestFixture(): array
    {
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Auto publish packages for Day-38 payment fixture.',
                'is_active' => true,
            ],
        );

        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Day 38 Candidate',
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'payer_name' => 'Candidate family',
            'payer_relationship_to_candidate' => 'Parent',
            'service_context' => SuchakCustomerContext::SERVICE_PACKAGE_LEAD,
            'source_owner' => SuchakPaymentContext::SOURCE_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $suchakUser->id,
            'classified_by_user_id' => $suchakUser->id,
            'classified_at' => now(),
            'opened_at' => now(),
        ]);

        $package = app(SuchakPackageCatalogService::class)->createCustomPackage(
            $account,
            $suchakUser,
            [
                'package_name' => 'Day-38 Family Coordination',
                'package_description' => 'Structured customer package for manual payment test.',
                'price_amount' => '15000',
                'currency' => 'INR',
            ],
            $this->stagePayload(),
            $this->deliverablePayload(),
            $customerContext,
        );

        $agreement = app(SuchakAgreementService::class)->createAgreementForPackage(
            $package,
            $suchakUser,
            [
                'agreement_title' => 'Day-38 agreement terms',
                'agreement_body' => 'Customer confirms the package scope before manual payment.',
            ],
        );
        $agreement = app(SuchakAgreementService::class)->acceptTerms($agreement, $suchakUser);

        $paymentContext = SuchakPaymentContext::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'matrimony_profile_id' => $profile->id,
            'source_owner' => SuchakPaymentContext::SOURCE_SUCHAK,
            'payment_collector' => SuchakPaymentContext::COLLECTOR_SUCHAK,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $suchakUser->id,
            'resolution_note' => 'Day-38 manual payment fixture.',
        ]);

        $requestResult = app(SuchakPaymentRequestService::class)->createAndSend(
            $package->fresh(['suchakAccount.user', 'customerContext', 'stages', 'deliverables.servicePackageStage']),
            $agreement->fresh(['suchakAccount', 'customerContext', 'servicePackage', 'stages', 'deliverables']),
            $paymentContext->fresh(['suchakAccount', 'customerContext', 'matrimonyProfile']),
            $suchakUser,
        );

        return [$suchakUser, $account, $requestResult['payment_request']];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stagePayload(): array
    {
        return [
            [
                'stage_key' => 'intake_and_shortlist',
                'stage_name' => 'Intake and shortlist',
                'stage_description' => 'Collect requirements and prepare shortlist.',
                'sort_order' => 10,
                'expected_days' => 7,
            ],
            [
                'stage_key' => 'family_coordination',
                'stage_name' => 'Family coordination',
                'stage_description' => 'Coordinate discussion and next steps.',
                'sort_order' => 20,
                'expected_days' => 14,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function deliverablePayload(): array
    {
        return [
            [
                'stage_key' => 'intake_and_shortlist',
                'deliverable_key' => 'shortlist_report',
                'deliverable_name' => 'Shortlist report',
                'deliverable_description' => 'Candidate shortlist summary.',
                'sort_order' => 10,
            ],
            [
                'stage_key' => 'family_coordination',
                'deliverable_key' => 'meeting_followup',
                'deliverable_name' => 'Meeting follow-up',
                'deliverable_description' => 'Follow-up notes after discussion.',
                'sort_order' => 20,
            ],
        ];
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function verifiedSuchakActor(): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        return [$user, $account];
    }
}
