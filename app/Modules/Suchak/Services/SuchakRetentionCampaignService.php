<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\SuchakAccount;
use App\Models\SuchakCampaignQualification;
use App\Models\SuchakCampaignRule;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakGrowthReward;
use App\Models\SuchakGrowthRewardRule;
use App\Models\SuchakLoyaltyTierSnapshot;
use App\Models\SuchakMonthlyValueReport;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPlatformLeadAllocation;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakRetentionOffer;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakRetentionCampaignService
{
    public function __construct(
        private readonly SuchakAccessService $accessService,
        private readonly SuchakPolicyService $policyService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function adminSummary(?string $month = null, int $limit = 20): array
    {
        $month = $this->month($month ?: now()->format('Y-m'));

        return [
            'month' => $month,
            'loyalty_policy' => $this->policyService->loyaltyTierPolicy(),
            'campaign_rules' => SuchakCampaignRule::query()
                ->with('createdByAdmin')
                ->latest()
                ->limit($limit)
                ->get(),
            'recent_qualifications' => SuchakCampaignQualification::query()
                ->with(['campaignRule', 'suchakAccount.user', 'qualifiedByAdmin'])
                ->latest()
                ->limit($limit)
                ->get(),
            'recent_reports' => SuchakMonthlyValueReport::query()
                ->with(['suchakAccount.user', 'loyaltyTierSnapshot', 'generatedByAdmin'])
                ->latest()
                ->limit($limit)
                ->get(),
            'recent_offers' => SuchakRetentionOffer::query()
                ->with(['suchakAccount.user', 'monthlyValueReport', 'offeredByAdmin'])
                ->latest()
                ->limit($limit)
                ->get(),
            'accounts' => SuchakAccount::query()
                ->with('user')
                ->where('verification_status', SuchakAccount::VERIFICATION_VERIFIED)
                ->latest()
                ->limit($limit)
                ->get(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createCampaignRule(User $admin, array $attributes): SuchakCampaignRule
    {
        $this->accessService->assertAdmin($admin, 'Only admins can create Suchak campaign rules.');

        $payload = [
            'campaign_key' => $this->campaignKey($attributes['campaign_key'] ?? null),
            'campaign_name' => $this->requiredText($attributes['campaign_name'] ?? null, 'Suchak campaign name is required.', 160),
            'campaign_goal' => $this->allowed($attributes['campaign_goal'] ?? SuchakCampaignRule::GOAL_RETENTION, SuchakCampaignRule::GOALS, 'Suchak campaign goal is invalid.'),
            'qualification_metric' => $this->allowed($attributes['qualification_metric'] ?? null, SuchakCampaignRule::METRICS, 'Suchak campaign qualification metric is invalid.'),
            'threshold_value' => $this->money($attributes['threshold_value'] ?? 0, 'Suchak campaign threshold is invalid.'),
            'bonus_type' => $this->allowed($attributes['bonus_type'] ?? null, SuchakCampaignRule::BONUS_TYPES, 'Suchak campaign bonus type is invalid.'),
            'bonus_amount' => $this->money($attributes['bonus_amount'] ?? 0, 'Suchak campaign bonus amount is invalid.'),
            'bonus_currency' => $this->currency($attributes['bonus_currency'] ?? 'INR'),
            'campaign_status' => $this->allowed($attributes['campaign_status'] ?? SuchakCampaignRule::STATUS_ACTIVE, SuchakCampaignRule::STATUSES, 'Suchak campaign status is invalid.'),
            'starts_at' => $this->nullableDateTime($attributes['starts_at'] ?? null, 'Suchak campaign start date is invalid.'),
            'ends_at' => $this->nullableDateTime($attributes['ends_at'] ?? null, 'Suchak campaign end date is invalid.'),
            'created_by_admin_user_id' => $admin->id,
        ];

        if ($payload['ends_at'] instanceof Carbon && $payload['starts_at'] instanceof Carbon && $payload['ends_at']->lt($payload['starts_at'])) {
            throw new InvalidArgumentException('Suchak campaign end date must be after start date.');
        }

        return DB::transaction(function () use ($admin, $payload): SuchakCampaignRule {
            if (SuchakCampaignRule::query()->where('campaign_key', $payload['campaign_key'])->lockForUpdate()->exists()) {
                throw new InvalidArgumentException('Suchak campaign key is already used.');
            }

            $rule = SuchakCampaignRule::query()->create($payload);
            $audit = $this->audit(
                $admin,
                'suchak_campaign_rule_created',
                $rule,
                'Suchak campaign rule created: '.$rule->campaign_name.'.',
            );
            $rule->forceFill(['admin_audit_log_id' => $audit->id])->save();

            return $rule->fresh(['createdByAdmin', 'adminAuditLog']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function qualifyCampaignBonus(
        SuchakCampaignRule $rule,
        SuchakAccount $account,
        User $admin,
        array $attributes = [],
    ): SuchakCampaignQualification {
        $this->accessService->assertAdmin($admin, 'Only admins can qualify Suchak campaign bonuses.');
        $rule = $rule->fresh() ?? $rule;
        $account = $account->fresh() ?? $account;
        $month = $this->month($attributes['qualification_month'] ?? now()->format('Y-m'));
        $metricValue = $this->money($attributes['metric_value'] ?? $this->metricValue($account, $rule->qualification_metric, $month), 'Suchak campaign qualification metric value is invalid.');
        $note = $this->requiredText($attributes['qualification_note'] ?? null, 'Suchak campaign qualification note is required.', 1000);

        $this->assertRuleQualifies($rule, $metricValue);

        return DB::transaction(function () use ($rule, $account, $admin, $month, $metricValue, $note): SuchakCampaignQualification {
            $existing = SuchakCampaignQualification::query()
                ->where('campaign_rule_id', $rule->id)
                ->where('suchak_account_id', $account->id)
                ->where('qualification_month', $month)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof SuchakCampaignQualification) {
                throw new InvalidArgumentException('Suchak campaign bonus is already qualified for this account and month.');
            }

            $qualification = SuchakCampaignQualification::query()->create([
                'campaign_rule_id' => $rule->id,
                'suchak_account_id' => $account->id,
                'qualification_month' => $month,
                'metric_value' => $metricValue,
                'qualification_status' => SuchakCampaignQualification::STATUS_QUALIFIED,
                'bonus_type' => $rule->bonus_type,
                'bonus_amount' => $rule->bonus_amount,
                'bonus_currency' => $rule->bonus_currency,
                'qualification_note' => $note,
                'qualified_by_admin_user_id' => $admin->id,
                'qualified_at' => now(),
            ]);

            $audit = $this->audit(
                $admin,
                'suchak_campaign_bonus_qualified',
                $qualification,
                $note,
            );
            $qualification->forceFill(['admin_audit_log_id' => $audit->id])->save();

            return $qualification->fresh(['campaignRule', 'suchakAccount', 'qualifiedByAdmin', 'adminAuditLog']);
        });
    }

    public function generateMonthlyValueReport(SuchakAccount $account, User $admin, string $month): SuchakMonthlyValueReport
    {
        $this->accessService->assertAdmin($admin, 'Only admins can generate Suchak monthly value reports.');
        $account = $account->fresh() ?? $account;
        $month = $this->month($month);

        return DB::transaction(function () use ($account, $admin, $month): SuchakMonthlyValueReport {
            $existing = SuchakMonthlyValueReport::query()
                ->where('suchak_account_id', $account->id)
                ->where('report_month', $month)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof SuchakMonthlyValueReport) {
                throw new InvalidArgumentException('Suchak monthly value report already exists for this account and month.');
            }

            $snapshot = SuchakLoyaltyTierSnapshot::query()
                ->where('suchak_account_id', $account->id)
                ->where('snapshot_month', $month)
                ->lockForUpdate()
                ->first();

            if (! $snapshot instanceof SuchakLoyaltyTierSnapshot) {
                $snapshot = $this->generateLoyaltySnapshot($account, $admin, $month);
            }

            $metrics = $this->metricsForMonth($account, $month);
            $report = SuchakMonthlyValueReport::query()->create([
                'suchak_account_id' => $account->id,
                'report_month' => $month,
                'loyalty_tier_snapshot_id' => $snapshot->id,
                'platform_leads_count' => $metrics['platform_leads_count'],
                'platform_customer_value_amount' => $metrics['platform_customer_value_amount'],
                'suchak_customer_value_amount' => $metrics['suchak_customer_value_amount'],
                'platform_payout_amount' => $metrics['platform_payout_amount'],
                'campaign_bonus_amount' => $metrics['campaign_bonus_amount'],
                'growth_reward_cash_amount' => $metrics['growth_reward_cash_amount'],
                'unsupported_claims_count' => 0,
                'unsupported_claims_note' => 'Only platform-recorded leads, payouts, customer payments, and auditable campaign bonuses are counted; public success claims are not asserted.',
                'report_status' => SuchakMonthlyValueReport::STATUS_GENERATED,
                'generated_by_admin_user_id' => $admin->id,
                'generated_at' => now(),
            ]);

            $audit = $this->audit(
                $admin,
                'suchak_monthly_value_report_generated',
                $report,
                'Suchak monthly value report generated for '.$month.'.',
            );
            $report->forceFill(['admin_audit_log_id' => $audit->id])->save();

            return $report->fresh(['suchakAccount', 'loyaltyTierSnapshot', 'generatedByAdmin', 'adminAuditLog']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createRetentionOffer(
        SuchakAccount $account,
        User $admin,
        array $attributes,
        ?SuchakMonthlyValueReport $report = null,
    ): SuchakRetentionOffer {
        $this->accessService->assertAdmin($admin, 'Only admins can create Suchak retention offers.');
        $account = $account->fresh() ?? $account;
        $report = $report?->fresh();

        if ($report instanceof SuchakMonthlyValueReport && (int) $report->suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('Suchak retention offer report must belong to the same account.');
        }

        $offerType = $this->allowed($attributes['offer_type'] ?? null, SuchakRetentionOffer::TYPES, 'Suchak retention offer type is invalid.');
        $discountPercent = $this->nullablePercent($attributes['discount_percent'] ?? null);
        $revenueSharePercent = $this->nullablePercent($attributes['revenue_share_percent'] ?? null);

        if ($offerType === SuchakRetentionOffer::TYPE_RENEWAL_DISCOUNT && $discountPercent === null) {
            throw new InvalidArgumentException('Renewal discount offers require a discount percent.');
        }

        if ($offerType === SuchakRetentionOffer::TYPE_REVENUE_SHARE && $revenueSharePercent === null) {
            throw new InvalidArgumentException('Revenue share offers require a revenue share percent.');
        }

        return DB::transaction(function () use ($account, $admin, $attributes, $report, $offerType, $discountPercent, $revenueSharePercent): SuchakRetentionOffer {
            $offer = SuchakRetentionOffer::query()->create([
                'suchak_account_id' => $account->id,
                'monthly_value_report_id' => $report?->id,
                'offer_type' => $offerType,
                'offer_status' => SuchakRetentionOffer::STATUS_OFFERED,
                'discount_percent' => $discountPercent,
                'revenue_share_percent' => $revenueSharePercent,
                'offer_amount' => $this->nullableMoney($attributes['offer_amount'] ?? null),
                'currency' => $this->currency($attributes['currency'] ?? 'INR'),
                'offer_note' => $this->requiredText($attributes['offer_note'] ?? null, 'Suchak retention offer note is required.', 1000),
                'offered_by_admin_user_id' => $admin->id,
                'offered_at' => now(),
            ]);

            $audit = $this->audit(
                $admin,
                'suchak_retention_offer_created',
                $offer,
                $offer->offer_note,
            );
            $offer->forceFill(['admin_audit_log_id' => $audit->id])->save();

            return $offer->fresh(['suchakAccount', 'monthlyValueReport', 'offeredByAdmin', 'adminAuditLog']);
        });
    }

    public function generateLoyaltySnapshot(SuchakAccount $account, ?User $admin, string $month): SuchakLoyaltyTierSnapshot
    {
        if ($admin instanceof User) {
            $this->accessService->assertAdmin($admin, 'Only admins can generate Suchak loyalty tier snapshots.');
        }

        $month = $this->month($month);
        $metrics = $this->metricsForMonth($account, $month);
        $tierScore = $this->tierScore($metrics);
        $tier = $this->tierForScore($tierScore);

        return DB::transaction(function () use ($account, $admin, $month, $metrics, $tierScore, $tier): SuchakLoyaltyTierSnapshot {
            $existing = SuchakLoyaltyTierSnapshot::query()
                ->where('suchak_account_id', $account->id)
                ->where('snapshot_month', $month)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof SuchakLoyaltyTierSnapshot) {
                return $existing->fresh(['suchakAccount', 'generatedByAdmin', 'adminAuditLog']);
            }

            $snapshot = SuchakLoyaltyTierSnapshot::query()->create([
                'suchak_account_id' => $account->id,
                'snapshot_month' => $month,
                'policy_key' => SuchakPolicyService::KEY_SUCHAK_LOYALTY_TIER_POLICY_JSON,
                'tier_key' => $tier['tier_key'],
                'tier_label' => $tier['tier_label'],
                'tier_score' => $tierScore,
                'platform_leads_count' => $metrics['platform_leads_count'],
                'platform_value_amount' => $metrics['platform_customer_value_amount'],
                'verified_representation_count' => $metrics['verified_representation_count'],
                'active_customer_count' => $metrics['active_customer_count'],
                'generated_by_admin_user_id' => $admin?->id,
                'generated_at' => now(),
            ]);

            if ($admin instanceof User) {
                $audit = $this->audit(
                    $admin,
                    'suchak_loyalty_tier_snapshot_generated',
                    $snapshot,
                    'Suchak loyalty tier snapshot generated for '.$month.'.',
                );
                $snapshot->forceFill(['admin_audit_log_id' => $audit->id])->save();
            }

            return $snapshot->fresh(['suchakAccount', 'generatedByAdmin', 'adminAuditLog']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function metricsForMonth(SuchakAccount $account, string $month): array
    {
        [$start, $end] = $this->period($month);

        $platformLeads = SuchakPlatformLeadAllocation::query()
            ->where('suchak_account_id', $account->id)
            ->whereIn('allocation_status', [
                SuchakPlatformLeadAllocation::STATUS_ACCEPTED,
                SuchakPlatformLeadAllocation::STATUS_CONVERTED,
            ])
            ->whereBetween('allocated_at', [$start, $end])
            ->count();

        $platformPayouts = SuchakPlatformPayout::query()
            ->where('suchak_account_id', $account->id)
            ->whereBetween('liability_recognized_at', [$start, $end]);

        $platformCustomerValue = (float) (clone $platformPayouts)
            ->where('platform_event_type', SuchakPlatformPayout::EVENT_PLATFORM_CUSTOMER_PAYMENT)
            ->sum('amount');

        $platformPayoutAmount = (float) (clone $platformPayouts)->sum('amount');

        $suchakCustomerValue = (float) SuchakCustomerPayment::query()
            ->where('suchak_account_id', $account->id)
            ->whereIn('payment_status', [
                SuchakCustomerPayment::STATUS_PARTIALLY_PAID,
                SuchakCustomerPayment::STATUS_PAID,
            ])
            ->whereBetween('payment_received_at', [$start, $end])
            ->whereHas('paymentContext', function ($query): void {
                $query->where('source_owner', SuchakPaymentContext::SOURCE_SUCHAK);
            })
            ->sum('amount_received');

        $campaignBonusAmount = (float) SuchakCampaignQualification::query()
            ->where('suchak_account_id', $account->id)
            ->where('qualification_month', $month)
            ->where('qualification_status', SuchakCampaignQualification::STATUS_QUALIFIED)
            ->sum('bonus_amount');

        $growthRewardCashAmount = (float) SuchakGrowthReward::query()
            ->where('suchak_account_id', $account->id)
            ->where('reward_type', SuchakGrowthRewardRule::TYPE_CASH)
            ->whereNotIn('reward_status', [
                SuchakGrowthReward::STATUS_REVERSED,
                SuchakGrowthReward::STATUS_REJECTED,
            ])
            ->whereBetween('qualified_at', [$start, $end])
            ->sum('reward_amount');

        $verifiedRepresentationCount = SuchakProfileRepresentation::query()
            ->where('suchak_account_id', $account->id)
            ->where('representation_status', SuchakProfileRepresentation::STATUS_ACTIVE)
            ->count();

        $activeCustomerCount = SuchakCustomerContext::query()
            ->where('suchak_account_id', $account->id)
            ->whereIn('customer_lifecycle_status', [
                SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
                SuchakCustomerContext::STATUS_COMPLETED,
            ])
            ->count();

        return [
            'platform_leads_count' => $platformLeads,
            'platform_customer_value_amount' => number_format($platformCustomerValue, 2, '.', ''),
            'suchak_customer_value_amount' => number_format($suchakCustomerValue, 2, '.', ''),
            'platform_payout_amount' => number_format($platformPayoutAmount, 2, '.', ''),
            'campaign_bonus_amount' => number_format($campaignBonusAmount, 2, '.', ''),
            'growth_reward_cash_amount' => number_format($growthRewardCashAmount, 2, '.', ''),
            'verified_representation_count' => $verifiedRepresentationCount,
            'active_customer_count' => $activeCustomerCount,
        ];
    }

    private function metricValue(SuchakAccount $account, string $metric, string $month): string
    {
        $metrics = $this->metricsForMonth($account, $month);

        return match ($metric) {
            SuchakCampaignRule::METRIC_PLATFORM_LEADS => (string) $metrics['platform_leads_count'],
            SuchakCampaignRule::METRIC_VERIFIED_REPRESENTATIONS => (string) $metrics['verified_representation_count'],
            default => (string) $metrics['platform_customer_value_amount'],
        };
    }

    private function assertRuleQualifies(SuchakCampaignRule $rule, string $metricValue): void
    {
        if ($rule->campaign_status !== SuchakCampaignRule::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Only active Suchak campaign rules can qualify bonuses.');
        }

        $now = now();
        if ($rule->starts_at instanceof Carbon && $rule->starts_at->isFuture()) {
            throw new InvalidArgumentException('Suchak campaign has not started.');
        }

        if ($rule->ends_at instanceof Carbon && $rule->ends_at->lt($now)) {
            throw new InvalidArgumentException('Suchak campaign has ended.');
        }

        if ((float) $metricValue < (float) $rule->threshold_value) {
            throw new InvalidArgumentException('Suchak campaign metric does not meet the qualification threshold.');
        }
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function tierScore(array $metrics): int
    {
        $score = ((int) $metrics['platform_leads_count']) * 10;
        $score += min(40, (int) floor(((float) $metrics['platform_customer_value_amount']) / 1000));
        $score += min(20, ((int) $metrics['verified_representation_count']) * 4);
        $score += min(10, ((int) $metrics['active_customer_count']) * 2);

        return max(0, min(100, $score));
    }

    /**
     * @return array{tier_key: string, tier_label: string}
     */
    private function tierForScore(int $score): array
    {
        $selected = ['tier_key' => 'starter', 'tier_label' => 'Starter', 'minimum_score' => 0];

        foreach ($this->policyService->loyaltyTierPolicy() as $tier) {
            if ($score >= (int) $tier['minimum_score'] && (int) $tier['minimum_score'] >= (int) $selected['minimum_score']) {
                $selected = $tier;
            }
        }

        return [
            'tier_key' => (string) $selected['tier_key'],
            'tier_label' => (string) $selected['tier_label'],
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00')->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    private function month(mixed $value): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d{4}-\d{2}$/', $value)) {
            throw new InvalidArgumentException('Suchak report month must use YYYY-MM format.');
        }

        try {
            Carbon::createFromFormat('Y-m-d', $value.'-01');
        } catch (\Throwable) {
            throw new InvalidArgumentException('Suchak report month must be a valid month.');
        }

        return $value;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function allowed(mixed $value, array $allowed, string $message): string
    {
        $value = trim((string) $value);
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private function campaignKey(mixed $value): string
    {
        $key = Str::slug(trim((string) $value), '_');
        if ($key === '' || mb_strlen($key) > 96) {
            throw new InvalidArgumentException('Suchak campaign key is required.');
        }

        return $key;
    }

    private function requiredText(mixed $value, string $message, int $limit): string
    {
        $text = trim((string) $value);
        if (mb_strlen($text) < 10) {
            throw new InvalidArgumentException($message);
        }

        return Str::limit($text, $limit, '');
    }

    private function money(mixed $value, string $message): string
    {
        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException($message);
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function nullableMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->money($value, 'Suchak retention offer amount is invalid.');
    }

    private function nullablePercent(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            throw new InvalidArgumentException('Suchak retention offer percent is invalid.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper(trim((string) $value));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Suchak currency must be a three-letter ISO code.');
        }

        return $currency;
    }

    private function nullableDateTime(mixed $value, string $message): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            throw new InvalidArgumentException($message);
        }
    }

    private function audit(User $admin, string $actionType, object $entity, string $reason): AdminAuditLog
    {
        return AuditLogService::log(
            $admin,
            $actionType,
            class_basename($entity),
            $entity->id,
            $reason,
            false,
        );
    }
}
