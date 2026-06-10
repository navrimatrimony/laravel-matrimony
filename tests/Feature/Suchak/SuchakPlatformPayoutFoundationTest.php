<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
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
use App\Models\SuchakPayoutHold;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPlatformPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakPlatformPayoutFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_42_platform_payout_tables_are_structured_and_separate_from_customer_ledger(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_platform_payouts'));
        $this->assertTrue(Schema::hasTable('suchak_platform_payout_details'));
        $this->assertTrue(Schema::hasTable('suchak_platform_payout_events'));

        foreach ([
            'suchak_account_id',
            'customer_context_id',
            'payment_context_id',
            'matrimony_profile_id',
            'platform_event_type',
            'platform_event_key',
            'payout_reason',
            'qualification_source',
            'payout_status',
            'amount',
            'currency',
            'liability_recognized_at',
            'qualified_by_user_id',
            'qualification_note',
            'hold_reason',
            'approved_by_user_id',
            'approved_at',
            'cancelled_by_user_id',
            'cancelled_at',
            'reversed_by_user_id',
            'reversed_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_platform_payouts', $column), $column);
        }

        foreach ([
            'platform_payout_id',
            'suchak_account_id',
            'payout_method',
            'payout_detail_reference',
            'beneficiary_name',
            'account_last_four',
            'ifsc_code',
            'upi_handle_masked',
            'verification_status',
            'verification_note',
            'created_by_user_id',
            'verified_by_user_id',
            'verified_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_platform_payout_details', $column), $column);
        }

        foreach ([
            'platform_payout_id',
            'suchak_account_id',
            'event_type',
            'actor_type',
            'actor_user_id',
            'from_status',
            'to_status',
            'event_note',
            'occurred_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_platform_payout_events', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_platform_payouts', 'customer_payment_id'));
        $this->assertFalse(Schema::hasColumn('suchak_platform_payouts', 'ledger_entry_id'));
        $this->assertFalse(Schema::hasColumn('suchak_platform_payout_details', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_platform_payout_events', 'deleted_at'));
        $this->assertContains(SuchakPlatformPayout::STATUS_APPROVED, SuchakPlatformPayout::STATUSES);
        $this->assertContains(SuchakPlatformPayout::STATUS_CANCELLED, SuchakPlatformPayout::STATUSES);
        $this->assertContains(SuchakPlatformPayout::STATUS_REVERSED, SuchakPlatformPayout::STATUSES);
    }

    public function test_admin_can_record_platform_confirmed_payout_liability_with_pending_details_hold(): void
    {
        [$admin, , $account, $profile, $customerContext, $paymentContext] = $this->platformContextFixture();
        $beforeUpdatedAt = $profile->updated_at?->toDateTimeString();

        $payout = app(SuchakPlatformPayoutService::class)->qualifyFromPlatformEvent(
            $paymentContext,
            $admin,
            [
                'platform_event_type' => SuchakPlatformPayout::EVENT_PLATFORM_CUSTOMER_PAYMENT,
                'platform_event_key' => 'platform-payment-1001',
                'payout_reason' => SuchakPlatformPayout::REASON_PLATFORM_CUSTOMER_PAYMENT_REWARD,
                'qualification_source' => SuchakPlatformPayout::SOURCE_PLATFORM_CONFIRMED_EVENT,
                'amount' => '2500',
                'currency' => 'INR',
                'qualification_note' => 'Platform payment succeeded and reward liability is qualified.',
                'payout_details' => [
                    'payout_method' => SuchakPlatformPayoutDetail::METHOD_BANK_TRANSFER,
                    'beneficiary_name' => 'Verified Suchak Office',
                    'account_last_four' => '4321',
                    'ifsc_code' => 'HDFC0001234',
                    'verification_status' => SuchakPlatformPayoutDetail::STATUS_PENDING,
                    'verification_note' => 'Bank details pending verification.',
                ],
            ],
            '127.0.0.1',
            'Day-42 payout qualification test',
        );

        $this->assertSame($account->id, $payout->suchak_account_id);
        $this->assertSame($customerContext->id, $payout->customer_context_id);
        $this->assertSame($paymentContext->id, $payout->payment_context_id);
        $this->assertSame($profile->id, $payout->matrimony_profile_id);
        $this->assertSame(SuchakPlatformPayout::STATUS_ON_HOLD, $payout->payout_status);
        $this->assertSame('2500.00', $payout->amount);
        $this->assertSame('INR', $payout->currency);
        $this->assertSame('Suchak payout details verification is pending.', $payout->hold_reason);
        $this->assertNotNull($payout->liability_recognized_at);

        $detail = SuchakPlatformPayoutDetail::query()->firstOrFail();
        $this->assertSame($payout->id, $detail->platform_payout_id);
        $this->assertSame(SuchakPlatformPayoutDetail::METHOD_BANK_TRANSFER, $detail->payout_method);
        $this->assertSame(SuchakPlatformPayoutDetail::STATUS_PENDING, $detail->verification_status);
        $this->assertSame('4321', $detail->account_last_four);

        $this->assertDatabaseHas('suchak_platform_payout_events', [
            'platform_payout_id' => $payout->id,
            'event_type' => SuchakPlatformPayoutEvent::EVENT_QUALIFIED,
            'actor_type' => SuchakPlatformPayoutEvent::ACTOR_ADMIN,
            'actor_user_id' => $admin->id,
            'to_status' => SuchakPlatformPayout::STATUS_ON_HOLD,
        ]);
        $this->assertDatabaseHas('suchak_platform_payout_events', [
            'platform_payout_id' => $payout->id,
            'event_type' => SuchakPlatformPayoutEvent::EVENT_STATUS_HELD,
            'to_status' => SuchakPlatformPayout::STATUS_ON_HOLD,
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action_type' => 'suchak_platform_payout_qualified',
            'entity_type' => 'SuchakPlatformPayout',
            'entity_id' => $payout->id,
        ]);
        $audit = AdminAuditLog::query()->where('action_type', 'suchak_platform_payout_qualified')->firstOrFail();
        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => SuchakActivityLog::ACTION_PLATFORM_PAYOUT_QUALIFIED,
            'target_type' => 'suchak_platform_payout',
            'target_id' => $payout->id,
            'admin_audit_log_id' => $audit->id,
        ]);
        $this->assertSame(0, SuchakCustomerPayment::query()->count());
        $this->assertSame(0, SuchakLedgerEntry::query()->count());
        $this->assertSame($beforeUpdatedAt, $profile->fresh()->updated_at?->toDateTimeString());
    }

    public function test_verified_details_release_payout_to_qualified_unless_payment_risk_hold_exists(): void
    {
        [$admin, , $account, , $customerContext, $paymentContext] = $this->platformContextFixture();
        $service = app(SuchakPlatformPayoutService::class);
        $payout = $service->qualifyFromPlatformEvent($paymentContext, $admin, $this->payoutPayload('platform-payment-1002'));

        $qualified = $service->verifyPayoutDetails($payout, $admin, [
            'verification_status' => SuchakPlatformPayoutDetail::STATUS_VERIFIED,
            'verification_note' => 'Payout bank details verified by admin.',
            'payout_method' => SuchakPlatformPayoutDetail::METHOD_BANK_TRANSFER,
            'payout_detail_reference' => 'bank-ref-verified-1002',
            'beneficiary_name' => 'Verified Suchak Office',
            'account_last_four' => '4321',
            'ifsc_code' => 'HDFC0001234',
        ]);

        $this->assertSame(SuchakPlatformPayout::STATUS_QUALIFIED, $qualified->payout_status);
        $this->assertNull($qualified->hold_reason);
        $this->assertSame(SuchakPlatformPayoutDetail::STATUS_VERIFIED, $qualified->latestDetail()?->verification_status);
        $this->assertNotNull($qualified->latestDetail()?->verified_at);

        SuchakPayoutHold::query()->create([
            'suchak_dispute_id' => null,
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'payment_context_id' => $paymentContext->id,
            'hold_scope' => SuchakPayoutHold::SCOPE_DIRECT_PAYMENT_RISK,
            'hold_status' => SuchakPayoutHold::STATUS_ACTIVE,
            'hold_reason' => 'Payment risk review hold for Day-42 payout.',
            'created_by_user_id' => $admin->id,
        ]);

        $held = $service->qualifyFromPlatformEvent(
            $paymentContext,
            $admin,
            array_merge($this->payoutPayload('platform-payment-1003'), [
                'payout_details' => [
                    'payout_method' => SuchakPlatformPayoutDetail::METHOD_BANK_TRANSFER,
                    'payout_detail_reference' => 'bank-ref-verified-1003',
                    'beneficiary_name' => 'Verified Suchak Office',
                    'account_last_four' => '4321',
                    'ifsc_code' => 'HDFC0001234',
                    'verification_status' => SuchakPlatformPayoutDetail::STATUS_VERIFIED,
                    'verification_note' => 'Verified details are still held by payment-risk review.',
                ],
            ]),
        );

        $this->assertSame(SuchakPlatformPayout::STATUS_ON_HOLD, $held->payout_status);
        $this->assertSame('Suchak payout is held because an active payment-risk review exists.', $held->hold_reason);
    }

    public function test_payout_qualification_rejects_suchak_self_claim_and_suchak_collected_context(): void
    {
        [$admin, $suchakUser, , , , $platformContext] = $this->platformContextFixture();
        $service = app(SuchakPlatformPayoutService::class);

        try {
            $service->qualifyFromPlatformEvent($platformContext, $suchakUser, $this->payoutPayload('self-claim-user'));
            $this->fail('Suchak user self-claim should not qualify platform payout.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only admins can qualify platform-to-Suchak payouts.', $exception->getMessage());
        }

        try {
            $service->qualifyFromPlatformEvent(
                $platformContext,
                $admin,
                array_merge($this->payoutPayload('self-claim-source'), ['qualification_source' => 'suchak_self_claim']),
            );
            $this->fail('Self-claim qualification source should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak platform payout qualification source must be platform-confirmed.', $exception->getMessage());
        }

        [, , , , , $suchakCollectedContext] = $this->suchakCollectedContextFixture();
        try {
            $service->qualifyFromPlatformEvent($suchakCollectedContext, $admin, $this->payoutPayload('direct-context'));
            $this->fail('Suchak-collected customer context should not qualify platform payout.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only platform-collected payment contexts can qualify platform-to-Suchak payouts.', $exception->getMessage());
        }

        $this->assertSame(0, SuchakPlatformPayout::query()->count());
    }

    public function test_platform_payout_records_and_events_are_non_deletable_and_events_are_immutable(): void
    {
        [$admin, , , , , $paymentContext] = $this->platformContextFixture();
        $payout = app(SuchakPlatformPayoutService::class)->qualifyFromPlatformEvent($paymentContext, $admin, $this->payoutPayload('platform-payment-1004'));
        $detail = $payout->details()->firstOrFail();
        $event = $payout->events()->firstOrFail();

        try {
            $payout->delete();
            $this->fail('Suchak platform payout delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak platform payout records cannot be deleted.', $exception->getMessage());
        }

        try {
            $detail->delete();
            $this->fail('Suchak platform payout detail delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak platform payout detail records cannot be deleted.', $exception->getMessage());
        }

        try {
            $event->update(['event_note' => 'changed']);
            $this->fail('Suchak platform payout event update should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak platform payout events are immutable and cannot be modified.', $exception->getMessage());
        }

        try {
            $event->delete();
            $this->fail('Suchak platform payout event delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak platform payout events are immutable and cannot be deleted.', $exception->getMessage());
        }
    }

    /**
     * @return array{0: User, 1: User, 2: SuchakAccount, 3: MatrimonyProfile, 4: SuchakCustomerContext, 5: SuchakPaymentContext}
     */
    private function platformContextFixture(): array
    {
        return $this->contextFixture(SuchakPaymentContext::SOURCE_PLATFORM, SuchakPaymentContext::COLLECTOR_PLATFORM);
    }

    /**
     * @return array{0: User, 1: User, 2: SuchakAccount, 3: MatrimonyProfile, 4: SuchakCustomerContext, 5: SuchakPaymentContext}
     */
    private function suchakCollectedContextFixture(): array
    {
        return $this->contextFixture(SuchakPaymentContext::SOURCE_SUCHAK, SuchakPaymentContext::COLLECTOR_SUCHAK);
    }

    /**
     * @return array{0: User, 1: User, 2: SuchakAccount, 3: MatrimonyProfile, 4: SuchakCustomerContext, 5: SuchakPaymentContext}
     */
    private function contextFixture(string $sourceOwner, string $collector): array
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
            'full_name' => 'Day 42 Platform Customer',
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
            'source_owner' => $sourceOwner,
            'source_type' => $sourceOwner === SuchakPaymentContext::SOURCE_PLATFORM
                ? SuchakCustomerContext::SOURCE_TYPE_PLATFORM_REQUEST
                : SuchakCustomerContext::SOURCE_TYPE_MANUAL,
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
            'source_owner' => $sourceOwner,
            'payment_collector' => $collector,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $suchakUser->id,
            'resolution_note' => 'Day-42 platform payout fixture.',
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
    private function payoutPayload(string $eventKey): array
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
                'beneficiary_name' => 'Verified Suchak Office',
                'account_last_four' => '4321',
                'ifsc_code' => 'HDFC0001234',
                'verification_status' => SuchakPlatformPayoutDetail::STATUS_PENDING,
                'verification_note' => 'Payout details pending verification.',
            ],
        ];
    }
}
