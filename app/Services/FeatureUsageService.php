<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserFeatureUsage;
use App\Support\PlanFeatureKeys;
use App\Support\UserFeatureUsageKeys;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for feature gates (plan_features + entitlements + usage buckets).
 *
 * {@see SubscriptionService::FEATURE_CHAT_SEND_LIMIT}: daily cap via {@see UserFeatureUsageService}.
 */
class FeatureUsageService
{
    public const FEATURE_CHAT_SEND_LIMIT = 'chat_send_limit';

    public const FEATURE_CHAT_CAN_READ = 'chat_can_read';

    /**
     * Monthly contact reveal cap vs {@see UserFeatureUsageKeys::CONTACT_VIEW_LIMIT} / {@see PlanFeatureKeys::CONTACT_VIEW_LIMIT}.
     */
    public const FEATURE_CONTACT_VIEW_LIMIT = 'contact_view_limit';

    /**
     * Who-viewed list access: plan stores window in days as {@see PlanFeatureKeys::WHO_VIEWED_ME_DAYS}
     * (1 / 7 / 999 = unlimited). This string is the public gate key; it does not add a second plan_features row.
     */
    public const FEATURE_WHO_VIEWED_ME_ACCESS = 'who_viewed_me_access';

    /** Values &gt;= this mean full history (matches who-viewed unlimited window). */
    public const WHO_VIEWED_UNLIMITED_DAYS_THRESHOLD = 999;

    /**
     * Check if user can use a feature
     */
    public function canUse(int $userId, string $featureKey): bool
    {
        if ($featureKey === self::FEATURE_CHAT_SEND_LIMIT) {
            return $this->canUseChatSendLimit($userId);
        }

        if ($featureKey === self::FEATURE_CHAT_CAN_READ) {
            return $this->canUseChatCanRead($userId);
        }

        if ($featureKey === self::FEATURE_CONTACT_VIEW_LIMIT) {
            return $this->canUseContactViewLimit($userId);
        }

        if ($featureKey === self::FEATURE_WHO_VIEWED_ME_ACCESS) {
            return $this->canUseWhoViewedMeAccess($userId);
        }

        // 1. Check entitlement
        $entitlement = DB::table('user_entitlements')
            ->where('user_id', $userId)
            ->where('entitlement_key', $featureKey)
            ->whereNull('revoked_at')
            ->first();

        if (! $entitlement) {
            return false;
        }

        // 2. Check expiry
        if ($entitlement->valid_until && Carbon::parse($entitlement->valid_until)->isPast()) {
            return false;
        }

        // 3. Get active subscription
        $subscription = DB::table('subscriptions')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (! $subscription) {
            return false;
        }

        // 4. Get feature value
        $feature = DB::table('plan_features')
            ->where('plan_id', $subscription->plan_id)
            ->where('key', $featureKey)
            ->first();

        if (! $feature) {
            return false;
        }

        // LIMIT FEATURE
        $limit = (int) $feature->value;
        if ($limit < 0) {
            return true;
        }
        if ($limit === 0) {
            return false;
        }

        $now = now();

        $usage = DB::table('user_feature_usages')
            ->where('user_id', $userId)
            ->where('feature_key', $featureKey)
            ->where('period_start', '<=', $now)
            ->where('period_end', '>=', $now)
            ->first();

        $used = $usage ? $usage->used_count : 0;

        return $used < $limit;
    }

