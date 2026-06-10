<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\Payment;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerOverdueServiceAction;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakCustomerPaymentCorrection;
use App\Models\SuchakCustomerPaymentCorrectionEvent;
use App\Models\SuchakCustomerPaymentDocument;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPlanPayment;
use App\Models\SuchakPolicy;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakCustomerPaymentCorrectionService;
use App\Modules\Suchak\Services\SuchakCustomerPaymentService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPaymentRequestService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakPaymentCorrectionOverdueFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_39_correction_and_overdue_tables_are_structured_no_delete_records(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_customer_payment_corrections'));
        $this->assertTrue(Schema::hasTable('suchak_customer_payment_correction_events'));
        $this->assertTrue(Schema::hasTable('suchak_customer_overdue_service_actions'));

        foreach ([
            'customer_payment_id',
            'suchak_account_id',
            'customer_context_id',
            'payment_request_id',
            'ledger_entry_id',
            'correction_type',
            'correction_status',
            'amount',
            'currency',
            'reason',
            'document_number',
            'requested_by_user_id',
            'approved_by_user_id',
            'paid_by_user_id',
            'posted_by_user_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_customer_payment_corrections', $column), $column);
        }

        foreach ([
            'payment_correction_id',
            'event_type',
            'actor_type',
            'from_status',
            'to_status',
            'occurred_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_customer_payment_correction_events', $column), $column);
        }

        foreach ([
            'customer_payment_id',
            'action_type',
            'action_status',
            'action_policy',
            'due_amount',
            'reason',
            'resolved_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_customer_overdue_service_actions', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_customer_payment_corrections', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_customer_overdue_service_actions', 'profile_hidden_at'));
        $this->assertFalse(Schema::hasColumn('suchak_customer_overdue_service_actions', 'profile_deleted_at'));
    }

    public function test_refund_request_approval_paid_lifecycle_preserves_original_payment_and_documents(): void
    {
        [$suchakUser, , , $payment] = $this->customerPaymentFixture('15000');
        $originalPayment = $payment->fresh(['documents']);
        $originalDocumentNumbers = $originalPayment->documents->pluck('document_number')->sort()->values()->all();

        $service = app(SuchakCustomerPaymentCorrectionService::class);

        $refund = $service->requestRefund(
            $payment,
            $suchakUser,
            [
                'amount' => '5000',
                'reason' => 'Customer cancelled one paid add-on service.',
            ],
            '127.0.0.1',
            'Day-39 refund request test',
        );

        $this->assertSame(SuchakCustomerPaymentCorrection::TYPE_REFUND, $refund->correction_type);
        $this->assertSame(SuchakCustomerPaymentCorrection::STATUS_REQUESTED, $refund->correction_status);
        $this->assertSame('5000.00', $refund->amount);
        $this->assertNull($refund->ledger_entry_id);

        $approved = $service->approveRefund($refund, $suchakUser, 'Refund approved after offline review.');
        $this->assertSame(SuchakCustomerPaymentCorrection::STATUS_APPROVED, $approved->correction_status);
        $this->assertSame($suchakUser->id, $approved->approved_by_user_id);
        $this->assertNotNull($approved->approved_at);

        $paid = $service->markRefundPaid($approved, $suchakUser, [
            'status_note' => 'Refund paid outside platform gateway.',
        ]);
        $this->assertSame(SuchakCustomerPaymentCorrection::STATUS_PAID, $paid->correction_status);
        $this->assertSame($suchakUser->id, $paid->paid_by_user_id);
        $this->assertNotNull($paid->paid_at);
        $this->assertNotNull($paid->ledger_entry_id);
        $this->assertSame(SuchakLedgerEntry::TYPE_CUSTOMER_REFUND_PAID, $paid->ledgerEntry->entry_type);
        $this->assertSame(SuchakLedgerEntry::STATUS_PAID, $paid->ledgerEntry->status);

        $paymentAfter = $payment->fresh(['documents']);
        $this->assertSame('15000.00', $paymentAfter->amount_received);
        $this->assertSame(SuchakCustomerPayment::STATUS_PAID, $paymentAfter->payment_status);
        $this->assertSame($originalDocumentNumbers, $paymentAfter->documents->pluck('document_number')->sort()->values()->all());
        $this->assertSame(2, $paymentAfter->documents->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, SuchakPlanPayment::query()->count());

        $this->assertDatabaseHas('suchak_customer_payment_correction_events', [
            'payment_correction_id' => $paid->id,
            'event_type' => SuchakCustomerPaymentCorrectionEvent::EVENT_REFUND_PAID,
            'to_status' => SuchakCustomerPaymentCorrection::STATUS_PAID,
        ]);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'actor_user_id' => $suchakUser->id,
            'action_type' => \App\Models\SuchakActivityLog::ACTION_CUSTOMER_REFUND_PAID,
            'target_type' => 'suchak_customer_payment_correction',
            'target_id' => $paid->id,
        ]);

        try {
            $service->requestRefund($paymentAfter, $suchakUser, [
                'amount' => '11000',
                'reason' => 'Too much refund.',
            ]);
            $this->fail('Refund amount should be limited by remaining received amount.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak customer refund amount cannot exceed remaining received amount.', $exception->getMessage());
        }
    }

    public function test_waiver_credit_note_and_reversal_are_separate_correction_records(): void
    {
        [$suchakUser, , , $payment] = $this->customerPaymentFixture('5000');
        $originalDocs = $payment->fresh(['documents'])->documents->pluck('document_number')->sort()->values()->all();
        $service = app(SuchakCustomerPaymentCorrectionService::class);

        $waiver = $service->postWaiver($payment, $suchakUser, [
            'amount' => '2500',
            'reason' => 'Waive part of remaining Suchak service fee.',
        ]);
        $this->assertSame(SuchakCustomerPaymentCorrection::TYPE_WAIVER, $waiver->correction_type);
        $this->assertSame(SuchakCustomerPaymentCorrection::STATUS_POSTED, $waiver->correction_status);
        $this->assertSame(SuchakLedgerEntry::TYPE_CUSTOMER_WAIVER_POSTED, $waiver->ledgerEntry->entry_type);
        $this->assertSame(SuchakLedgerEntry::STATUS_WAIVED, $waiver->ledgerEntry->status);
        $this->assertNull($waiver->document_number);

        $creditNote = $service->issueCreditNote($payment, $suchakUser, [
            'amount' => '1000',
            'reason' => 'Issue credit note for overquoted service item.',
        ]);
        $this->assertSame(SuchakCustomerPaymentCorrection::TYPE_CREDIT_NOTE, $creditNote->correction_type);
        $this->assertStringStartsWith('SUCHAK-CUST-CN/', (string) $creditNote->document_number);
        $this->assertSame(SuchakLedgerEntry::TYPE_CUSTOMER_CREDIT_NOTE_ISSUED, $creditNote->ledgerEntry->entry_type);
        $this->assertSame(SuchakLedgerEntry::STATUS_ADJUSTED, $creditNote->ledgerEntry->status);

        $reversal = $service->postReversal($payment, $suchakUser, [
            'amount' => '500',
            'reason' => 'Reverse duplicate manual entry without deleting original payment.',
        ]);
        $this->assertSame(SuchakCustomerPaymentCorrection::TYPE_REVERSAL, $reversal->correction_type);
        $this->assertStringStartsWith('SUCHAK-CUST-REV/', (string) $reversal->document_number);
        $this->assertSame(SuchakLedgerEntry::TYPE_CUSTOMER_PAYMENT_REVERSAL, $reversal->ledgerEntry->entry_type);
        $this->assertSame(SuchakLedgerEntry::STATUS_ADJUSTED, $reversal->ledgerEntry->status);

        $paymentAfter = $payment->fresh(['documents']);
        $this->assertSame(SuchakCustomerPayment::STATUS_PARTIALLY_PAID, $paymentAfter->payment_status);
        $this->assertSame('5000.00', $paymentAfter->amount_received);
        $this->assertSame('10000.00', $paymentAfter->balance_amount);
        $this->assertSame($originalDocs, $paymentAfter->documents->pluck('document_number')->sort()->values()->all());
        $this->assertSame(2, $paymentAfter->documents->count());
    }

    public function test_overdue_action_affects_suchak_service_only_and_never_mutates_canonical_profile(): void
    {
        [$suchakUser, , $profile, $payment] = $this->customerPaymentFixture('0');
        $originalState = $profile->fresh()->lifecycle_state;
        $originalSuspended = (bool) $profile->fresh()->is_suspended;

        $service = app(SuchakCustomerPaymentCorrectionService::class);
        $action = $service->openOverdueServiceAction(
            $payment,
            $suchakUser,
            [
                'action_type' => SuchakCustomerOverdueServiceAction::TYPE_SERVICE_PAUSE_WARNING,
                'reason' => 'Payment pending beyond agreed internal service follow-up window.',
            ],
            '127.0.0.1',
            'Day-39 overdue action test',
        );

        $this->assertSame(SuchakCustomerOverdueServiceAction::STATUS_OPEN, $action->action_status);
        $this->assertSame(SuchakCustomerOverdueServiceAction::POLICY_SUCHAK_SERVICE_ONLY, $action->action_policy);
        $this->assertSame('15000.00', $action->due_amount);
        $this->assertSame($originalState, $profile->fresh()->lifecycle_state);
        $this->assertSame($originalSuspended, (bool) $profile->fresh()->is_suspended);
        $this->assertTrue(MatrimonyProfile::query()->whereKey($profile->id)->exists());

        $resolved = $service->resolveOverdueServiceAction(
            $action,
            $suchakUser,
            'Customer replied; continue internal Suchak follow-up.',
        );
        $this->assertSame(SuchakCustomerOverdueServiceAction::STATUS_RESOLVED, $resolved->action_status);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame($originalState, $profile->fresh()->lifecycle_state);
        $this->assertSame($originalSuspended, (bool) $profile->fresh()->is_suspended);

        try {
            $resolved->delete();
            $this->fail('Suchak overdue service action delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak customer overdue service actions cannot be deleted.', $exception->getMessage());
        }
    }

    public function test_correction_records_and_events_cannot_be_deleted_or_mutated(): void
    {
        [$suchakUser, , , $payment] = $this->customerPaymentFixture('15000');
        $refund = app(SuchakCustomerPaymentCorrectionService::class)->requestRefund($payment, $suchakUser, [
            'amount' => '1000',
            'reason' => 'Refund immutability test.',
        ]);
        $event = $refund->events()->firstOrFail();

        try {
            $refund->delete();
            $this->fail('Suchak correction delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak customer payment corrections cannot be deleted.', $exception->getMessage());
        }

        try {
            $event->update(['event_note' => 'changed']);
            $this->fail('Suchak correction event update should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak customer payment correction events are immutable and cannot be modified.', $exception->getMessage());
        }
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: MatrimonyProfile, 3: SuchakCustomerPayment}
     */
    private function customerPaymentFixture(string $amountReceived): array
    {
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Auto publish packages for Day-39 correction fixture.',
                'is_active' => true,
            ],
        );

        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Day 39 Candidate',
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
                'package_name' => 'Day-39 Family Coordination',
                'package_description' => 'Structured customer package for payment correction test.',
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
                'agreement_title' => 'Day-39 agreement terms',
                'agreement_body' => 'Customer confirms the package scope before correction rules.',
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
            'resolution_note' => 'Day-39 correction fixture.',
        ]);

        $requestResult = app(SuchakPaymentRequestService::class)->createAndSend(
            $package->fresh(['suchakAccount.user', 'customerContext', 'stages', 'deliverables.servicePackageStage']),
            $agreement->fresh(['suchakAccount', 'customerContext', 'servicePackage', 'stages', 'deliverables']),
            $paymentContext->fresh(['suchakAccount', 'customerContext', 'matrimonyProfile']),
            $suchakUser,
        );

        $paymentPayload = [
            'payment_mode' => SuchakCustomerPayment::MODE_CASH,
            'amount_received' => $amountReceived,
        ];
        if ((float) $amountReceived > 0.0) {
            $paymentPayload['collection_note'] = 'Cash collected at Suchak office.';
        }

        $payment = app(SuchakCustomerPaymentService::class)->recordManualPayment(
            $requestResult['payment_request'],
            $suchakUser,
            $paymentPayload,
        )['customer_payment'];

        return [$suchakUser, $account, $profile, $payment->fresh(['documents', 'paymentContext', 'corrections'])];
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
