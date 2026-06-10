<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakGrowthAttribution;
use App\Models\SuchakGrowthReward;
use App\Models\SuchakGrowthRewardEvent;
use App\Models\SuchakGrowthRewardRule;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPlatformPayout;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakGrowthRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakGrowthRewardEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_45_growth_reward_tables_are_structured_and_separate_from_member_referral_and_customer_ledger(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_growth_attributions'));
        $this->assertTrue(Schema::hasTable('suchak_growth_reward_rules'));
        $this->assertTrue(Schema::hasTable('suchak_growth_rewards'));
        $this->assertTrue(Schema::hasTable('suchak_growth_reward_events'));

        foreach ([
            'suchak_account_id',
            'attributed_user_id',
            'matrimony_profile_id',
            'customer_context_id',
            'payment_context_id',
            'attribution_source',
            'attribution_policy',
            'attribution_key',
            'referral_code',
            'coupon_code',
            'attribution_status',
            'fraud_status',
            'fraud_flags',
            'attribution_note',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_growth_attributions', $column), $column);
        }

        foreach ([
            'rule_key',
            'reward_trigger',
            'reward_type',
            'attribution_policy',
            'reward_amount',
            'reward_currency',
            'credit_value',
            'admin_action_key',
            'is_active',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_growth_reward_rules', $column), $column);
        }

        foreach ([
            'growth_attribution_id',
            'reward_rule_id',
            'suchak_account_id',
            'customer_context_id',
            'payment_context_id',
            'matrimony_profile_id',
            'platform_payout_id',
            'platform_event_key',
            'reward_trigger',
            'reward_type',
            'reward_status',
            'qualification_source',
            'reversal_reason',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_growth_rewards', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_growth_attributions', 'user_referral_id'));
        $this->assertFalse(Schema::hasColumn('suchak_growth_rewards', 'referral_reward_ledger_id'));
        $this->assertFalse(Schema::hasColumn('suchak_growth_rewards', 'ledger_entry_id'));
        $this->assertFalse(Schema::hasColumn('suchak_growth_rewards', 'customer_payment_id'));
        $this->assertFalse(Schema::hasColumn('suchak_growth_reward_events', 'updated_at'));

        $this->assertContains(SuchakGrowthAttribution::POLICY_FIRST_TOUCH, SuchakGrowthAttribution::POLICIES);
        $this->assertContains(SuchakGrowthAttribution::POLICY_LAST_TOUCH, SuchakGrowthAttribution::POLICIES);
        $this->assertContains(SuchakGrowthAttribution::POLICY_COUPON_PRIORITY, SuchakGrowthAttribution::POLICIES);
        $this->assertContains(SuchakGrowthAttribution::POLICY_ADMIN_OVERRIDE, SuchakGrowthAttribution::POLICIES);
        $this->assertContains(SuchakGrowthRewardRule::TYPE_CASH, SuchakGrowthRewardRule::TYPES);
        $this->assertContains(SuchakGrowthRewardRule::TYPE_CREDIT, SuchakGrowthRewardRule::TYPES);
        $this->assertContains(SuchakGrowthRewardRule::TYPE_ADMIN_ACTION, SuchakGrowthRewardRule::TYPES);
        $this->assertContains(SuchakPlatformPayout::REASON_PLATFORM_GROWTH_REWARD, SuchakPlatformPayout::REASONS);
        $this->assertContains(SuchakPlatformPayout::EVENT_PLATFORM_GROWTH_REWARD_CONFIRMED, SuchakPlatformPayout::PLATFORM_EVENT_TYPES);
    }

    public function test_coupon_attribution_cash_reward_qualifies_only_from_platform_confirmed_payment_without_customer_ledger(): void
    {
        [$admin, , $account, $profile, $customerContext, $paymentContext] = $this->platformContextFixture();
        $service = app(SuchakGrowthRewardService::class);
        $attribution = $this->couponAttribution($service, $account, $admin, $paymentContext, 'coupon:day45:platform-payment');
        $rule = $this->rewardRule($service, $admin, SuchakGrowthRewardRule::TYPE_CASH, 'day45-cash-platform-payment', [
            'reward_amount' => '1200',
        ]);

        $reward = $service->qualifyRewardFromPlatformPayment($attribution, $paymentContext, $rule, $admin, [
            'qualification_note' => 'Platform-confirmed payment qualifies Day-45 growth cash reward.',
        ]);

        $this->assertSame($account->id, $reward->suchak_account_id);
        $this->assertSame($customerContext->id, $reward->customer_context_id);
        $this->assertSame($paymentContext->id, $reward->payment_context_id);
        $this->assertSame($profile->id, $reward->matrimony_profile_id);
        $this->assertSame(SuchakGrowthRewardRule::TYPE_CASH, $reward->reward_type);
        $this->assertSame(SuchakGrowthReward::STATUS_PAYOUT_QUALIFIED, $reward->reward_status);
        $this->assertSame(SuchakGrowthReward::SOURCE_PLATFORM_CONFIRMED_PAYMENT, $reward->qualification_source);
        $this->assertNotNull($reward->platform_payout_id);

        $payout = SuchakPlatformPayout::query()->firstOrFail();
        $this->assertSame($reward->platform_payout_id, $payout->id);
        $this->assertSame(SuchakPlatformPayout::EVENT_PLATFORM_GROWTH_REWARD_CONFIRMED, $payout->platform_event_type);
        $this->assertSame(SuchakPlatformPayout::REASON_PLATFORM_GROWTH_REWARD, $payout->payout_reason);
        $this->assertSame($reward->platform_event_key, $payout->platform_event_key);
        $this->assertSame('1200.00', $payout->amount);
        $this->assertSame(0, SuchakCustomerPayment::query()->count());
        $this->assertSame(0, SuchakLedgerEntry::query()->count());
        $this->assertSame(SuchakGrowthAttribution::STATUS_REWARDED, $attribution->fresh()->attribution_status);

        $audit = AdminAuditLog::query()
            ->where('action_type', 'suchak_growth_reward_qualified')
            ->firstOrFail();
        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => SuchakActivityLog::ACTION_GROWTH_REWARD_QUALIFIED,
            'target_type' => 'suchak_growth_reward',
            'target_id' => $reward->id,
            'admin_audit_log_id' => $audit->id,
        ]);
        $this->assertDatabaseHas('suchak_growth_reward_events', [
            'growth_reward_id' => $reward->id,
            'event_type' => SuchakGrowthRewardEvent::EVENT_REWARD_QUALIFIED,
            'to_status' => SuchakGrowthReward::STATUS_PAYOUT_QUALIFIED,
        ]);
    }

    public function test_paid_suchak_customer_ledger_does_not_qualify_platform_growth_reward(): void
    {
        [$admin, $suchakUser, $account, $profile, $customerContext, $suchakContext] = $this->suchakCollectedContextFixture();
        $service = app(SuchakGrowthRewardService::class);
        $attribution = $this->couponAttribution($service, $account, $admin, $suchakContext, 'coupon:day45:suchak-paid-ledger');
        $rule = $this->rewardRule($service, $admin, SuchakGrowthRewardRule::TYPE_CASH, 'day45-cash-ledger-block', [
            'reward_amount' => '900',
        ]);

        SuchakLedgerEntry::query()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'payment_context_id' => $suchakContext->id,
            'entry_type' => SuchakLedgerEntry::TYPE_CUSTOMER_PAYMENT_RECORDED,
            'amount' => '900',
            'currency' => 'INR',
            'status' => SuchakLedgerEntry::STATUS_PAID,
            'paid_at' => now(),
            'note' => 'Paid Suchak customer ledger must not qualify platform growth reward.',
        ]);

        try {
            $service->qualifyRewardFromPlatformPayment($attribution, $suchakContext, $rule, $admin, [
                'qualification_note' => 'Attempt to qualify from Suchak customer ledger paid status.',
            ]);
            $this->fail('Suchak-collected paid ledger should not qualify platform growth reward.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak growth rewards qualify only from active platform-collected payment contexts.', $exception->getMessage());
        }

        $this->assertSame($suchakUser->id, $account->user_id);
        $this->assertSame(1, SuchakLedgerEntry::query()->where('status', SuchakLedgerEntry::STATUS_PAID)->count());
        $this->assertSame(0, SuchakGrowthReward::query()->count());
        $this->assertSame(0, SuchakPlatformPayout::query()->count());
    }

    public function test_self_referral_and_duplicate_attribution_abuse_are_blocked_or_reviewed(): void
    {
        [$admin, $suchakUser, $account, , , $paymentContext] = $this->platformContextFixture();
        $service = app(SuchakGrowthRewardService::class);

        $selfReferral = $service->recordAttribution($account, $admin, [
            'attributed_user_id' => $suchakUser->id,
            'payment_context_id' => $paymentContext->id,
            'attribution_source' => SuchakGrowthAttribution::SOURCE_REFERRAL_CODE,
            'attribution_policy' => SuchakGrowthAttribution::POLICY_FIRST_TOUCH,
            'attribution_key' => 'referral:self-referral-day45',
            'referral_code' => 'SELF45',
            'attribution_note' => 'Self-referral attempt should go to fraud review.',
        ]);

        $this->assertSame(SuchakGrowthAttribution::STATUS_REVIEW_REQUIRED, $selfReferral->attribution_status);
        $this->assertSame(SuchakGrowthAttribution::FRAUD_REVIEW_REQUIRED, $selfReferral->fraud_status);
        $this->assertContains('self_referral', $selfReferral->fraud_flags);
        $this->assertDatabaseHas('suchak_growth_reward_events', [
            'growth_attribution_id' => $selfReferral->id,
            'event_type' => SuchakGrowthRewardEvent::EVENT_FRAUD_REVIEW_REQUIRED,
        ]);

        $rule = $this->rewardRule($service, $admin, SuchakGrowthRewardRule::TYPE_CASH, 'day45-self-referral-block', [
            'reward_amount' => '500',
        ]);

        try {
            $service->qualifyRewardFromPlatformPayment($selfReferral, $paymentContext, $rule, $admin, [
                'qualification_note' => 'Attempt to qualify reviewed self-referral.',
            ]);
            $this->fail('Self-referral review attribution should not qualify reward.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only active Suchak growth attribution can qualify rewards.', $exception->getMessage());
        }

        $this->couponAttribution($service, $account, $admin, $paymentContext, 'coupon:day45:duplicate');

        try {
            $this->couponAttribution($service, $account, $admin, $paymentContext, 'coupon:day45:duplicate');
            $this->fail('Duplicate Suchak growth attribution should be blocked.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak growth attribution already exists for this source and key.', $exception->getMessage());
        }

        $this->assertSame(0, SuchakGrowthReward::query()->count());
    }

    public function test_credit_and_admin_action_rewards_do_not_create_platform_payouts(): void
    {
        [$admin, , $account, , , $paymentContext] = $this->platformContextFixture();
        $service = app(SuchakGrowthRewardService::class);
        $creditAttribution = $this->couponAttribution($service, $account, $admin, $paymentContext, 'coupon:day45:credit');
        $creditRule = $this->rewardRule($service, $admin, SuchakGrowthRewardRule::TYPE_CREDIT, 'day45-credit-reward', [
            'credit_value' => '300',
        ]);

        $creditReward = $service->qualifyRewardFromPlatformPayment($creditAttribution, $paymentContext, $creditRule, $admin, [
            'qualification_note' => 'Platform payment qualifies Suchak credit growth reward.',
        ]);

        $this->assertSame(SuchakGrowthReward::STATUS_CREDITED, $creditReward->reward_status);
        $this->assertNull($creditReward->platform_payout_id);

        $adminAttribution = $service->recordAttribution($account, $admin, [
            'payment_context_id' => $paymentContext->id,
            'attribution_source' => SuchakGrowthAttribution::SOURCE_ADMIN_OVERRIDE,
            'attribution_policy' => SuchakGrowthAttribution::POLICY_ADMIN_OVERRIDE,
            'attribution_key' => 'admin:day45:manual-review',
            'attribution_note' => 'Admin override growth attribution for manual action reward.',
        ]);
        $adminRule = $this->rewardRule($service, $admin, SuchakGrowthRewardRule::TYPE_ADMIN_ACTION, 'day45-admin-action', [
            'admin_action_key' => 'manual_success_review',
        ]);

        $adminReward = $service->qualifyRewardFromPlatformPayment($adminAttribution, $paymentContext, $adminRule, $admin, [
            'qualification_note' => 'Platform payment qualifies admin-action growth reward.',
        ]);

        $this->assertSame(SuchakGrowthReward::STATUS_ADMIN_ACTION_PENDING, $adminReward->reward_status);
        $this->assertSame('manual_success_review', $adminReward->admin_action_key);
        $this->assertNull($adminReward->platform_payout_id);
        $this->assertSame(0, SuchakPlatformPayout::query()->count());
    }

    public function test_refund_reversal_reverses_growth_reward_and_cancels_unpaid_platform_payout(): void
    {
        [$admin, , $account, , , $paymentContext] = $this->platformContextFixture();
        $service = app(SuchakGrowthRewardService::class);
        $attribution = $this->couponAttribution($service, $account, $admin, $paymentContext, 'coupon:day45:refund-reversal');
        $rule = $this->rewardRule($service, $admin, SuchakGrowthRewardRule::TYPE_CASH, 'day45-refund-reversal', [
            'reward_amount' => '750',
        ]);
        $reward = $service->qualifyRewardFromPlatformPayment($attribution, $paymentContext, $rule, $admin, [
            'qualification_note' => 'Platform payment initially qualifies Day-45 reward.',
        ]);
        $payout = $reward->platformPayout;

        $reversed = $service->reverseRewardForRefund($reward, $admin, [
            'reversal_reason' => 'Customer platform payment was refunded, so growth reward is reversed.',
        ]);

        $this->assertSame(SuchakGrowthReward::STATUS_REVERSED, $reversed->reward_status);
        $this->assertSame($admin->id, $reversed->reversed_by_admin_user_id);
        $this->assertNotNull($reversed->reversed_at);
        $this->assertSame(SuchakGrowthAttribution::STATUS_REVERSED, $attribution->fresh()->attribution_status);
        $this->assertSame(SuchakPlatformPayout::STATUS_CANCELLED, $payout->fresh()->payout_status);
        $this->assertDatabaseHas('suchak_growth_reward_events', [
            'growth_reward_id' => $reward->id,
            'event_type' => SuchakGrowthRewardEvent::EVENT_REWARD_REVERSED,
            'from_status' => SuchakGrowthReward::STATUS_PAYOUT_QUALIFIED,
            'to_status' => SuchakGrowthReward::STATUS_REVERSED,
        ]);

        $event = SuchakGrowthRewardEvent::query()
            ->where('growth_reward_id', $reward->id)
            ->where('event_type', SuchakGrowthRewardEvent::EVENT_REWARD_REVERSED)
            ->firstOrFail();

        try {
            $event->update(['event_note' => 'changed']);
            $this->fail('Suchak growth reward events should be immutable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak growth reward events are immutable and cannot be modified.', $exception->getMessage());
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
            'full_name' => 'Day 45 Growth Reward Candidate',
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'payer_name' => 'Growth reward customer family',
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
            'resolution_note' => 'Day-45 growth reward fixture.',
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

    private function couponAttribution(
        SuchakGrowthRewardService $service,
        SuchakAccount $account,
        User $admin,
        SuchakPaymentContext $paymentContext,
        string $key,
    ): SuchakGrowthAttribution {
        return $service->recordAttribution($account, $admin, [
            'attributed_user_id' => User::factory()->create()->id,
            'matrimony_profile_id' => $paymentContext->matrimony_profile_id,
            'customer_context_id' => $paymentContext->customer_context_id,
            'payment_context_id' => $paymentContext->id,
            'attribution_source' => SuchakGrowthAttribution::SOURCE_COUPON_CODE,
            'attribution_policy' => SuchakGrowthAttribution::POLICY_COUPON_PRIORITY,
            'attribution_key' => $key,
            'coupon_code' => 'DAY45GROWTH',
            'attribution_note' => 'Day-45 coupon attribution recorded by admin.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function rewardRule(
        SuchakGrowthRewardService $service,
        User $admin,
        string $type,
        string $key,
        array $overrides = [],
    ): SuchakGrowthRewardRule {
        return $service->createRewardRule($admin, array_merge([
            'rule_key' => $key,
            'reward_type' => $type,
            'attribution_policy' => SuchakGrowthAttribution::POLICY_COUPON_PRIORITY,
            'reward_amount' => '0',
            'credit_value' => '0',
            'reward_currency' => 'INR',
        ], $overrides));
    }
}
