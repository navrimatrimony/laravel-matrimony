<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use App\Models\UserFeatureUsage;
use App\Support\PlanFeatureKeys;
use App\Support\UserFeatureUsageKeys;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

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
     * SSOT allowlist for normalized feature keys: config-driven.
     *
     * @return list<string>
     */
    public function getAllowedFeatureKeys(): array
    {
        $keys = array_keys((array) config('plan_features', []));

        return array_values(array_unique(array_map(
            fn ($k) => strtolower(trim((string) $k)),
            $keys
        )));
    }

    /**
     * Quota unlimited: any negative int (e.g. -1, -2). Zero is NOT unlimited.
     */
    private function isUnlimitedLimit(?int $limit): bool
    {
        return $limit !== null && $limit < 0;
    }

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
     * Canonical plan / usage keys (aliases → one key; no duplicate semantics).
     */
    public function normalizeFeatureKey(string $key): string
    {
        $k = strtolower(trim($key));

        $normalized = match ($k) {
            'chat_send', 'daily_chat_send_limit' => PlanFeatureKeys::CHAT_SEND_LIMIT,
            'contact_view', 'contact_number_access', 'contact_unlock' => PlanFeatureKeys::CONTACT_VIEW_LIMIT,
            'profile_view', 'daily_profile_view', 'profile_view_limit' => self::FEATURE_DAILY_PROFILE_VIEW_LIMIT,
            'interest_send', 'monthly_interest_send_limit' => PlanFeatureKeys::INTEREST_SEND_LIMIT,
            'who_viewed_me' => self::FEATURE_WHO_VIEWED_ME_ACCESS,
            'mediator_request', 'mediator_requests' => PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH,
            default => $k,
        };

        $this->assertCanonicalFeatureKeyAllowed($normalized, $key);

        return $normalized;
    }

    /**
     * @param  string  $originalKey  Raw input (for logging).
     */
    private function assertCanonicalFeatureKeyAllowed(string $normalized, string $originalKey): void
    {
        if (in_array($normalized, $this->getAllowedFeatureKeys(), true)) {
            return;
        }

        $message = 'Unknown or invalid feature key after normalization.';
        $context = [
            'normalized' => $normalized,
            'original' => $originalKey,
        ];

        if (config('app.strict_feature_keys', true)) {
            Log::warning($message, $context);

            throw new InvalidArgumentException("Invalid feature key: {$normalized}");
        }

        Log::warning($message, $context);
    }

    /**
     * Full gate snapshot for monetization (SSOT). Limits come from {@see SubscriptionService::getFeatureLimit}
     * and related product rules; usage from {@code user_feature_usages} where applicable.
     *
     * @return array{
     *   allowed: bool,
     *   limit: int|null,
     *   used: int,
     *   remaining: int|null,
     *   unlimited: bool,
     *   reset_at: CarbonInterface|null,
     *   reason: string|null
     * }
     */
    public function getFeatureState(User $user, string $featureKey): array
    {
        if ($this->isAdminBypass($user)) {
            return [
                'allowed' => true,
                'limit' => null,
                'used' => 0,
                'remaining' => null,
                'unlimited' => true,
                'reset_at' => null,
                'reason' => null,
            ];
        }

        $normalized = $this->normalizeFeatureKey($featureKey);

        if ($this->subscriptionMetaFeatureExpired($user, $normalized)) {
            Log::warning('Feature gate: subscription meta feature_expiry is in the past.', [
                'user_id' => $user->id,
                'feature_key' => $normalized,
            ]);

            return [
                'allowed' => false,
                'limit' => null,
                'used' => 0,
                'remaining' => null,
                'unlimited' => false,
                'reset_at' => null,
                'reason' => 'feature_expired',
            ];
        }

        $state = match ($normalized) {
            PlanFeatureKeys::CHAT_SEND_LIMIT => $this->stateForChatSendLimit($user),
            self::FEATURE_CHAT_CAN_READ => $this->stateForChatCanRead($user),
            PlanFeatureKeys::CONTACT_VIEW_LIMIT => $this->stateForContactViewLimit($user),
            self::FEATURE_DAILY_PROFILE_VIEW_LIMIT => $this->stateForDailyProfileViewLimit($user),
            self::FEATURE_WHO_VIEWED_ME_ACCESS => $this->stateForWhoViewedMeAccess($user),
            PlanFeatureKeys::INTEREST_SEND_LIMIT => $this->stateForInterestSendLimit($user),
            PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => $this->stateForMediatorRequestsMonthly($user),
            default => $this->stateForGenericEntitlementPlanFeature($user, $normalized),
        };

        $state = $this->applyConfigFallbackDefaults($normalized, $state);

        return $this->applySoftLimitWarning($state);
    }

    /**
     * If a key is known in {@see config('plan_features')} but state could not resolve a numeric limit,
     * apply safe defaults (no access / 0) without changing boolean gate semantics.
     */
    private function applyConfigFallbackDefaults(string $normalizedKey, array $state): array
    {
        $defs = (array) config('plan_features', []);
        $def = $defs[$normalizedKey] ?? null;
        if (! is_array($def)) {
            return $state;
        }

        if (($state['reason'] ?? null) === 'feature_expired') {
            return $state;
        }

        $type = (string) ($def['type'] ?? '');
        if (! in_array($type, ['limit', 'days'], true)) {
            return $state;
        }

        if (($state['limit'] ?? null) !== null || ($state['unlimited'] ?? false)) {
            return $state;
        }

        return [
            ...$state,
            'allowed' => false,
            'limit' => 0,
            'remaining' => 0,
            'unlimited' => false,
            'reason' => 'limit_exhausted',
        ];
    }

    /**
     * Admin / support only: plan, raw sources, usage rows, and {@see getFeatureState} snapshot.
     * Do not call from production request paths.
     *
     * @return array<string, mixed>
     */
    public function debugFeatureState(User $user, string $featureKey): array
    {
        $normalized = null;
        $normalizeError = null;
        try {
            $normalized = $this->normalizeFeatureKey($featureKey);
        } catch (InvalidArgumentException $e) {
            $normalizeError = $e->getMessage();
        }

        $subscription = Subscription::query()
            ->where('user_id', $user->id)
            ->effectivelyActiveForAccess()
            ->latest('id')
            ->with(['plan', 'planTerm'])
            ->first();

        $planFeatureRow = null;
        $rawValue = null;
        if ($subscription !== null && $normalized !== null) {
            $planFeatureRow = DB::table('plan_features')
                ->where('plan_id', $subscription->plan_id)
                ->where('key', $normalized)
                ->first();
            $rawValue = $planFeatureRow?->value ?? null;
        }

        $entitlementRow = null;
        if ($normalized !== null) {
            $entitlementRow = DB::table('user_entitlements')
                ->where('user_id', $user->id)
                ->where('entitlement_key', $normalized)
                ->whereNull('revoked_at')
                ->first();
        }

        $usageRowsSample = [];
        if ($normalized !== null) {
            $usageRowsSample = UserFeatureUsage::query()
                ->where('user_id', $user->id)
                ->where('feature_key', $normalized)
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(fn (UserFeatureUsage $r) => $r->toArray())
                ->all();
        }

        $computedState = null;
        if ($normalized !== null) {
            $computedState = $this->getFeatureState($user, $featureKey);
        } else {
            $computedState = ['error' => $normalizeError];
        }

        $typedValue = null;
        if ($subscription?->plan && $normalized !== null) {
            $typedValue = $subscription->plan->getTypedFeatureValue($normalized);
        }

        $source = 'fallback';
        if ($planFeatureRow !== null) {
            $source = 'plan_feature';
        } elseif ($entitlementRow !== null) {
            $source = 'entitlement';
        }

        return [
            'input_feature_key' => $featureKey,
            'normalized_key' => $normalized,
            'normalize_error' => $normalizeError,
            'plan_name' => $subscription?->plan?->name,
            'plan_slug' => $subscription?->plan?->slug,
            'plan_term' => $subscription?->planTerm ? [
                'id' => $subscription->planTerm->id,
                'billing_key' => $subscription->planTerm->billing_key,
                'duration_days' => $subscription->planTerm->duration_days,
            ] : null,
            'limit_sources' => [
                'plan_features_row' => $planFeatureRow,
                'user_entitlement_row' => $entitlementRow,
                'source' => $source,
                'raw_value' => $rawValue,
                'typed_value' => $typedValue,
            ],
            'usage_rows_sample' => $usageRowsSample,
            'computed_state' => $computedState,
        ];
    }

    /**
     * True when active subscription {@code meta.feature_expiry[normalizedKey]} is in the past.
     * Invalid meta / date formats fail closed (ignore expiry — no block).
     */
    private function subscriptionMetaFeatureExpired(User $user, string $normalizedKey): bool
    {
        try {
            $sub = Subscription::query()
                ->where('user_id', $user->id)
                ->effectivelyActiveForAccess()
                ->latest('id')
                ->first();

            if (! $sub) {
                return false;
            }

            $meta = $sub->meta;
            if (! is_array($meta)) {
                return false;
            }

            $expiryMap = $meta['feature_expiry'] ?? null;
            if (! is_array($expiryMap)) {
                return false;
            }

            $raw = $expiryMap[$normalizedKey] ?? null;
            if ($raw === null || $raw === '') {
                return false;
            }

            $expiresAt = Carbon::parse($raw);

            return Carbon::now()->gt($expiresAt);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array{
     *   allowed: bool,
     *   limit: int|null,
     *   used: int,
     *   remaining: int|null,
     *   unlimited: bool,
     *   reset_at: CarbonInterface|null,
     *   reason: string|null
     * }  $state
     * @return array{
     *   allowed: bool,
     *   limit: int|null,
     *   used: int,
     *   remaining: int|null,
     *   unlimited: bool,
     *   reset_at: CarbonInterface|null,
     *   reason: string|null
     * }
     */
    private function applySoftLimitWarning(array $state): array
    {
        $reason = $state['reason'] ?? null;
        if ($reason === 'feature_expired' || $reason === 'limit_exhausted') {
            return $state;
        }

        $limit = $state['limit'];
        if ($limit === null || $limit <= 0 || ($state['unlimited'] ?? false)) {
            return $state;
        }

        $used = (int) ($state['used'] ?? 0);
        if ($limit > 0) {
            $ratio = $used / $limit;
            if ($ratio >= 0.8) {
                $state['reason'] = 'soft_limit_warning';
            }
        }

        return $state;
    }

    /**
     * @return array{
     *   allowed: bool,
     *   limit: int|null,
     *   used: int,
     *   remaining: int|null,
     *   unlimited: bool,
     *   reset_at: CarbonInterface|null,
     *   reason: string|null
     * }
     */
    private function buildQuotaState(int $limit, int $used, ?CarbonInterface $resetAt = null): array
    {
        if ($this->isUnlimitedLimit($limit)) {
            return [
                'allowed' => true,
                'limit' => null,
                'used' => $used,
                'remaining' => null,
                'unlimited' => true,
                'reset_at' => null,
                'reason' => null,
            ];
        }

        if ($limit === 0) {
            return [
                'allowed' => false,
                'limit' => 0,
                'used' => $used,
                'remaining' => 0,
                'unlimited' => false,
                'reset_at' => $resetAt,
                'reason' => 'limit_exhausted',
            ];
        }

        $allowed = $used < $limit;

        return [
            'allowed' => $allowed,
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'unlimited' => false,
            'reset_at' => $resetAt,
            'reason' => null,
        ];
    }

    private function resetAtForUsagePeriod(string $period): ?CarbonInterface
    {
        $now = Carbon::now();

        return match ($period) {
            UserFeatureUsage::PERIOD_DAILY => $now->copy()->endOfDay(),
            UserFeatureUsage::PERIOD_MONTHLY => $now->copy()->endOfMonth()->endOfDay(),
            default => null,
        };
    }

    private function stateForChatSendLimit(User $user): array
    {
        $subs = app(SubscriptionService::class);
        $usageSvc = app(UserFeatureUsageService::class);
        $limit = $subs->getFeatureLimit($user, SubscriptionService::FEATURE_CHAT_SEND_LIMIT);
        $used = $usageSvc->getUsage(
            (int) $user->id,
            self::FEATURE_CHAT_SEND_LIMIT,
            UserFeatureUsage::PERIOD_DAILY,
        );

        return $this->buildQuotaState($limit, $used, $this->resetAtForUsagePeriod(UserFeatureUsage::PERIOD_DAILY));
    }

    private function stateForChatCanRead(User $user): array
    {
        $allowed = $this->canUseChatCanRead((int) $user->id);

        return [
            'allowed' => $allowed,
            'limit' => null,
            'used' => 0,
            'remaining' => null,
            'unlimited' => false,
            'reset_at' => null,
            'reason' => null,
        ];
    }

    private function stateForContactViewLimit(User $user): array
    {
        $subs = app(SubscriptionService::class);
        $usageSvc = app(UserFeatureUsageService::class);
        $limit = $subs->getFeatureLimit($user, PlanFeatureKeys::CONTACT_VIEW_LIMIT);
        $used = $usageSvc->getUsage(
            (int) $user->id,
            UserFeatureUsageKeys::CONTACT_VIEW_LIMIT,
            UserFeatureUsage::PERIOD_MONTHLY,
        );

        return $this->buildQuotaState($limit, $used, $this->resetAtForUsagePeriod(UserFeatureUsage::PERIOD_MONTHLY));
    }

    private function stateForDailyProfileViewLimit(User $user): array
    {
        $subs = app(SubscriptionService::class);
        $usageSvc = app(UserFeatureUsageService::class);
        $limit = $subs->getFeatureLimit($user, SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT);
        $used = $usageSvc->getUsage(
            (int) $user->id,
            self::FEATURE_DAILY_PROFILE_VIEW_LIMIT,
            UserFeatureUsage::PERIOD_DAILY,
        );

        return $this->buildQuotaState($limit, $used, $this->resetAtForUsagePeriod(UserFeatureUsage::PERIOD_DAILY));
    }

    /**
     * Matches {@see InterestSendLimitService} (entitlement override + daily bucket).
     */
    private function stateForInterestSendLimit(User $user): array
    {
        $limit = app(InterestSendLimitService::class)->effectiveDailyLimit($user);
        $used = app(UserFeatureUsageService::class)->getUsage(
            (int) $user->id,
            UserFeatureUsageKeys::INTEREST_SEND_LIMIT,
            UserFeatureUsage::PERIOD_DAILY,
        );

        return $this->buildQuotaState($limit, $used, $this->resetAtForUsagePeriod(UserFeatureUsage::PERIOD_DAILY));
    }

    /**
     * Same effective cap as dashboard / {@see ContactAccessService} when contact reveal is blocked.
     */
    private function effectiveMediatorMonthlyLimitForUser(User $user): int
    {
        $subs = app(SubscriptionService::class);
        $contactCap = $subs->getFeatureLimit($user, PlanFeatureKeys::CONTACT_VIEW_LIMIT);
        if ($contactCap === 0) {
            return 0;
        }

        return $this->parseNumericLimitFromEntitlement((int) $user->id, PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH);
    }

    private function stateForMediatorRequestsMonthly(User $user): array
    {
        $limit = $this->effectiveMediatorMonthlyLimitForUser($user);
        $used = app(UserFeatureUsageService::class)->getUsage(
            (int) $user->id,
            UserFeatureUsageKeys::MEDIATOR_REQUEST,
            UserFeatureUsage::PERIOD_MONTHLY,
        );

        return $this->buildQuotaState($limit, $used, $this->resetAtForUsagePeriod(UserFeatureUsage::PERIOD_MONTHLY));
    }

    private function stateForWhoViewedMeAccess(User $user): array
    {
        $days = $this->getWhoViewedMeWindowDays((int) $user->id);
        $allowed = $days > 0;

        return [
            'allowed' => $allowed,
            'limit' => null,
            'used' => 0,
            'remaining' => null,
            'unlimited' => $days >= self::WHO_VIEWED_UNLIMITED_DAYS_THRESHOLD,
            'reset_at' => null,
            'reason' => null,
        ];
    }

    /**
     * Legacy entitlement + plan_features + overlapping usage window (unchanged product rules).
     */
    private function stateForGenericEntitlementPlanFeature(User $user, string $featureKey): array
    {
        $userId = (int) $user->id;

        $entitlement = DB::table('user_entitlements')
            ->where('user_id', $userId)
            ->where('entitlement_key', $featureKey)
            ->whereNull('revoked_at')
            ->first();

        if (! $entitlement) {
            return [
                'allowed' => false,
                'limit' => null,
                'used' => 0,
                'remaining' => null,
                'unlimited' => false,
                'reset_at' => null,
                'reason' => null,
            ];
        }

        if ($entitlement->valid_until && Carbon::parse($entitlement->valid_until)->isPast()) {
            return [
                'allowed' => false,
                'limit' => null,
                'used' => 0,
                'remaining' => null,
                'unlimited' => false,
                'reset_at' => null,
                'reason' => null,
            ];
        }

        $subscription = DB::table('subscriptions')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (! $subscription) {
            return [
                'allowed' => false,
                'limit' => null,
                'used' => 0,
                'remaining' => null,
                'unlimited' => false,
                'reset_at' => null,
                'reason' => null,
            ];
        }

        $feature = DB::table('plan_features')
            ->where('plan_id', $subscription->plan_id)
            ->where('key', $featureKey)
            ->first();

        if (! $feature) {
            return [
                'allowed' => false,
                'limit' => null,
                'used' => 0,
                'remaining' => null,
                'unlimited' => false,
                'reset_at' => null,
                'reason' => null,
            ];
        }

        $limit = (int) $feature->value;
        $now = now();

        $usage = DB::table('user_feature_usages')
            ->where('user_id', $userId)
            ->where('feature_key', $featureKey)
            ->where('period_start', '<=', $now)
            ->where('period_end', '>=', $now)
            ->first();

        $used = $usage ? (int) $usage->used_count : 0;

        if ($this->isUnlimitedLimit($limit)) {
            return [
                'allowed' => true,
                'limit' => null,
                'used' => $used,
                'remaining' => null,
                'unlimited' => true,
                'reset_at' => null,
                'reason' => null,
            ];
        }

        if ($limit === 0) {
            return [
                'allowed' => false,
                'limit' => 0,
                'used' => $used,
                'remaining' => 0,
                'unlimited' => false,
                'reset_at' => null,
                'reason' => 'limit_exhausted',
            ];
        }

        return [
            'allowed' => $used < $limit,
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'unlimited' => false,
            'reset_at' => null,
            'reason' => null,
        ];
    }

    /**
     * Check if user can use a feature
     */
    public function canUse(int $userId, string $featureKey): bool
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return false;
        }

        return $this->getFeatureState($user, $featureKey)['allowed'];
    }

    /**
     * Consume usage. Returns whether the action is allowed (no increment when unlimited / no-op gates).
     */
    public function consume(int $userId, string $featureKey): bool
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return false;
        }

        if ($this->isAdminBypass($user)) {
            return true;
        }

        $normalized = $this->normalizeFeatureKey($featureKey);
        $state = $this->getFeatureState($user, $featureKey);

        if (! $state['allowed']) {
            $ppa = $this->payPerActionQuotePaiseIfEligible($normalized, $state);
            if ($ppa === null) {
                return false;
            }

            return DB::transaction(function () use ($user, $userId, $normalized, $ppa) {
                if (! app(UserWalletService::class)->debit((int) $user->id, $ppa, 'ppa:'.$normalized)) {
                    return false;
                }

                match ($normalized) {
                    PlanFeatureKeys::CHAT_SEND_LIMIT => $this->consumeChatSendLimit($userId),
                    PlanFeatureKeys::CONTACT_VIEW_LIMIT => $this->consumeContactViewLimit($userId),
                    self::FEATURE_DAILY_PROFILE_VIEW_LIMIT => $this->consumeDailyProfileViewLimit($userId),
                    PlanFeatureKeys::INTEREST_SEND_LIMIT => $this->consumeInterestSendLimit($userId),
                    PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => $this->consumeMediatorMonthly($userId),
                    default => $this->consumeGenericEntitlementPlanFeature($userId, $normalized),
                };

                return true;
            });
        }

        if ($state['unlimited']) {
            return true;
        }

        if ($normalized === self::FEATURE_WHO_VIEWED_ME_ACCESS || $normalized === self::FEATURE_CHAT_CAN_READ) {
            return true;
        }

        return DB::transaction(function () use ($userId, $normalized) {
            match ($normalized) {
                PlanFeatureKeys::CHAT_SEND_LIMIT => $this->consumeChatSendLimit($userId),
                PlanFeatureKeys::CONTACT_VIEW_LIMIT => $this->consumeContactViewLimit($userId),
                self::FEATURE_DAILY_PROFILE_VIEW_LIMIT => $this->consumeDailyProfileViewLimit($userId),
                PlanFeatureKeys::INTEREST_SEND_LIMIT => $this->consumeInterestSendLimit($userId),
                PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => $this->consumeMediatorMonthly($userId),
                default => $this->consumeGenericEntitlementPlanFeature($userId, $normalized),
            };

            return true;
        });
    }

    /**
     * When quota is exhausted and PPA is configured, return price in paise; otherwise null.
     *
     * @param  array<string, mixed>  $state  {@see getFeatureState}
     */
    private function payPerActionQuotePaiseIfEligible(string $normalized, array $state): ?int
    {
        if (! config('pay_per_action.enabled', false)) {
            return null;
        }

        $cfg = config('pay_per_action.actions.'.$normalized);
        if (! is_array($cfg) || empty($cfg['enabled'])) {
            return null;
        }

        $pricePaise = (int) ($cfg['price_paise'] ?? 0);
        if ($pricePaise <= 0) {
            return null;
        }

        if (($state['unlimited'] ?? false) === true) {
            return null;
        }

        $limit = $state['limit'] ?? null;
        if ($limit === null || $limit <= 0) {
            return null;
        }

        if ((int) ($state['used'] ?? 0) < (int) $limit) {
            return null;
        }

        return $pricePaise;
    }

    private function consumeInterestSendLimit(int $userId): void
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }

        app(UserFeatureUsageService::class)->incrementUsage(
            $userId,
            UserFeatureUsageKeys::INTEREST_SEND_LIMIT,
            1,
            UserFeatureUsage::PERIOD_DAILY,
        );
    }

    private function consumeMediatorMonthly(int $userId): void
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }

        $limit = $this->effectiveMediatorMonthlyLimitForUser($user);
        if ($this->isUnlimitedLimit($limit) || $limit === 0) {
            return;
        }

        app(UserFeatureUsageService::class)->incrementUsage(
            $userId,
            UserFeatureUsageKeys::MEDIATOR_REQUEST,
            1,
            UserFeatureUsage::PERIOD_MONTHLY,
        );
    }

    private function consumeGenericEntitlementPlanFeature(int $userId, string $featureKey): void
    {
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

        if ($this->isUnlimitedLimit($limit)) {
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
                $this->effectiveMediatorMonthlyLimitForUser($user),
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
        $unlimited = $this->isUnlimitedLimit($limit);

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