    /**
     * Consume usage
     */
    public function consume(int $userId, string $featureKey): void
    {
        if ($featureKey === self::FEATURE_CHAT_SEND_LIMIT) {
            $this->consumeChatSendLimit($userId);

            return;
        }

        if ($featureKey === self::FEATURE_CONTACT_VIEW_LIMIT) {
            $this->consumeContactViewLimit($userId);

            return;
        }

        if ($featureKey === self::FEATURE_WHO_VIEWED_ME_ACCESS) {
            return;
        }

        DB::transaction(function () use ($userId, $featureKey) {

            // get entitlement
            $entitlement = DB::table('user_entitlements')
                ->where('user_id', $userId)
                ->where('entitlement_key', $featureKey)
                ->whereNull('revoked_at')
                ->first();

            if (! $entitlement) {
                return;
            }

            $now = now();

            $usage = DB::table('user_feature_usages')
                ->where('user_id', $userId)
                ->where('feature_key', $featureKey)
                ->where('period_start', '<=', $now)
                ->where('period_end', '>=', $now)
                ->lockForUpdate()
                ->first();

            if ($usage) {
                DB::table('user_feature_usages')
                    ->where('id', $usage->id)
                    ->update([
                        'used_count' => $usage->used_count + 1,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('user_feature_usages')
                    ->insert([
                        'user_id' => $userId,
                        'feature_key' => $featureKey,
                        'used_count' => 1,
                        'period_start' => $now,
                        'period_end' => $entitlement->valid_until,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    /**
     * Incoming chat body/image read access: plan_features.chat_can_read + entitlement when the plan defines the gate;
     * legacy plans without that row allow reading.
     */
    private function canUseChatCanRead(int $userId): bool
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return false;
        }
        if ($user->isAnyAdmin()) {
            return true;
        }

        $plan = app(SubscriptionService::class)->getActivePlan($user);
        $plan->loadMissing('features');
        $planDefinesReadGate = $plan->features->contains(
            fn ($f) => (string) $f->key === PlanFeatureKeys::CHAT_CAN_READ
        );
        if (! $planDefinesReadGate) {
            return true;
        }

        return app(EntitlementService::class)->hasFeature($userId, PlanFeatureKeys::CHAT_CAN_READ);
    }

    private function canUseChatSendLimit(int $userId): bool
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return false;
        }
        if ($user->isAnyAdmin()) {
            return true;
        }

        $limit = app(SubscriptionService::class)->getFeatureLimit($user, SubscriptionService::FEATURE_CHAT_SEND_LIMIT);
        if ($limit === -1) {
            return true;
        }
        if ($limit === 0) {
            return false;
        }

        $used = app(UserFeatureUsageService::class)->getUsage(
            $userId,
            self::FEATURE_CHAT_SEND_LIMIT,
            UserFeatureUsage::PERIOD_DAILY,
        );

        return $used < $limit;
    }

    private function consumeChatSendLimit(int $userId): void
    {
        $user = User::query()->find($userId);
        if (! $user || $user->isAnyAdmin()) {
            return;
        }

        app(UserFeatureUsageService::class)->incrementUsage(
            $userId,
            self::FEATURE_CHAT_SEND_LIMIT,
            1,
            UserFeatureUsage::PERIOD_DAILY,
        );
    }

    /**
     * Contact views: {@see PlanFeatureKeys::CONTACT_UNLOCK} + monthly {@see UserFeatureUsageKeys::CONTACT_VIEW_LIMIT} vs entitlement limit.
     */
    private function canUseContactViewLimit(int $userId): bool
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return false;
        }
        if ($user->isAnyAdmin()) {
            return true;
        }

        $entitlements = app(EntitlementService::class);
        if (! $entitlements->hasFeature($userId, PlanFeatureKeys::CONTACT_UNLOCK)) {
            return false;
        }

        $limit = $this->parseContactViewNumericLimit($userId);
        if ($limit === -1) {
            return true;
        }
        if ($limit === 0) {
            return false;
        }

        $used = app(UserFeatureUsageService::class)->getUsage(
            $userId,
            UserFeatureUsageKeys::CONTACT_VIEW_LIMIT,
            UserFeatureUsage::PERIOD_MONTHLY,
        );

        return $used < $limit;
    }

    /**
     * -1 = unlimited, 0 = none, n = cap (aligned with {@see ContactAccessService}).
     */
    private function parseContactViewNumericLimit(int $userId): int
    {
        $raw = app(EntitlementService::class)->getValue($userId, PlanFeatureKeys::CONTACT_VIEW_LIMIT, '0');
        $s = strtolower(trim((string) $raw));
        if ($s === '' || $s === '-1' || $s === 'unlimited') {
            return -1;
        }

        return max(0, (int) $raw);
    }

    private function consumeContactViewLimit(int $userId): void
    {
        $user = User::query()->find($userId);
        if (! $user || $user->isAnyAdmin()) {
            return;
        }

        app(UserFeatureUsageService::class)->incrementUsage(
            $userId,
            UserFeatureUsageKeys::CONTACT_VIEW_LIMIT,
            1,
            UserFeatureUsage::PERIOD_MONTHLY,
        );
    }

    /**
     * Window length from entitlements ({@see PlanFeatureKeys::WHO_VIEWED_ME_DAYS}): 0 = none, 1 / 7 / 999 (unlimited).
     */
    public function getWhoViewedMeWindowDays(int $userId): int
    {
        $user = User::query()->find($userId);
        if ($user && $user->isAnyAdmin()) {
            return self::WHO_VIEWED_UNLIMITED_DAYS_THRESHOLD;
        }

        $raw = app(EntitlementService::class)->getValue($userId, PlanFeatureKeys::WHO_VIEWED_ME_DAYS, '0');
        if (! is_numeric($raw)) {
            return 0;
        }

        return max(0, (int) $raw);
    }

    private function canUseWhoViewedMeAccess(int $userId): bool
    {
        return $this->getWhoViewedMeWindowDays($userId) > 0;
    }
}
