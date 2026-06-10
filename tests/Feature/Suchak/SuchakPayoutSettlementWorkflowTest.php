<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakPlatformPayoutDetail;
use App\Models\SuchakPlatformPayoutEvent;
use App\Models\SuchakPlatformPayoutSettlement;
use App\Models\SuchakPlatformPayoutSettlementLine;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPlatformPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class SuchakPayoutSettlementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_43_settlement_tables_and_payout_workflow_columns_are_structured(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_platform_payout_settlements'));
        $this->assertTrue(Schema::hasTable('suchak_platform_payout_settlement_lines'));

        foreach ([
            'settlement_statement_id',
            'deduction_amount',
            'reversal_amount',
            'net_amount',
            'paid_by_user_id',
            'paid_at',
            'payout_reference_number',
            'payout_reference_note',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_platform_payouts', $column), $column);
        }

        foreach ([
            'suchak_account_id',
            'statement_number',
            'statement_month',
            'period_start',
            'period_end',
            'statement_status',
            'payout_count',
            'gross_amount',
            'deduction_amount',
            'reversal_amount',
            'net_amount',
            'currency',
            'statement_hash',
            'generated_by_admin_user_id',
            'generated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_platform_payout_settlements', $column), $column);
        }

        foreach ([
            'settlement_statement_id',
            'platform_payout_id',
            'suchak_account_id',
            'line_type',
            'gross_amount',
            'deduction_amount',
            'reversal_amount',
            'net_amount',
            'currency',
            'line_note',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_platform_payout_settlement_lines', $column), $column);
        }

        $this->assertTrue(Schema::hasColumn('suchak_platform_payout_events', 'settlement_statement_id'));
        $this->assertFalse(Schema::hasColumn('suchak_platform_payouts', 'customer_payment_id'));
        $this->assertFalse(Schema::hasColumn('suchak_platform_payouts', 'ledger_entry_id'));
        $this->assertContains(SuchakPlatformPayout::STATUS_PAID, SuchakPlatformPayout::STATUSES);
        $this->assertContains(SuchakPlatformPayoutEvent::EVENT_PAID, [
            SuchakPlatformPayoutEvent::EVENT_PAID,
            SuchakPlatformPayoutEvent::EVENT_REVERSED,
            SuchakPlatformPayoutEvent::EVENT_SETTLEMENT_REGENERATED,
        ]);
    }

    public function test_admin_can_approve_pay_and_regenerate_monthly_settlement_with_reference_and_deductions(): void
    {
        [$admin, , $account, , , $paymentContext] = $this->platformContextFixture();
        $service = app(SuchakPlatformPayoutService::class);

        $payout = $service->qualifyFromPlatformEvent($paymentContext, $admin, $this->verifiedPayoutPayload('platform-payment-4301'));

        $approved = $service->approvePayout($payout, $admin, [
            'deduction_amount' => '100',
            'status_note' => 'Admin approved payout after verified bank details and liability review.',
        ]);

        $this->assertSame(SuchakPlatformPayout::STATUS_APPROVED, $approved->payout_status);
        $this->assertSame('100.00', $approved->deduction_amount);
        $this->assertSame('2400.00', $approved->net_amount);

        $paid = $service->markPayoutPaid($approved, $admin, [
            'payout_reference_number' => 'NMM-PAYOUT-4301',
            'payout_reference_note' => 'Paid through admin bank transfer for Day-43 settlement.',
            'paid_at' => '2026-06-15 10:00:00',
        ]);

        $this->assertSame(SuchakPlatformPayout::STATUS_PAID, $paid->payout_status);
        $this->assertSame('NMM-PAYOUT-4301', $paid->payout_reference_number);
        $this->assertSame('2026-06-15 10:00:00', $paid->paid_at?->format('Y-m-d H:i:s'));
        $this->assertNotNull($paid->settlement_statement_id);

        $settlement = SuchakPlatformPayoutSettlement::query()->firstOrFail();
        $this->assertSame($account->id, $settlement->suchak_account_id);
        $this->assertSame('SPS-'.str_pad((string) $account->id, 6, '0', STR_PAD_LEFT).'-202606', $settlement->statement_number);
        $this->assertSame('2026-06', $settlement->statement_month);
        $this->assertSame(1, $settlement->payout_count);
        $this->assertSame('2500.00', $settlement->gross_amount);
        $this->assertSame('100.00', $settlement->deduction_amount);
        $this->assertSame('0.00', $settlement->reversal_amount);
        $this->assertSame('2400.00', $settlement->net_amount);
        $this->assertSame(64, strlen($settlement->statement_hash));

        $line = SuchakPlatformPayoutSettlementLine::query()->firstOrFail();
        $this->assertSame($settlement->id, $line->settlement_statement_id);
        $this->assertSame($paid->id, $line->platform_payout_id);
        $this->assertSame('2500.00', $line->gross_amount);
        $this->assertSame('100.00', $line->deduction_amount);
        $this->assertSame('2400.00', $line->net_amount);

        $hashBeforeRegeneration = $settlement->statement_hash;
        $regenerated = $service->generateMonthlySettlementStatement($account, $admin, '2026-06');
        $this->assertSame($settlement->id, $regenerated->id);
        $this->assertSame($hashBeforeRegeneration, $regenerated->statement_hash);

        $this->assertDatabaseHas('suchak_platform_payout_events', [
            'platform_payout_id' => $paid->id,
            'settlement_statement_id' => $settlement->id,
            'event_type' => SuchakPlatformPayoutEvent::EVENT_SETTLEMENT_REGENERATED,
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action_type' => 'suchak_platform_payout_settlement_generated',
            'entity_type' => 'SuchakPlatformPayoutSettlement',
            'entity_id' => $settlement->id,
        ]);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'action_type' => SuchakActivityLog::ACTION_PLATFORM_PAYOUT_SETTLEMENT_GENERATED,
            'target_type' => 'suchak_platform_payout_settlement',
            'target_id' => $settlement->id,
        ]);
        $this->assertSame(0, SuchakCustomerPayment::query()->count());
        $this->assertSame(0, SuchakLedgerEntry::query()->count());
    }

    public function test_paid_payout_can_be_reversed_and_statement_rebuilds_without_mutating_details(): void
    {
        [$admin, , , , , $paymentContext] = $this->platformContextFixture();
        $service = app(SuchakPlatformPayoutService::class);
        $payout = $service->qualifyFromPlatformEvent($paymentContext, $admin, $this->verifiedPayoutPayload('platform-payment-4302'));
        $approved = $service->approvePayout($payout, $admin, [
            'deduction_amount' => '100',
            'status_note' => 'Admin approved payout for reversal workflow coverage.',
        ]);
        $paid = $service->markPayoutPaid($approved, $admin, [
            'payout_reference_number' => 'NMM-PAYOUT-4302',
            'payout_reference_note' => 'Paid before later reversal review.',
            'paid_at' => '2026-06-20 11:00:00',
        ]);
        $detail = $paid->latestDetail();
        $this->assertInstanceOf(SuchakPlatformPayoutDetail::class, $detail);

        $reversed = $service->reversePayout($paid, $admin, [
            'reversal_reason' => 'Admin reversed payout after bank return confirmation.',
        ]);

        $this->assertSame(SuchakPlatformPayout::STATUS_REVERSED, $reversed->payout_status);
        $this->assertSame('2400.00', $reversed->reversal_amount);
        $this->assertSame('0.00', $reversed->net_amount);
        $this->assertSame('NMM-PAYOUT-4302', $reversed->payout_reference_number);

        $settlement = SuchakPlatformPayoutSettlement::query()->firstOrFail();
        $this->assertSame('2500.00', $settlement->gross_amount);
        $this->assertSame('100.00', $settlement->deduction_amount);
        $this->assertSame('2400.00', $settlement->reversal_amount);
        $this->assertSame('0.00', $settlement->net_amount);
        $this->assertDatabaseHas('suchak_platform_payout_settlement_lines', [
            'settlement_statement_id' => $settlement->id,
            'platform_payout_id' => $reversed->id,
            'reversal_amount' => '2400.00',
            'net_amount' => '0.00',
        ]);

        $this->assertSame($detail->id, $reversed->latestDetail()?->id);
        $this->assertSame(SuchakPlatformPayoutDetail::STATUS_VERIFIED, $reversed->latestDetail()?->verification_status);

        try {
            $service->verifyPayoutDetails($reversed, $admin, [
                'verification_status' => SuchakPlatformPayoutDetail::STATUS_VERIFIED,
                'verification_note' => 'Attempted detail change after reversal.',
            ]);
            $this->fail('Reversed payout details should remain final.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Finalized Suchak platform payouts cannot have payout details changed.', $exception->getMessage());
        }
    }

    public function test_admin_payout_report_route_exposes_workflow_and_separates_liability_from_revenue(): void
    {
        [$admin, , , , , $paymentContext] = $this->platformContextFixture();
        $payout = app(SuchakPlatformPayoutService::class)->qualifyFromPlatformEvent(
            $paymentContext,
            $admin,
            $this->verifiedPayoutPayload('platform-payment-4303'),
        );

        $response = $this->actingAs($admin)->get(route('admin.suchak.payouts.index', [
            'statement_month' => '2026-06',
        ]));

        $response->assertOk();
        $response->assertSee('Suchak Payouts');
        $response->assertSee('Payout Workflow');
        $response->assertSee('suchak_platform_payouts_only');
        $response->assertSee(route('admin.suchak.payouts.approve', $payout), false);
        $this->assertSame(0, SuchakCustomerPayment::query()->count());
        $this->assertSame(0, SuchakLedgerEntry::query()->count());

        $postResponse = $this->actingAs($admin)->post(route('admin.suchak.payouts.approve', $payout), [
            'deduction_amount' => '0',
            'status_note' => 'Admin approved payout through the Day-43 admin route.',
        ]);

        $postResponse->assertRedirect(route('admin.suchak.payouts.index'));
        $postResponse->assertSessionHas('success', 'Suchak platform payout approved.');
        $this->assertSame(SuchakPlatformPayout::STATUS_APPROVED, $payout->fresh()->payout_status);
    }

    /**
     * @return array{0: User, 1: User, 2: SuchakAccount, 3: MatrimonyProfile, 4: SuchakCustomerContext, 5: SuchakPaymentContext}
     */
    private function platformContextFixture(): array
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Day 43 Platform Customer',
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'payer_name' => 'Platform customer family',
            'payer_relationship_to_candidate' => 'Parent',
            'service_context' => SuchakCustomerContext::SERVICE_PACKAGE_LEAD,
            'source_owner' => SuchakPaymentContext::SOURCE_PLATFORM,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_PLATFORM_REQUEST,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $suchakUser->id,
            'classified_by_user_id' => $suchakUser->id,
            'classified_at' => now(),
            'opened_at' => now(),
        ]);
        $paymentContext = SuchakPaymentContext::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'matrimony_profile_id' => $profile->id,
            'source_owner' => SuchakPaymentContext::SOURCE_PLATFORM,
            'payment_collector' => SuchakPaymentContext::COLLECTOR_PLATFORM,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $suchakUser->id,
            'resolution_note' => 'Day-43 platform payout fixture.',
        ]);

        return [
            $admin,
            $suchakUser,
            $account,
            $profile,
            $customerContext,
            $paymentContext->fresh(['suchakAccount', 'customerContext', 'matrimonyProfile']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function verifiedPayoutPayload(string $eventKey): array
    {
        return [
            'platform_event_type' => SuchakPlatformPayout::EVENT_PLATFORM_CUSTOMER_PAYMENT,
            'platform_event_key' => $eventKey,
            'payout_reason' => SuchakPlatformPayout::REASON_PLATFORM_CUSTOMER_PAYMENT_REWARD,
            'qualification_source' => SuchakPlatformPayout::SOURCE_PLATFORM_CONFIRMED_EVENT,
            'amount' => '2500',
            'currency' => 'INR',
            'qualification_note' => 'Platform-confirmed payment qualifies payout liability.',
            'payout_details' => [
                'payout_method' => SuchakPlatformPayoutDetail::METHOD_BANK_TRANSFER,
                'payout_detail_reference' => 'bank-ref-'.$eventKey,
                'beneficiary_name' => 'Verified Suchak Office',
                'account_last_four' => '4321',
                'ifsc_code' => 'HDFC0001234',
                'verification_status' => SuchakPlatformPayoutDetail::STATUS_VERIFIED,
                'verification_note' => 'Payout bank details verified before settlement.',
            ],
        ];
    }
}
