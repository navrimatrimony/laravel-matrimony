<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserFeatureUsage;
use App\Support\PlanFeatureKeys;
use App\Support\UserFeatureUsageKeys;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Daily interest-send quota: {@see EntitlementService::getValue}(..., {@see PlanFeatureKeys::INTEREST_SEND_LIMIT})
 * vs {@see UserFeatureUsageService} bucket {@see UserFeatureUsageKeys::INTEREST_SEND_LIMIT} ({@see UserFeatureUsage::PERIOD_DAILY}).
 */
class InterestSendLimitService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly UserFeatureUsageService $usage,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * @throws HttpException when sending another interest today is not allowed
     */
    public function assertCanSend(User $user): void
    {
        if ($user->isAnyAdmin()) {
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
        if ($user->isAnyAdmin()) {
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
        if ($user->isAnyAdmin()) {
            return -1;
        }

        return $this->resolveLimit($user);
    }

    /**
     * @return int 0 = blocked, &gt;0 = max sends per calendar day (app tz), -1 = unlimited
     */
    private function resolveLimit(User $user): int
    {
        $uid = (int) $user->id;

        if ($this->entitlements->hasAccess($uid, PlanFeatureKeys::INTEREST_SEND_LIMIT)) {
            $raw = $this->entitlements->getValue($uid, PlanFeatureKeys::INTEREST_SEND_LIMIT, '5');

            return $this->parseLimitString((string) $raw);
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
}
