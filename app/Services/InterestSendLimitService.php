<?php

namespace App\Services;

use App\Models\Interest;
use App\Models\User;
use App\Models\UserFeatureUsage;
use App\Support\PlanFeatureKeys;
use App\Support\UserFeatureUsageKeys;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Interest engine (SSOT): daily **send** quota + plan **incoming view** reveal slots per reset window.
 *
 * Send limit: {@see SubscriptionService::getFeatureLimit}(..., {@see PlanFeatureKeys::INTEREST_SEND_LIMIT})
 * (includes {@code meta.carry_quota}) or {@see EntitlementService::getValueOverride} when set;
 * usage {@see UserFeatureUsageService} ({@see UserFeatureUsageKeys::INTEREST_SEND_LIMIT}, {@see UserFeatureUsage::PERIOD_DAILY}).
 *
 * Incoming view: {@see PlanFeatureKeys::INTEREST_VIEW_LIMIT} + {@see PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD}.
 * First N pending interests in the current window (by {@see Interest::scopeReceivedInboxOrder}) get full name/photo/profile;
 * overflow is blurred/locked on the interests hub (received tab) and blocked on direct profile open.
 */
class InterestSendLimitService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly UserFeatureUsageService $usage,
        private readonly SubscriptionService $subscriptions,
        private readonly FeatureUsageService $featureUsage,
    ) {}

    /**
     * @throws HttpException when sending another interest today is not allowed
     */
    public function assertCanSend(User $user): void
    {
        if ($this->featureUsage->shouldBypassUsageLimits($user)) {
            return;
        }

        $limit = $this->resolveLimit($user);
        if ($limit === 0) {
            throw new HttpException(403, __('subscriptions.interest_locked'));
        }
        if ($limit < 0) {
            return;
        }

        $used = $this->usage->getUsage(
            (int) $user->id,
            UserFeatureUsageKeys::INTEREST_SEND_LIMIT,
            UserFeatureUsage::PERIOD_DAILY,
        );

        if ($used >= $limit) {
            throw new HttpException(403, __('interest.daily_limit_reached'));
        }
    }

    /**
     * Call only after a new interest row was created (not duplicate pairs).
     */
    public function recordSuccessfulSend(User $user): void
    {
        if ($this->featureUsage->shouldBypassUsageLimits($user)) {
            return;
        }

        $this->usage->incrementUsage(
            (int) $user->id,
            UserFeatureUsageKeys::INTEREST_SEND_LIMIT,
            1,
            UserFeatureUsage::PERIOD_DAILY,
        );
    }

    /**
     * Resolved daily cap from entitlements/plan (admins: unlimited).
     *
     * @return int 0 = blocked, &gt;0 = max sends per calendar day (app tz), -1 = unlimited
     */
    public function effectiveDailyLimit(User $user): int
    {
        if ($this->featureUsage->shouldBypassUsageLimits($user)) {
            return -1;
        }

        return $this->resolveLimit($user);
    }

    /**
     * Like {@see effectiveDailyLimit} but uses {@see SubscriptionService::getQuotaLimitForUsageDisplay}
     * so staff accounts see the same numbers as the plan card on usage strips.
     */
    public function effectiveDailyLimitForUsageDisplay(User $user): int
    {
        if ($this->featureUsage->shouldBypassUsageLimits($user)) {
            return -1;
        }

        $uid = (int) $user->id;
        $override = $this->entitlements->getValueOverride($uid, PlanFeatureKeys::INTEREST_SEND_LIMIT);
        if ($override !== null) {
            return $this->parseLimitString($override);
        }

        return $this->subscriptions->getQuotaLimitForUsageDisplay($user, PlanFeatureKeys::INTEREST_SEND_LIMIT);
    }

    /**
     * @return int 0 = blocked, &gt;0 = max sends per calendar day (app tz), -1 = unlimited
     */
    private function resolveLimit(User $user): int
    {
        $uid = (int) $user->id;
        $override = $this->entitlements->getValueOverride($uid, PlanFeatureKeys::INTEREST_SEND_LIMIT);
        if ($override !== null) {
            return $this->parseLimitString($override);
        }

        return $this->subscriptions->getFeatureLimit($user, PlanFeatureKeys::INTEREST_SEND_LIMIT);
    }

    private function parseLimitString(string $raw): int
    {
        $s = strtolower(trim($raw));
        if ($s === '' || $s === 'unlimited') {
            return -1;
        }

        if ($s === '-1') {
            return -1;
        }

        return max(0, (int) $s);
    }

    // -------------------------------------------------------------------------
    // Incoming interest view (plan limit + reset window; positional unlock)
    // -------------------------------------------------------------------------

    /**
     * @param  Collection<int, Interest>  $interests  Typically received inbox, any status
     * @return array<int, bool> interest id => may see full name, photo, open profile
     */
    public function incomingInterestUnlockMap(User $receiver, Collection $interests): array
    {
        if ($interests->isEmpty()) {
            return [];
        }

        if ($this->featureUsage->shouldBypassUsageLimits($receiver)) {
            return $interests->mapWithKeys(fn (Interest $i) => [$i->id => true])->all();
        }

        $limit = $this->resolveInterestViewLimit($receiver);
        if ($limit < 0) {
            return $interests->mapWithKeys(fn (Interest $i) => [$i->id => true])->all();
        }

        $windowStart = $this->interestViewWindowStart($receiver);
        $receiverProfileId = (int) ($receiver->matrimonyProfile?->id ?? 0);
        if ($receiverProfileId === 0) {
            return $interests->mapWithKeys(fn (Interest $i) => [$i->id => false])->all();
        }

        $allowedIds = Interest::query()
            ->where('receiver_profile_id', $receiverProfileId)
            ->where('status', 'pending')
            ->where('created_at', '>=', $windowStart)
            ->receivedInboxOrder()
            ->limit(max(0, $limit))
            ->pluck('id')
            ->flip()
            ->all();

        $out = [];
        foreach ($interests as $i) {
            if ($i->status !== 'pending') {
                $out[(int) $i->id] = true;

                continue;
            }
            if ($limit === 0) {
                $out[(int) $i->id] = false;

                continue;
            }
            $out[(int) $i->id] = isset($allowedIds[(int) $i->id]);
        }

        return $out;
    }

    public function isIncomingInterestUnlocked(User $receiver, Interest $interest): bool
    {
        $map = $this->incomingInterestUnlockMap($receiver, collect([$interest]));

        return $map[(int) $interest->id] ?? false;
    }

    /**
     * Start of current weekly / monthly / quarterly window (app timezone).
     */
    public function interestViewWindowStart(User $receiver): Carbon
    {
        $period = $this->resolveInterestViewResetPeriod($receiver);

        return $this->windowStartForPeriod($period, Carbon::now());
    }

    /**
     * @return int 0 = none, &gt;0 = max pending reveals per window, -1 = unlimited
     */
    public function effectiveInterestViewLimit(User $receiver): int
    {
        if ($this->featureUsage->shouldBypassUsageLimits($receiver)) {
            return -1;
        }

        return $this->resolveInterestViewLimit($receiver);
    }

    /**
     * @return 'weekly'|'monthly'|'quarterly'
     */
    public function interestViewResetPeriodLabel(User $receiver): string
    {
        return $this->resolveInterestViewResetPeriod($receiver);
    }

    private function resolveInterestViewLimit(User $user): int
    {
        $uid = (int) $user->id;

        if ($this->entitlements->hasAccess($uid, PlanFeatureKeys::INTEREST_VIEW_LIMIT)) {
            $raw = $this->entitlements->getValue($uid, PlanFeatureKeys::INTEREST_VIEW_LIMIT, '3');

            return $this->parseLimitString((string) $raw);
        }

        return $this->subscriptions->getFeatureLimit($user, PlanFeatureKeys::INTEREST_VIEW_LIMIT);
    }

    /**
     * @return 'weekly'|'monthly'|'quarterly'
     */
    private function resolveInterestViewResetPeriod(User $user): string
    {
        $uid = (int) $user->id;
        $raw = strtolower(trim((string) $this->entitlements->getValue($uid, PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD, 'monthly')));
        if (in_array($raw, ['weekly', 'monthly', 'quarterly'], true)) {
            return $raw;
        }

        return 'monthly';
    }

    private function windowStartForPeriod(string $period, Carbon $at): Carbon
    {
        return match ($period) {
            'weekly' => $at->copy()->startOfWeek(Carbon::MONDAY)->startOfDay(),
            'quarterly' => $at->copy()->startOfQuarter(),
            'monthly' => $at->copy()->startOfMonth()->startOfDay(),
            default => $at->copy()->startOfMonth()->startOfDay(),
        };
    }
}
