<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakCustomerPaymentCorrection;
use App\Models\SuchakGrowthAttribution;
use App\Models\SuchakGrowthReward;
use App\Models\SuchakGrowthRewardRule;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPlan;
use App\Models\SuchakPlanPayment;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakIncomeAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuchakIncomeAnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_50_income_analytics_keeps_financial_flows_separate_and_suchak_scoped(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        [$package, $paymentRequest, $payment, $paymentContext] = $this->customerIncomeFixture($account, $user);

        SuchakLedgerEntry::query()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $paymentContext->matrimony_profile_id,
            'payment_context_id' => $paymentContext->id,
            'entry_type' => SuchakLedgerEntry::TYPE_REGISTRATION_FEE_EXPECTED,
            'amount' => '2000',
            'currency' => 'INR',
            'status' => SuchakLedgerEntry::STATUS_DUE,
            'due_date' => now()->subDay()->toDateString(),
            'note' => 'Day-50 overdue standalone ledger amount.',
        ]);
        $this->paymentCorrections($payment, $user);
        $this->platformPlanRevenue($account, $user, '2500');
        $platformPayout = $this->platformPayout($account, $paymentContext, $admin, '1500', SuchakPlatformPayout::STATUS_QUALIFIED);
        $this->platformPayout($account, $paymentContext, $admin, '400', SuchakPlatformPayout::STATUS_ON_HOLD);
        $this->growthRewards($account, $paymentContext, $admin, $platformPayout);
        $this->otherSuchakNoise();

        $summary = app(SuchakIncomeAnalyticsService::class)->summary($account, now());

        $this->assertSame('14000.00', $summary['customer_ledger']['expected_income_amount']);
        $this->assertSame('7000.00', $summary['customer_ledger']['received_income_amount']);
        $this->assertSame('7000.00', $summary['customer_ledger']['pending_amount']);
        $this->assertSame('2000.00', $summary['customer_ledger']['overdue_amount']);
        $this->assertSame('2500.00', $summary['platform_revenue']['plan_payment_received_amount']);
        $this->assertSame('1500.00', $summary['payout_liability']['due_amount']);
        $this->assertSame('400.00', $summary['payout_liability']['held_amount']);
        $this->assertSame('700.00', $summary['referral_rewards']['cash_amount']);
        $this->assertSame('300.00', $summary['referral_rewards']['credit_value']);
        $this->assertSame('4550.00', $summary['net_benefit_amount']);
        $this->assertSame($package->package_name, $summary['package_performance'][0]['package_name']);
        $this->assertSame('12000.00', $summary['package_performance'][0]['requested_amount']);
        $this->assertSame('7000.00', $summary['package_performance'][0]['received_amount']);
        $this->assertSame('manual', $summary['source_performance'][0]['source_type']);

        $this->actingAs($user)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertSee('Income Dashboard', false)
            ->assertSee('Platform revenue', false)
            ->assertSee('Suchak customer ledger', false)
            ->assertSee('Payout liability', false)
            ->assertSee('Referral rewards', false)
            ->assertSee('Package performance', false)
            ->assertSee('Source performance', false)
            ->assertSee('INR 14,000.00', false)
            ->assertSee('INR 7,000.00', false)
            ->assertSee('INR 2,500.00', false)
            ->assertSee('INR 1,500.00', false)
            ->assertSee('INR 4,550.00', false)
            ->assertSee($package->package_name, false)
            ->assertDontSee('Other Account Package', false)
            ->assertDontSee('INR 999,999.00', false);

        $this->assertSame(SuchakPaymentRequest::STATUS_SENT, $paymentRequest->fresh()->payment_status);
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

    /**
     * @return array{0: SuchakServicePackage, 1: SuchakPaymentRequest, 2: SuchakCustomerPayment, 3: SuchakPaymentContext}
     */
    private function customerIncomeFixture(SuchakAccount $account, User $user): array
    {
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Day 50 Income Candidate',
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'payer_name' => 'Day 50 family',
            'payer_relationship_to_candidate' => 'Parent',
            'service_context' => SuchakCustomerContext::SERVICE_PACKAGE_LEAD,
            'source_owner' => SuchakCustomerContext::SOURCE_OWNER_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $user->id,
            'classified_by_user_id' => $user->id,
            'classified_at' => now(),
            'opened_at' => now(),
        ]);
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'package_name' => 'Day 50 Family Coordination',
            'package_description' => 'Income analytics package fixture.',
            'price_amount' => '12000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $user->id,
            'published_at' => now(),
        ]);
        $agreement = SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'service_package_id' => $package->id,
            'agreement_revision' => 1,
            'terms_status' => 'accepted',
            'terms_policy_mode' => 'strict',
            'agreement_snapshot_hash' => hash('sha256', 'day-50-agreement-'.$package->id),
            'package_name' => $package->package_name,
            'package_description' => $package->package_description,
            'price_amount' => '12000',
            'currency' => 'INR',
            'agreement_title' => 'Day-50 accepted terms',
            'agreement_body' => 'Customer accepted Day-50 package terms.',
            'created_by_user_id' => $user->id,
            'accepted_by_user_id' => $user->id,
            'accepted_at' => now(),
        ]);
        $paymentContext = SuchakPaymentContext::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'matrimony_profile_id' => $profile->id,
            'source_owner' => SuchakPaymentContext::SOURCE_SUCHAK,
            'payment_collector' => SuchakPaymentContext::COLLECTOR_SUCHAK,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $user->id,
            'resolution_note' => 'Day-50 direct Suchak collector fixture.',
        ]);
        $paymentRequest = SuchakPaymentRequest::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'service_package_id' => $package->id,
            'customer_agreement_id' => $agreement->id,
            'payment_context_id' => $paymentContext->id,
            'requested_by_user_id' => $user->id,
            'request_token_hash' => hash('sha256', 'day-50-payment-request-'.$account->id),
            'payment_status' => SuchakPaymentRequest::STATUS_SENT,
            'payment_detail_visibility_policy' => SuchakPaymentRequest::VISIBILITY_TERMS_SATISFIED_ONLY,
            'request_title' => 'Day-50 payment request',
            'amount_due' => '12000',
            'currency' => 'INR',
            'collector_disclosure' => 'Payment collector: Suchak.',
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
            'recorded_by_user_id' => $user->id,
            'collection_channel' => SuchakCustomerPayment::CHANNEL_SUCHAK_DIRECT,
            'payment_mode' => SuchakCustomerPayment::MODE_CASH,
            'payment_status' => SuchakCustomerPayment::STATUS_PARTIALLY_PAID,
            'amount_due' => '12000',
            'amount_received' => '7000',
            'balance_amount' => '5000',
            'currency' => 'INR',
            'payment_received_at' => now(),
            'proof_status' => SuchakCustomerPayment::PROOF_NOT_REQUIRED,
            'collection_note' => 'Cash collected for Day-50 analytics fixture.',
        ]);

        return [$package, $paymentRequest, $payment, $paymentContext];
    }

    private function paymentCorrections(SuchakCustomerPayment $payment, User $user): void
    {
        foreach ([
            [SuchakCustomerPaymentCorrection::TYPE_REFUND, SuchakCustomerPaymentCorrection::STATUS_PAID, '1000'],
            [SuchakCustomerPaymentCorrection::TYPE_WAIVER, SuchakCustomerPaymentCorrection::STATUS_POSTED, '300'],
            [SuchakCustomerPaymentCorrection::TYPE_CREDIT_NOTE, SuchakCustomerPaymentCorrection::STATUS_POSTED, '500'],
            [SuchakCustomerPaymentCorrection::TYPE_REVERSAL, SuchakCustomerPaymentCorrection::STATUS_POSTED, '250'],
        ] as [$type, $status, $amount]) {
            SuchakCustomerPaymentCorrection::query()->create([
                'customer_payment_id' => $payment->id,
                'suchak_account_id' => $payment->suchak_account_id,
                'customer_context_id' => $payment->customer_context_id,
                'payment_request_id' => $payment->payment_request_id,
                'ledger_entry_id' => null,
                'correction_type' => $type,
                'correction_status' => $status,
                'amount' => $amount,
                'currency' => 'INR',
                'reason' => 'Day-50 analytics correction.',
                'document_number' => $type === SuchakCustomerPaymentCorrection::TYPE_WAIVER ? null : strtoupper('DAY50-'.$type.'-'.$payment->id),
                'fy_label' => '2026-27',
                'sequence_no' => SuchakCustomerPaymentCorrection::query()->count() + 1,
                'requested_by_user_id' => $user->id,
                'requested_at' => now(),
                'approved_by_user_id' => $status === SuchakCustomerPaymentCorrection::STATUS_PAID ? $user->id : null,
                'approved_at' => $status === SuchakCustomerPaymentCorrection::STATUS_PAID ? now() : null,
                'paid_by_user_id' => $status === SuchakCustomerPaymentCorrection::STATUS_PAID ? $user->id : null,
                'paid_at' => $status === SuchakCustomerPaymentCorrection::STATUS_PAID ? now() : null,
                'posted_by_user_id' => $status === SuchakCustomerPaymentCorrection::STATUS_POSTED ? $user->id : null,
                'posted_at' => $status === SuchakCustomerPaymentCorrection::STATUS_POSTED ? now() : null,
            ]);
        }
    }

    private function platformPlanRevenue(SuchakAccount $account, User $user, string $amount): void
    {
        $plan = SuchakPlan::factory()->create([
            'name' => 'Day 50 Plan',
            'slug' => 'day-50-plan-'.$account->id,
            'price_amount' => $amount,
            'currency' => 'INR',
        ]);

        SuchakPlanPayment::query()->create([
            'suchak_account_id' => $account->id,
            'suchak_plan_id' => $plan->id,
            'initiated_by_user_id' => $user->id,
            'txnid' => 'DAY50PLAN'.$account->id,
            'plan_name' => $plan->name,
            'plan_slug' => $plan->slug,
            'billing_period_days' => 30,
            'amount' => $amount,
            'currency' => 'INR',
            'payment_status' => SuchakPlanPayment::STATUS_SUCCESS,
            'gateway' => 'payu',
            'source' => 'checkout',
            'product_info' => 'Day 50 plan payment',
            'gateway_status' => 'success',
            'paid_at' => now(),
        ]);
    }

    private function platformPayout(
        SuchakAccount $account,
        SuchakPaymentContext $paymentContext,
        User $admin,
        string $netAmount,
        string $status,
    ): SuchakPlatformPayout {
        return SuchakPlatformPayout::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $paymentContext->customer_context_id,
            'payment_context_id' => $paymentContext->id,
            'matrimony_profile_id' => $paymentContext->matrimony_profile_id,
            'platform_event_type' => SuchakPlatformPayout::EVENT_PLATFORM_CUSTOMER_PAYMENT,
            'platform_event_key' => 'day-50-payout-'.$status.'-'.$netAmount.'-'.$account->id,
            'payout_reason' => SuchakPlatformPayout::REASON_PLATFORM_CUSTOMER_PAYMENT_REWARD,
            'qualification_source' => SuchakPlatformPayout::SOURCE_PLATFORM_CONFIRMED_EVENT,
            'payout_status' => $status,
            'amount' => $netAmount,
            'deduction_amount' => '0',
            'reversal_amount' => '0',
            'net_amount' => $netAmount,
            'currency' => 'INR',
            'liability_recognized_at' => now(),
            'qualified_by_user_id' => $admin->id,
            'qualification_note' => 'Day-50 payout analytics fixture.',
            'hold_reason' => $status === SuchakPlatformPayout::STATUS_ON_HOLD ? 'Payout details pending.' : null,
        ]);
    }

    private function growthRewards(
        SuchakAccount $account,
        SuchakPaymentContext $paymentContext,
        User $admin,
        SuchakPlatformPayout $platformPayout,
    ): void {
        $attribution = SuchakGrowthAttribution::query()->create([
            'suchak_account_id' => $account->id,
            'attributed_user_id' => User::factory()->create()->id,
            'matrimony_profile_id' => $paymentContext->matrimony_profile_id,
            'customer_context_id' => $paymentContext->customer_context_id,
            'payment_context_id' => $paymentContext->id,
            'attribution_source' => SuchakGrowthAttribution::SOURCE_REFERRAL_CODE,
            'attribution_policy' => SuchakGrowthAttribution::POLICY_FIRST_TOUCH,
            'attribution_key' => 'day-50-referral-'.$account->id,
            'referral_code' => 'DAY50',
            'attribution_status' => SuchakGrowthAttribution::STATUS_REWARDED,
            'fraud_status' => SuchakGrowthAttribution::FRAUD_CLEAR,
            'attribution_note' => 'Day-50 referral analytics fixture.',
            'attributed_by_admin_user_id' => $admin->id,
            'attributed_at' => now(),
        ]);
        $cashRule = SuchakGrowthRewardRule::query()->create([
            'rule_key' => 'day50-cash-'.$account->id,
            'reward_trigger' => SuchakGrowthRewardRule::TRIGGER_PLATFORM_PAYMENT_CONFIRMED,
            'reward_type' => SuchakGrowthRewardRule::TYPE_CASH,
            'attribution_policy' => SuchakGrowthAttribution::POLICY_FIRST_TOUCH,
            'reward_amount' => '700',
            'reward_currency' => 'INR',
            'credit_value' => '0',
            'is_active' => true,
            'created_by_admin_user_id' => $admin->id,
        ]);
        $creditRule = SuchakGrowthRewardRule::query()->create([
            'rule_key' => 'day50-credit-'.$account->id,
            'reward_trigger' => SuchakGrowthRewardRule::TRIGGER_PLATFORM_PAYMENT_CONFIRMED,
            'reward_type' => SuchakGrowthRewardRule::TYPE_CREDIT,
            'attribution_policy' => SuchakGrowthAttribution::POLICY_FIRST_TOUCH,
            'reward_amount' => '0',
            'reward_currency' => 'INR',
            'credit_value' => '300',
            'is_active' => true,
            'created_by_admin_user_id' => $admin->id,
        ]);

        SuchakGrowthReward::query()->create([
            'growth_attribution_id' => $attribution->id,
            'reward_rule_id' => $cashRule->id,
            'suchak_account_id' => $account->id,
            'customer_context_id' => $paymentContext->customer_context_id,
            'payment_context_id' => $paymentContext->id,
            'matrimony_profile_id' => $paymentContext->matrimony_profile_id,
            'platform_payout_id' => $platformPayout->id,
            'platform_event_key' => 'day-50-cash-reward-'.$account->id,
            'reward_trigger' => SuchakGrowthRewardRule::TRIGGER_PLATFORM_PAYMENT_CONFIRMED,
            'reward_type' => SuchakGrowthRewardRule::TYPE_CASH,
            'reward_status' => SuchakGrowthReward::STATUS_PAYOUT_QUALIFIED,
            'reward_amount' => '700',
            'reward_currency' => 'INR',
            'credit_value' => '0',
            'qualification_source' => SuchakGrowthReward::SOURCE_PLATFORM_CONFIRMED_PAYMENT,
            'fraud_status' => SuchakGrowthAttribution::FRAUD_CLEAR,
            'qualified_by_admin_user_id' => $admin->id,
            'qualified_at' => now(),
        ]);
        SuchakGrowthReward::query()->create([
            'growth_attribution_id' => $attribution->id,
            'reward_rule_id' => $creditRule->id,
            'suchak_account_id' => $account->id,
            'customer_context_id' => $paymentContext->customer_context_id,
            'payment_context_id' => $paymentContext->id,
            'matrimony_profile_id' => $paymentContext->matrimony_profile_id,
            'platform_event_key' => 'day-50-credit-reward-'.$account->id,
            'reward_trigger' => SuchakGrowthRewardRule::TRIGGER_PLATFORM_PAYMENT_CONFIRMED,
            'reward_type' => SuchakGrowthRewardRule::TYPE_CREDIT,
            'reward_status' => SuchakGrowthReward::STATUS_CREDITED,
            'reward_amount' => '0',
            'reward_currency' => 'INR',
            'credit_value' => '300',
            'qualification_source' => SuchakGrowthReward::SOURCE_PLATFORM_CONFIRMED_PAYMENT,
            'fraud_status' => SuchakGrowthAttribution::FRAUD_CLEAR,
            'qualified_by_admin_user_id' => $admin->id,
            'qualified_at' => now(),
        ]);
    }

    private function otherSuchakNoise(): void
    {
        [$otherUser, $otherAccount] = $this->verifiedSuchakActor();
        SuchakServicePackage::query()->create([
            'suchak_account_id' => $otherAccount->id,
            'package_name' => 'Other Account Package',
            'price_amount' => '999999',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $otherUser->id,
            'published_at' => now(),
        ]);
        $this->platformPlanRevenue($otherAccount, $otherUser, '999999');
    }
}
