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
     * Daily profile opens (viewer) cap — same key as {@see SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT} / {@code user_feature_usages.feature_key}.
     */
    public const FEATURE_DAILY_PROFILE_VIEW_LIMIT = 'daily_profile_view_limit';

    /**
     * Who-viewed list access: plan stores window in days as {@see PlanFeatureKeys::WHO_VIEWED_ME_DAYS}
     * (1 / 7 / 999 = unlimited). This string is the public gate key; it does not add a second plan_features row.
     */
    public const FEATURE_WHO_VIEWED_ME_ACCESS = 'who_viewed_me_access';

    /** Values &gt;= this mean full history (matches who-viewed unlimited window). */
    public const WHO_VIEWED_UNLIMITED_DAYS_THRESHOLD = 999;

    /**
     * When true, {@see isAdminBypass}, usage limits do not apply for that user.
     */
    public function shouldBypassUsageLimits(User $user): bool
    {
        return $this->isAdminBypass($user);
    }

    /**
     * Real mode: admin is subject to limits. Bypass mode: is_admin skips limits (DB {@see SettingService} or env fallback).
     */
    private function adminBypassModeEnabled(): bool
    {
        $raw = app(SettingService::class)->get('admin_bypass_mode');
        if ($raw === null) {
            return (bool) config('app.admin_bypass_mode', false);
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    private function isAdminBypass(User $user): bool
    {
        return $user->is_admin === true && $this->adminBypassModeEnabled();
    }

    /**
     * Check if user can use a feature
     */
    public function canUse(int $userId, string $featureKey): bool
    {
        $user = User::query()->find($userId);
        if ($user && $this->isAdminBypass($user)) {
            return true;
        }

        if ($featureKey === self::FEATURE_CHAT_SEND_LIMIT) {
            return $this->canUseChatSendLimit($userId);
        }

        if ($featureKey === self::FEATURE_CHAT_CAN_READ) {
            return $this->canUseChatCanRead($userId);
        }

        if ($featureKey === self::FEATURE_CONTACT_VIEW_LIMIT) {
            return $this->canUseContactViewLimit($userId);
        }

        if ($featureKey === self::FEATURE_DAILY_PROFILE_VIEW_LIMIT) {
            return $this->canUseDailyProfileViewLimit($userId);
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
        $user = User::query()->find($userId);
        if ($user && $this->isAdminBypass($user)) {
            return;
        }

        if ($featureKey === self::FEATURE_CHAT_SEND_LIMIT) {
            $this->consumeChatSendLimit($userId);

            return;
        }

        if ($featureKey === self::FEATURE_CONTACT_VIEW_LIMIT) {
            $this->consumeContactViewLimit($userId);

            return;
        }

        if ($featureKey === self::FEATURE_DAILY_PROFILE_VIEW_LIMIT) {
            $this->consumeDailyProfileViewLimit($userId);

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
                        'period' => UserFeatureUsage::PERIOD_ENTITLEMENT,
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
        if (! $user) {
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
     * Viewer daily profile-browse cap: {@see SubscriptionService::getFeatureLimit}(..., {@see self::FEATURE_DAILY_PROFILE_VIEW_LIMIT}) vs {@link UserFeatureUsageService} daily bucket.
     */
    private function canUseDailyProfileViewLimit(int $userId): bool
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return false;
        }

        $limit = app(SubscriptionService::class)->getFeatureLimit($user, SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT);
        if ($limit === -1) {
            return true;
        }
        if ($limit === 0) {
            return false;
        }

        $used = app(UserFeatureUsageService::class)->getUsage(
            $userId,
            self::FEATURE_DAILY_PROFILE_VIEW_LIMIT,
            UserFeatureUsage::PERIOD_DAILY,
        );

        return $used < $limit;
    }

    private function consumeDailyProfileViewLimit(int $userId): void
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }

        app(UserFeatureUsageService::class)->incrementUsage(
            $userId,
            self::FEATURE_DAILY_PROFILE_VIEW_LIMIT,
            1,
            UserFeatureUsage::PERIOD_DAILY,
        );
    }

    /**
     * Contact views: same cap as {@see self::getPlanFeatureLimit}({@see PlanFeatureKeys::CONTACT_VIEW_LIMIT})
     * vs monthly {@see UserFeatureUsageKeys::CONTACT_VIEW_LIMIT} usage (SSOT with UI display).
     */
    private function canUseContactViewLimit(int $userId): bool
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return false;
        }

        $limit = app(SubscriptionService::class)->getFeatureLimit($user, PlanFeatureKeys::CONTACT_VIEW_LIMIT);
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

    private function consumeContactViewLimit(int $userId): void
    {
        $user = User::query()->find($userId);
        if (! $user) {
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
        if ($user && $this->isAdminBypass($user)) {
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

    // -------------------------------------------------------------------------
    // Public façade — delegates only (controllers / Blade entry point; SSOT API).
    // -------------------------------------------------------------------------

    /**
     * Effective plan limit for a feature key ({@see SubscriptionService::getFeatureLimit}).
     */
    public function getPlanFeatureLimit(User $user, string $planFeatureKey): int
    {
        return app(SubscriptionService::class)->getFeatureLimit($user, $planFeatureKey);
    }

    /**
     * Contact view quota for display — same inputs as {@see self::canUse}(..., {@see self::FEATURE_CONTACT_VIEW_LIMIT}).
     * CTA must use {@see self::canUse} only; this snapshot is display-only.
     *
     * @return array{used: int, limit: int|string, remaining: int|string|null, is_unlimited: bool}
     */
    public function getContactViewUsageSnapshot(User $user): array
    {
        $limit = $this->getPlanFeatureLimit($user, PlanFeatureKeys::CONTACT_VIEW_LIMIT);
        $used = $this->getUsageBucketCount(
            (int) $user->id,
            UserFeatureUsageKeys::CONTACT_VIEW_LIMIT,
            UserFeatureUsage::PERIOD_MONTHLY,
        );

        if ($limit === -1) {
            return [
                'used' => $used,
                'limit' => '∞',
                'remaining' => 'Unlimited',
                'is_unlimited' => true,
            ];
        }

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'is_unlimited' => false,
        ];
    }

    /**
     * Usage for a bucket ({@see UserFeatureUsageService::getUsage}).
     */
    public function getUsageBucketCount(int $userId, string $featureKey, string $period): int
    {
        return app(UserFeatureUsageService::class)->getUsage($userId, $featureKey, $period);
    }

    /**
     * Legacy profile-view gate ({@see SubscriptionService::assertWithinProfileViewLimit}) — uses {@code profile_views} counts.
     * Prefer {@see self::canUse}({@see self::FEATURE_DAILY_PROFILE_VIEW_LIMIT}) + {@see self::consume} for {@code user_feature_usages}.
     */
    public function assertProfileViewLimit(User $user): void
    {
        app(SubscriptionService::class)->assertWithinProfileViewLimit($user);
    }

    /**
     * Product gate aliases: contact_number, chat, interest, profile_views, chat_images, … ({@see SubscriptionService::hasFeature}).
     */
    public function subscriptionFeatureAllows(User $user, string $featureAlias): bool
    {
        return app(SubscriptionService::class)->hasFeature($user, $featureAlias);
    }

    /**
     * Chat image send allowed by plan ({@see SubscriptionService::canUseChatImages}).
     */
    public function subscriptionAllowsChatImages(User $user): bool
    {
        return app(SubscriptionService::class)->canUseChatImages($user);
    }

    /**
     * Member dashboard / layout: used vs plan limit per quota (display-only; gates use {@see canUse} / domain services).
     *
     * @return array{bypass: bool, rows: list<array{key: string, label: string, period: string, used: int, limit: int|null, remaining: int|null, locked: bool, is_unlimited: bool}>}|null
     */
    public function getDashboardUsageSummary(User $user): ?array
    {
        if (! $user->matrimonyProfile) {
            return null;
        }

        if ($this->shouldBypassUsageLimits($user)) {
            return [
                'bypass' => true,
                'rows' => [],
            ];
        }

        $subs = app(SubscriptionService::class);
        $usageSvc = app(UserFeatureUsageService::class);
        $interestSvc = app(InterestSendLimitService::class);

        $contact = $this->getContactViewUsageSnapshot($user);
        $contactLimit = $contact['is_unlimited'] ? -1 : (int) $contact['limit'];

        $rows = [
            $this->dashboardUsageRow(
                'contact_reveals',
                __('dashboard.usage_row_contact_reveals'),
                'monthly',
                (int) $contact['used'],
                $contactLimit,
            ),
            $this->dashboardUsageRow(
                'chat_sends',
                __('dashboard.usage_row_chat_sends'),
                'daily',
                $usageSvc->getUsage(
                    (int) $user->id,
                    self::FEATURE_CHAT_SEND_LIMIT,
                    UserFeatureUsage::PERIOD_DAILY,
                ),
                $subs->getFeatureLimit($user, SubscriptionService::FEATURE_CHAT_SEND_LIMIT),
            ),
            $this->dashboardUsageRow(
                'profile_opens',
                __('dashboard.usage_row_profile_opens'),
                'daily',
                $usageSvc->getUsage(
                    (int) $user->id,
                    self::FEATURE_DAILY_PROFILE_VIEW_LIMIT,
                    UserFeatureUsage::PERIOD_DAILY,
                ),
                $subs->getFeatureLimit($user, SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT),
            ),
            $this->dashboardUsageRow(
                'interest_sends',
                __('dashboard.usage_row_interest_sends'),
                'daily',
                $usageSvc->getUsage(
                    (int) $user->id,
                    UserFeatureUsageKeys::INTEREST_SEND_LIMIT,
                    UserFeatureUsage::PERIOD_DAILY,
                ),
                $interestSvc->effectiveDailyLimit($user),
            ),
            $this->dashboardUsageRow(
                'mediator_requests',
                __('dashboard.usage_row_mediator_requests'),
                'monthly',
                $usageSvc->getUsage(
                    (int) $user->id,
                    UserFeatureUsageKeys::MEDIATOR_REQUEST,
                    UserFeatureUsage::PERIOD_MONTHLY,
                ),
                $this->effectiveMediatorMonthlyLimitForDashboard($user, $subs),
            ),
        ];

        return [
            'bypass' => false,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{key: string, label: string, period: string, used: int, limit: int|null, remaining: int|null, locked: bool, is_unlimited: bool}
     */
    private function dashboardUsageRow(
        string $key,
        string $label,
        string $period,
        int $used,
        int $limit,
    ): array {
        $locked = $limit === 0;
        $unlimited = $limit === -1;

        return [
            'key' => $key,
            'label' => $label,
            'period' => $period,
            'used' => $used,
            'limit' => $unlimited ? null : $limit,
            'remaining' => $unlimited ? null : max(0, $limit - $used),
            'locked' => $locked,
            'is_unlimited' => $unlimited,
        ];
    }

    /**
     * Mediator quota for dashboard: same product rule as {@see ContactAccessService::resolveViewerContext}
     * when {@code has_contact_unlock} is false — contact reveal cap 0 ⇒ mediator shows as unavailable (0),
     * not the raw {@see PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH} on the plan row.
     */
    private function effectiveMediatorMonthlyLimitForDashboard(User $user, SubscriptionService $subs): int
    {
        $contactCap = $subs->getFeatureLimit($user, PlanFeatureKeys::CONTACT_VIEW_LIMIT);
        if ($contactCap === 0) {
            return 0;
        }

        return $this->parseNumericLimitFromEntitlement((int) $user->id, PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH);
    }

    /**
     * Same parsing as {@see ContactAccessService::parseNumericLimit} for mediator monthly cap.
     */
    private function parseNumericLimitFromEntitlement(int $userId, string $featureKey): int
    {
        $raw = app(EntitlementService::class)->getValue($userId, $featureKey, '0');
        $s = strtolower(trim((string) $raw));
        if ($s === '' || $s === '-1' || $s === 'unlimited') {
            return -1;
        }

        return max(0, (int) $raw);
    }
}
