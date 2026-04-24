<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use App\Models\UserFeatureUsage;
use App\Support\PlanFeatureKeys;
use App\Support\UserFeatureUsageKeys;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * SSOT read-path for member quota summaries, subscription access window (paid / grace), and feature gates.
 * Numeric caps remain computed in {@see SubscriptionService}; usage buckets in {@see UserFeatureUsageService}.
 */
class QuotaEngineService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly UserFeatureUsageService $usage,
        private readonly InterestSendLimitService $interestLimits,
    ) {}

    /**
     * @return array{
     *   bypass: bool,
     *   rows: list<array{
     *     key: string,
     *     feature_key: string,
     *     label: string,
     *     period: string,
     *     used: int,
     *     limit: int|null,
     *     total_allocated: int|null,
     *     remaining: int|null,
     *     locked: bool,
     *     is_unlimited: bool,
     *     source_breakdown: array{plan: int|null, carry: int}
     *   }>,
     *   subscription_status: 'active'|'grace'|'expired',
     *   is_within_plan: bool,
     *   is_within_grace: bool,
     *   expires_at: string|null,
     *   grace_ends_at: string|null,
     *   expires_at_display: string|null,
     *   grace_ends_at_display: string|null,
     *   subscription_state_label: string,
     *   plan_name: string,
     *   subscription_started_at: string|null,
     *   subscription_started_at_display: string|null,
     *   subscription_row_status: string|null,
     *   carry_forward_items: list<array{feature_key: string, label: string, carry: int}>
     * }|null
     */
    public function getUserQuotaSummary(User $user): ?array
    {
        if (! $user->matrimonyProfile) {
            return null;
        }

        $featureUsage = app(FeatureUsageService::class);
        $lifecycle = $this->subscriptionFieldsForQuotaSummary($user);
        $planContext = $this->planContextForQuotaSummary($user);
        if ($featureUsage->shouldBypassUsageLimits($user)) {
            return array_merge([
                'bypass' => true,
                'rows' => [],
                'carry_forward_items' => [],
            ], $lifecycle, $planContext);
        }

        $contactLimitDisplay = $this->subscriptions->getQuotaLimitForUsageDisplay($user, PlanFeatureKeys::CONTACT_VIEW_LIMIT);
        $contactUsed = $this->usage->getUsage(
            (int) $user->id,
            UserFeatureUsageKeys::CONTACT_VIEW_LIMIT,
            UserFeatureUsage::PERIOD_MONTHLY,
        );

        $allRows = [
            $this->usageRow(
                $user,
                'contact_reveals',
                PlanFeatureKeys::CONTACT_VIEW_LIMIT,
                __('dashboard.usage_row_contact_reveals'),
                'monthly',
                $contactUsed,
                $contactLimitDisplay,
            ),
            $this->usageRow(
                $user,
                'chat_sends',
                SubscriptionService::FEATURE_CHAT_SEND_LIMIT,
                __('dashboard.usage_row_chat_sends'),
                'daily',
                $this->usage->getUsage(
                    (int) $user->id,
                    FeatureUsageService::FEATURE_CHAT_SEND_LIMIT,
                    UserFeatureUsage::PERIOD_DAILY,
                ),
                $this->subscriptions->getQuotaLimitForUsageDisplay($user, SubscriptionService::FEATURE_CHAT_SEND_LIMIT),
            ),
            $this->usageRow(
                $user,
                'profile_opens',
                SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT,
                __('dashboard.usage_row_profile_opens'),
                'daily',
                $this->usage->getUsage(
                    (int) $user->id,
                    FeatureUsageService::FEATURE_DAILY_PROFILE_VIEW_LIMIT,
                    UserFeatureUsage::PERIOD_DAILY,
                ),
                $this->subscriptions->getQuotaLimitForUsageDisplay($user, SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT),
            ),
            $this->usageRow(
                $user,
                'interest_sends',
                PlanFeatureKeys::INTEREST_SEND_LIMIT,
                __('dashboard.usage_row_interest_sends'),
                'daily',
                $this->usage->getUsage(
                    (int) $user->id,
                    UserFeatureUsageKeys::INTEREST_SEND_LIMIT,
                    UserFeatureUsage::PERIOD_DAILY,
                ),
                $this->interestLimits->effectiveDailyLimitForUsageDisplay($user),
            ),
            $this->usageRow(
                $user,
                'mediator_requests',
                PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH,
                __('dashboard.usage_row_mediator_requests'),
                'monthly',
                $this->usage->getUsage(
                    (int) $user->id,
                    UserFeatureUsageKeys::MEDIATOR_REQUEST,
                    UserFeatureUsage::PERIOD_MONTHLY,
                ),
                $this->mediatorMonthlyDisplayLimit($user),
            ),
        ];

        $carryForwardItems = $this->carryForwardItemsFromRows($allRows);

        $rows = array_values(array_filter($allRows, function (array $r): bool {
            if ($r['is_unlimited'] ?? false) {
                return true;
            }
            if (($r['key'] ?? '') === 'mediator_requests') {
                return true;
            }

            return ! ($r['locked'] ?? false);
        }));

        return array_merge([
            'bypass' => false,
            'rows' => $rows,
            'carry_forward_items' => $carryForwardItems,
        ], $lifecycle, $planContext);
    }

    /**
     * Subscription access window derived from the same query rules as {@see Subscription::scopeEffectivelyActiveForAccessAt}
     * and {@see SubscriptionService::getActiveSubscription} (plan grace from {@see PlanSubscriptionTerms::gracePeriodDays}).
     *
     * @return array{
     *   status: 'active'|'grace'|'expired',
     *   is_within_plan: bool,
     *   is_within_grace: bool,
     *   expires_at: string|null,
     *   grace_ends_at: string|null,
     *   subscription_state_label: string
     * }
     */
    public function getSubscriptionState(User $user, ?CarbonInterface $at = null): array
    {
        $moment = $at ?? now();
        $snap = $this->resolveSubscriptionAccessSnapshot($user, $moment);

        return [
            'status' => $snap['status'],
            'is_within_plan' => $snap['is_within_plan'],
            'is_within_grace' => $snap['is_within_grace'],
            'expires_at' => $snap['endsAt']?->toIso8601String(),
            'grace_ends_at' => $snap['graceEndsAt']?->toIso8601String(),
            'subscription_state_label' => $this->formatSubscriptionStateLabel($snap),
        ];
    }

    /**
     * Product gate: delegates to {@see FeatureUsageService::getFeatureState} / {@see self::canUseFeature}
     * so grace and free-plan rules stay identical to pre-centralization behavior.
     */
    public function canAccessFeature(User $user, string $featureKey, array $context = []): bool
    {
        $featureUsage = app(FeatureUsageService::class);
        if ($featureUsage->shouldBypassUsageLimits($user)) {
            return true;
        }

        $raw = strtolower(trim($featureKey));
        if (in_array($raw, ['chat', 'chat_send', 'chat_send_limit'], true)) {
            return $this->canUseFeature($user, 'chat', $context);
        }

        $normalized = $featureUsage->normalizeFeatureKey($featureKey);

        return $featureUsage->getFeatureState($user, $normalized)['allowed'];
    }

    /**
     * Compose immutable quota snapshot + carry preview for subscription meta (caller persists).
     *
     * @return array{quota_policies: array<string, array<string, mixed>>, carry_quota: array<string, int>}
     */
    public function applyPlan(User $user, Plan $plan, ?CarbonInterface $at = null): array
    {
        $checkout = PlanQuotaCheckoutSnapshot::forPlan($plan);
        $moment = $at ?? now();

        return [
            'quota_policies' => $checkout['quota_policies'],
            'carry_quota' => $this->subscriptions->resolveCarryQuotaSnapshotForCheckout($user, $moment),
        ];
    }

    /**
     * Product-level gate (e.g. {@code chat} → send limit + existing-thread continuation rules).
     */
    public function canUseFeature(User $user, string $feature, array $context = []): bool
    {
        $featureUsage = app(FeatureUsageService::class);
        $uid = (int) $user->id;

        return match (strtolower(trim($feature))) {
            'chat', 'chat_send', 'chat_send_limit' => $featureUsage->canUse($uid, FeatureUsageService::FEATURE_CHAT_SEND_LIMIT)
                || (
                    isset($context['conversation_id'], $context['sender_profile_id'])
                    && $featureUsage->canSendChatInExistingConversation(
                        $uid,
                        (int) $context['conversation_id'],
                        (int) $context['sender_profile_id'],
                    )
                ),
            default => $featureUsage->canUse($uid, $feature),
        };
    }

    /**
     * @return array{
     *   subscription_status: 'active'|'grace'|'expired',
     *   is_within_plan: bool,
     *   is_within_grace: bool,
     *   expires_at: string|null,
     *   grace_ends_at: string|null,
     *   subscription_state_label: string
     * }
     */
    private function subscriptionFieldsForQuotaSummary(User $user): array
    {
        $st = $this->getSubscriptionState($user);

        return [
            'subscription_status' => $st['status'],
            'is_within_plan' => $st['is_within_plan'],
            'is_within_grace' => $st['is_within_grace'],
            'expires_at' => $st['expires_at'],
            'grace_ends_at' => $st['grace_ends_at'],
            'expires_at_display' => $this->formatIsoStringForDisplay($st['expires_at']),
            'grace_ends_at_display' => $this->formatIsoStringForDisplay($st['grace_ends_at']),
            'subscription_state_label' => $st['subscription_state_label'],
        ];
    }

    /**
     * Read-only plan row context for member UI (no new quota rules).
     *
     * @return array{
     *   plan_name: string,
     *   subscription_started_at: string|null,
     *   subscription_started_at_display: string|null,
     *   subscription_row_status: string|null
     * }
     */
    private function planContextForQuotaSummary(User $user): array
    {
        $sub = $this->subscriptions->getActiveSubscription($user);
        if ($sub === null) {
            $plan = $this->subscriptions->getEffectivePlan($user);

            return [
                'plan_name' => (string) ($plan->name ?? ''),
                'subscription_started_at' => null,
                'subscription_started_at_display' => null,
                'subscription_row_status' => null,
            ];
        }

        $sub->loadMissing('plan');
        $started = $sub->starts_at;

        return [
            'plan_name' => (string) ($sub->plan?->name ?? ''),
            'subscription_started_at' => $started?->toIso8601String(),
            'subscription_started_at_display' => $this->formatCarbonForUserDisplay($started),
            'subscription_row_status' => (string) $sub->status,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{feature_key: string, label: string, carry: int}>
     */
    private function carryForwardItemsFromRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $carry = (int) (($row['source_breakdown'] ?? [])['carry'] ?? 0);
            if ($carry > 0 && isset($row['feature_key'], $row['label'])) {
                $out[] = [
                    'feature_key' => (string) $row['feature_key'],
                    'label' => (string) $row['label'],
                    'carry' => $carry,
                ];
            }
        }

        return $out;
    }

    private function formatCarbonForUserDisplay(?CarbonInterface $dt): ?string
    {
        if ($dt === null) {
            return null;
        }
        $tz = (string) config('app.timezone', 'UTC');

        return $dt->copy()->timezone($tz)->translatedFormat('j M Y, H:i');
    }

    private function formatIsoStringForDisplay(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return $this->formatCarbonForUserDisplay(Carbon::parse($iso));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *   status: 'active'|'grace'|'expired',
     *   is_within_plan: bool,
     *   is_within_grace: bool,
     *   endsAt: Carbon|null,
     *   graceEndsAt: Carbon|null
     * }
     */
    private function resolveSubscriptionAccessSnapshot(User $user, CarbonInterface $moment): array
    {
        $sub = $this->subscriptions->getActiveSubscription($user, $moment);
        if ($sub === null) {
            return [
                'status' => 'expired',
                'is_within_plan' => false,
                'is_within_grace' => false,
                'endsAt' => null,
                'graceEndsAt' => null,
            ];
        }

        $sub->loadMissing('plan');
        $plan = $sub->plan;
        $graceDays = $plan !== null ? PlanSubscriptionTerms::gracePeriodDays($plan) : 0;
        $endsAt = $sub->ends_at;
        $graceEndsAt = null;
        if ($endsAt !== null) {
            $graceEndsAt = $endsAt->copy()->addDays($graceDays);
        }

        $isWithinPlan = $endsAt === null || $endsAt->greaterThan($moment);
        $isWithinGrace = $endsAt !== null
            && $endsAt->lessThanOrEqualTo($moment)
            && $graceEndsAt !== null
            && $graceEndsAt->greaterThan($moment);

        $status = $isWithinPlan ? 'active' : ($isWithinGrace ? 'grace' : 'expired');

        return [
            'status' => $status,
            'is_within_plan' => $isWithinPlan,
            'is_within_grace' => $isWithinGrace,
            'endsAt' => $endsAt,
            'graceEndsAt' => $graceEndsAt,
        ];
    }

    /**
     * @param  array{
     *   status: 'active'|'grace'|'expired',
     *   is_within_plan: bool,
     *   is_within_grace: bool,
     *   endsAt: Carbon|null,
     *   graceEndsAt: Carbon|null
     * }  $snap
     */
    private function formatSubscriptionStateLabel(array $snap): string
    {
        $tz = (string) config('app.timezone', 'UTC');
        $fmt = static function (?Carbon $dt) use ($tz): string {
            if ($dt === null) {
                return '';
            }

            return $dt->copy()->timezone($tz)->translatedFormat('j M Y, H:i');
        };

        return match ($snap['status']) {
            'expired' => __('dashboard.subscription_state_expired'),
            'grace' => __('dashboard.subscription_state_grace', [
                'grace_ends' => $fmt($snap['graceEndsAt']),
            ]),
            'active' => $snap['endsAt'] === null
                ? __('dashboard.subscription_state_active_open')
                : __('dashboard.subscription_state_active_until', [
                    'expires' => $fmt($snap['endsAt']),
                ]),
            default => '',
        };
    }

    private function mediatorMonthlyDisplayLimit(User $user): int
    {
        $contactCap = $this->subscriptions->getQuotaLimitForUsageDisplay($user, PlanFeatureKeys::CONTACT_VIEW_LIMIT);
        if ($contactCap === 0) {
            return 0;
        }

        return $this->subscriptions->getQuotaLimitForUsageDisplay($user, PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH);
    }

    /**
     * @return array{
     *   key: string,
     *   feature_key: string,
     *   label: string,
     *   period: string,
     *   used: int,
     *   limit: int|null,
     *   total_allocated: int|null,
     *   remaining: int|null,
     *   locked: bool,
     *   is_unlimited: bool,
     *   source_breakdown: array{plan: int|null, carry: int}
     * }
     */
    private function usageRow(
        User $user,
        string $rowKey,
        string $policyFeatureKey,
        string $label,
        string $period,
        int $used,
        int $limit,
    ): array {
        $locked = $limit === 0;
        $unlimited = $limit !== null && $limit < 0;
        $carry = $this->subscriptions->getQuotaCarryBonus($user, $policyFeatureKey);
        $planBase = null;
        if (! $unlimited && $limit > 0) {
            $planBase = max(0, $limit - $carry);
        } elseif ($unlimited) {
            $planBase = -1;
        } else {
            $planBase = 0;
        }

        return [
            'key' => $rowKey,
            'feature_key' => $policyFeatureKey,
            'label' => $label,
            'period' => $period,
            'used' => $used,
            'limit' => $unlimited ? null : $limit,
            'total_allocated' => $unlimited ? null : $limit,
            'remaining' => $unlimited ? null : max(0, $limit - $used),
            'locked' => $locked,
            'is_unlimited' => $unlimited,
            'source_breakdown' => [
                'plan' => $planBase,
                'carry' => $carry,
            ],
        ];
    }
}
