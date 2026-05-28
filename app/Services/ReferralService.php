<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\ReferralRewardLedger;
use App\Models\ReferralRewardRule;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Models\UserReferral;
use App\Support\PlanFeatureLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ReferralService
{
    public const SESSION_REGISTRATION_WELCOME = 'referred_registration_welcome';

    private const ENGINE_KEYS = [
        'enabled' => 'referral_engine_enabled',
        'paid_only' => 'referral_engine_paid_plans_only',
        'min_plan_amount' => 'referral_engine_min_plan_amount',
        'monthly_cap' => 'referral_engine_monthly_cap_per_referrer',
        'fraud_auto_hold' => 'referral_fraud_auto_hold',
        'fraud_rapid_invites' => 'referral_fraud_rapid_invites_per_day',
        'pending_claim_expiry_days' => 'referral_pending_claim_expiry_days',
        'quality_require_profile_active' => 'referral_quality_require_profile_active',
        'quality_require_mobile_verified' => 'referral_quality_require_mobile_verified',
        'quality_require_photo_approved' => 'referral_quality_require_photo_approved',
        'quality_cooling_period_days' => 'referral_quality_cooling_period_days',
        'referred_checkout_enabled' => 'referral_referred_checkout_enabled',
        'referred_checkout_percent' => 'referral_referred_checkout_percent',
        'referred_checkout_extra_days' => 'referral_referred_checkout_extra_days',
        'renewal_micro_bonus_enabled' => 'referral_renewal_micro_bonus_enabled',
        'renewal_micro_bonus_days' => 'referral_renewal_micro_bonus_days',
    ];

    public const QUALITY_PROFILE_NOT_ACTIVE = 'profile_not_active';

    public const QUALITY_MOBILE_NOT_VERIFIED = 'mobile_not_verified';

    public const QUALITY_PHOTO_NOT_APPROVED = 'photo_not_approved';

    public const QUALITY_COOLING_PERIOD = 'cooling_period';

    public const FRAUD_SAME_MOBILE = 'same_mobile';

    public const FRAUD_CIRCULAR = 'circular_referral';

    /** Ledger rows that record bonus days / quotas actually granted to the referrer. */
    private const APPLIED_REWARD_LEDGER_ACTIONS = [
        'auto_applied',
        'auto_claimed',
        'admin_partial_applied',
        'admin_force_pending_claim',
    ];

    public const FRAUD_LINKED_DUPLICATE_MOBILE = 'linked_duplicate_mobile';

    public const FRAUD_RAPID_INVITES = 'rapid_invites';

    public const FRAUD_SAME_REGISTRATION_IP = 'same_registration_ip';

    /**
     * Record referral at registration when {@code referral_code} matches another user's code.
     */
    public function recordReferralIfEligible(User $newUser, ?string $rawCode, ?string $registrationIp = null, ?array $attribution = null): bool
    {
        if (! $this->isEngineEnabled()) {
            return false;
        }

        $code = strtoupper(trim((string) $rawCode));
        if ($code === '') {
            return false;
        }

        $referrer = User::query()->where('referral_code', $code)->where('id', '!=', $newUser->id)->first();
        if (! $referrer || $referrer->isReferralCodeDisabled()) {
            return false;
        }

        try {
            $existing = UserReferral::query()->where('referred_user_id', $newUser->id)->first();
            if ($existing) {
                return false;
            }

            $fraudFlags = $this->assessFraudFlags($referrer, $newUser, $registrationIp);
            $reviewStatus = $this->resolveInitialReviewStatus($fraudFlags);

            $utm = $this->normalizeRegistrationAttribution($attribution);

            UserReferral::query()->create([
                'referrer_id' => $referrer->id,
                'referred_user_id' => $newUser->id,
                'reward_applied' => false,
                'review_status' => $reviewStatus,
                'fraud_flags' => $fraudFlags !== [] ? $fraudFlags : null,
                'registration_ip' => $this->normalizeRegistrationIp($registrationIp),
                'utm_source' => $utm['utm_source'],
                'utm_medium' => $utm['utm_medium'],
                'utm_campaign' => $utm['utm_campaign'],
                'utm_content' => $utm['utm_content'],
            ]);

            if ($reviewStatus === UserReferral::REVIEW_APPROVED) {
                $newUser->loadMissing('matrimonyProfile');
                $displayName = $this->privacySafeReferredName($newUser, $newUser->matrimonyProfile);
                try {
                    app(NotificationService::class)->notifyReferralInviteRegistered($referrer, $displayName);
                } catch (\Throwable $e) {
                    Log::warning('Referral invite registered notification failed', ['error' => $e->getMessage()]);
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Referral record failed', ['error' => $e->getMessage(), 'user_id' => $newUser->id]);

            return false;
        }
    }

    /**
     * One-time post-registration banner (session); cleared when the member dismisses it.
     */
    public function stashRegistrationWelcomeSession(User $buyer): void
    {
        if (! $this->isEngineEnabled()) {
            return;
        }

        $cfg = $this->referredCheckoutConfig();
        if (! $cfg['enabled'] || ($cfg['percent_off'] <= 0 && $cfg['extra_days'] <= 0)) {
            return;
        }

        if (! UserReferral::query()->where('referred_user_id', $buyer->id)->exists()) {
            return;
        }

        session()->put(self::SESSION_REGISTRATION_WELCOME, [
            'user_id' => (int) $buyer->id,
            'shown_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array{
     *     percent_off: int,
     *     extra_days: int,
     *     plans_url: string,
     *     dismiss_url: string,
     *     referrer_display_name: string|null
     * }|null
     */
    public function registrationWelcomeBanner(User $buyer): ?array
    {
        if (! session()->has(self::SESSION_REGISTRATION_WELCOME)) {
            return null;
        }

        $payload = session(self::SESSION_REGISTRATION_WELCOME);
        if (! is_array($payload) || (int) ($payload['user_id'] ?? 0) !== (int) $buyer->id) {
            session()->forget(self::SESSION_REGISTRATION_WELCOME);

            return null;
        }

        if (! UserReferral::query()->where('referred_user_id', $buyer->id)->exists()) {
            session()->forget(self::SESSION_REGISTRATION_WELCOME);

            return null;
        }

        $cfg = $this->referredCheckoutConfig();
        if (! $cfg['enabled'] || ($cfg['percent_off'] <= 0 && $cfg['extra_days'] <= 0)) {
            return null;
        }

        return [
            'percent_off' => $cfg['percent_off'],
            'extra_days' => $cfg['extra_days'],
            'plans_url' => route('plans.index'),
            'dismiss_url' => route('referrals.welcome.dismiss'),
            'referrer_display_name' => $this->referrerDisplayNameForReferredUser($buyer),
        ];
    }

    public function dismissRegistrationWelcome(): void
    {
        session()->forget(self::SESSION_REGISTRATION_WELCOME);
    }

    /**
     * Invite link + WhatsApp share payload for member UI.
     *
     * @return array{
     *     share_url: string,
     *     referral_code: string,
     *     whatsapp_url: string,
     *     message: string
     * }|null
     */
    public function shareToolsForReferrer(User $referrer): ?array
    {
        if (! $this->isEngineEnabled()) {
            return null;
        }

        $code = strtoupper(trim((string) $referrer->referral_code));
        if ($code === '') {
            return null;
        }

        $shareUrl = $this->referralRegisterUrl($code, 'link');
        $whatsappShareUrl = $this->referralRegisterUrl($code, 'whatsapp');
        $message = $this->buildWhatsappShareMessage($whatsappShareUrl, $code);

        return [
            'share_url' => $shareUrl,
            'referral_code' => $code,
            'whatsapp_url' => 'https://api.whatsapp.com/send?'.http_build_query(['text' => $message]),
            'message' => $message,
        ];
    }

    /**
     * Register URL with ref + UTM tags for growth attribution.
     */
    public function referralRegisterUrl(string $code, string $channel = 'link'): string
    {
        $code = strtoupper(trim($code));
        $utm = $this->defaultUtmParams($channel);

        return url(route('register', array_merge(['ref' => $code], $utm), false));
    }

    /**
     * @return array{monthly_cap: int, paid_plans_only: bool}
     */
    public function memberRulesContext(): array
    {
        $referredCheckout = $this->referredCheckoutConfig();

        return [
            'monthly_cap' => $this->getIntSetting(self::ENGINE_KEYS['monthly_cap'], 0),
            'paid_plans_only' => $this->getBoolSetting(self::ENGINE_KEYS['paid_only'], true),
            'referred_checkout_enabled' => $referredCheckout['enabled'],
            'referred_checkout_percent' => $referredCheckout['percent_off'],
            'referred_checkout_extra_days' => $referredCheckout['extra_days'],
            'fraud_auto_hold' => $this->fraudAutoHoldEnabled(),
            'pending_claim_expiry_days' => $this->pendingClaimExpiryDays(),
            'quality_gates_enabled' => $this->anyQualityGateEnabled(),
            'quality_require_profile_active' => $this->qualityGateRequireProfileActive(),
            'quality_require_mobile_verified' => $this->qualityGateRequireMobileVerified(),
            'quality_require_photo_approved' => $this->qualityGateRequirePhotoApproved(),
            'quality_cooling_period_days' => $this->qualityGateCoolingPeriodDays(),
            'renewal_micro_bonus_enabled' => $this->renewalMicroBonusEnabled(),
            'renewal_micro_bonus_days' => $this->renewalMicroBonusDays(),
        ];
    }

    /**
     * @return list<string>
     */
    public function assessReferralQualityGates(User $referred): array
    {
        if (! $this->anyQualityGateEnabled()) {
            return [];
        }

        $flags = [];
        $referred->loadMissing('matrimonyProfile');
        $profile = $referred->matrimonyProfile;
        $referralRow = UserReferral::query()->where('referred_user_id', $referred->id)->first();

        if ($this->qualityGateRequireProfileActive()) {
            if (! $profile
                || ($profile->lifecycle_state ?? '') !== 'active'
                || ($profile->is_suspended ?? false)) {
                $flags[] = self::QUALITY_PROFILE_NOT_ACTIVE;
            }
        }

        if ($this->qualityGateRequireMobileVerified() && $referred->mobile_verified_at === null) {
            $flags[] = self::QUALITY_MOBILE_NOT_VERIFIED;
        }

        if ($this->qualityGateRequirePhotoApproved()) {
            if (! $profile || ! ($profile->photo_approved ?? false)) {
                $flags[] = self::QUALITY_PHOTO_NOT_APPROVED;
            }
        }

        $coolingDays = $this->qualityGateCoolingPeriodDays();
        if ($coolingDays > 0 && $referralRow?->created_at !== null) {
            if ($referralRow->created_at->copy()->addDays($coolingDays)->isFuture()) {
                $flags[] = self::QUALITY_COOLING_PERIOD;
            }
        }

        return array_values(array_unique($flags));
    }

    public function referredBuyerMeetsQualityGates(User $referred): bool
    {
        return $this->assessReferralQualityGates($referred) === [];
    }

    /**
     * Retry referrer reward when a referred buyer was held for quality gates and now qualifies.
     */
    public function retryQualityPendingReferralReward(User $buyer): void
    {
        if (! $this->isEngineEnabled() || ! $this->referredBuyerMeetsQualityGates($buyer)) {
            return;
        }

        $row = UserReferral::query()
            ->where('referred_user_id', $buyer->id)
            ->where('reward_status', UserReferral::STATUS_QUALITY_PENDING)
            ->where('reward_applied', false)
            ->first();

        if (! $row || ! $row->isReferrerRewardEligible()) {
            return;
        }

        $plan = $row->pending_plan_id ? Plan::query()->find($row->pending_plan_id) : null;
        if (! $plan) {
            return;
        }

        $stored = is_array($row->pending_reward) ? $row->pending_reward : [];
        $bonusDays = (int) ($stored['bonus_days'] ?? 0);
        /** @var array<string, int> $featureBonus */
        $featureBonus = (array) ($stored['feature_bonus'] ?? []);
        if ($bonusDays <= 0 && $featureBonus === []) {
            $resolved = $this->resolveRewardForPlan($plan);
            $bonusDays = (int) ($resolved['bonus_days'] ?? 0);
            $featureBonus = (array) ($resolved['feature_bonus'] ?? []);
        }

        if ($bonusDays <= 0 && $featureBonus === []) {
            return;
        }

        DB::transaction(function () use ($row, $buyer, $plan, $bonusDays, $featureBonus) {
            $locked = UserReferral::query()->whereKey($row->id)->lockForUpdate()->first();
            if (! $locked
                || $locked->reward_applied
                || $locked->reward_status !== UserReferral::STATUS_QUALITY_PENDING
                || ! $locked->isReferrerRewardEligible()) {
                return;
            }

            $referrer = User::query()->find($locked->referrer_id);
            if (! $referrer) {
                return;
            }

            $this->processReferralReward(
                $locked,
                $referrer,
                $buyer,
                $plan,
                $bonusDays,
                $featureBonus,
                'quality_gates_passed',
                'Referrer reward released after referred buyer quality gates passed',
            );
        });
    }

    /**
     * Expire pending_claim rows older than the configured window (0 = disabled).
     */
    public function expireStalePendingClaims(?User $referrer = null): int
    {
        $expiryDays = $this->pendingClaimExpiryDays();
        if ($expiryDays <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($expiryDays);
        $query = UserReferral::query()
            ->where('reward_status', UserReferral::STATUS_PENDING_CLAIM)
            ->where('reward_applied', false)
            ->where(function ($q) use ($cutoff) {
                $q->where('pending_claim_at', '<=', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff) {
                        $inner->whereNull('pending_claim_at')
                            ->where('updated_at', '<=', $cutoff);
                    });
            });

        if ($referrer) {
            $query->where('referrer_id', $referrer->id);
        }

        $expired = 0;
        foreach ($query->get() as $row) {
            $this->expirePendingClaimRow($row, 'Pending claim expired after '.$expiryDays.' days');
            $expired++;
        }

        return $expired;
    }

    public function countPendingReviewReferrals(): int
    {
        return UserReferral::query()
            ->where('review_status', UserReferral::REVIEW_PENDING)
            ->count();
    }

    /**
     * @return list<string>
     */
    public function assessFraudFlags(User $referrer, User $referred, ?string $registrationIp = null): array
    {
        $flags = [];
        $referrerMobile = $this->normalizeMobileForCompare($referrer->mobile);
        $referredMobile = $this->normalizeMobileForCompare($referred->mobile);

        if ($referrerMobile !== '' && $referredMobile !== '' && $referrerMobile === $referredMobile) {
            $flags[] = self::FRAUD_SAME_MOBILE;
        }

        if ((int) ($referred->mobile_duplicate_of_user_id ?? 0) === (int) $referrer->id
            || (int) ($referrer->mobile_duplicate_of_user_id ?? 0) === (int) $referred->id) {
            $flags[] = self::FRAUD_LINKED_DUPLICATE_MOBILE;
        }

        if (UserReferral::query()
            ->where('referrer_id', $referred->id)
            ->where('referred_user_id', $referrer->id)
            ->exists()) {
            $flags[] = self::FRAUD_CIRCULAR;
        }

        $rapidCap = $this->fraudRapidInvitesPerDay();
        if ($rapidCap > 0) {
            $recentCount = UserReferral::query()
                ->where('referrer_id', $referrer->id)
                ->where('created_at', '>=', now()->subDay())
                ->count();
            if ($recentCount >= $rapidCap) {
                $flags[] = self::FRAUD_RAPID_INVITES;
            }
        }

        $ip = $this->normalizeRegistrationIp($registrationIp);
        if ($ip !== '') {
            $lookbackDays = max(1, (int) config('referral.fraud.same_ip_lookback_days', 30));
            $sameIpExists = UserReferral::query()
                ->where('referrer_id', $referrer->id)
                ->where('registration_ip', $ip)
                ->where('created_at', '>=', now()->subDays($lookbackDays))
                ->exists();
            if ($sameIpExists) {
                $flags[] = self::FRAUD_SAME_REGISTRATION_IP;
            }
        }

        return array_values(array_unique($flags));
    }

    public function adminApproveReferralReview(UserReferral $row, User $admin, ?string $notes = null): void
    {
        DB::transaction(function () use ($row, $admin, $notes): void {
            $locked = UserReferral::query()->whereKey($row->id)->lockForUpdate()->first();
            if (! $locked || $locked->review_status !== UserReferral::REVIEW_PENDING) {
                return;
            }

            $locked->forceFill([
                'review_status' => UserReferral::REVIEW_APPROVED,
                'reviewed_at' => now(),
                'reviewed_by_admin_id' => $admin->id,
                'fraud_notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : $locked->fraud_notes,
            ])->save();

            $referrer = User::query()->find($locked->referrer_id);
            $buyer = User::query()->find($locked->referred_user_id);
            if ($referrer && $buyer) {
                $buyer->loadMissing('matrimonyProfile');
                $displayName = $this->privacySafeReferredName($buyer, $buyer->matrimonyProfile);
                try {
                    app(NotificationService::class)->notifyReferralInviteRegistered($referrer, $displayName);
                } catch (\Throwable $e) {
                    Log::warning('Referral invite registered notification failed', ['error' => $e->getMessage()]);
                }
            }

            $this->writeLedger([
                'user_referral_id' => $locked->id,
                'referrer_id' => $locked->referrer_id,
                'referred_user_id' => $locked->referred_user_id,
                'action_type' => 'admin_review_approved',
                'bonus_days' => 0,
                'feature_bonus' => null,
                'reason' => 'Admin approved referral after fraud review',
                'meta' => [
                    'admin_id' => $admin->id,
                    'fraud_flags' => $locked->fraud_flags,
                ],
            ]);

            if ($buyer) {
                $paidSub = Subscription::query()
                    ->where('user_id', $buyer->id)
                    ->with('plan')
                    ->orderByDesc('id')
                    ->get()
                    ->first(fn (Subscription $sub) => $sub->plan && ! Plan::isFreeCatalogSlug((string) $sub->plan->slug));
                if ($paidSub?->plan) {
                    $this->applyPurchaseRewardIfEligible($buyer, $paidSub->plan);
                }
            }
        });
    }

    public function adminRejectReferralReview(UserReferral $row, User $admin, string $reason): void
    {
        DB::transaction(function () use ($row, $admin, $reason): void {
            $locked = UserReferral::query()->whereKey($row->id)->lockForUpdate()->first();
            if (! $locked || $locked->review_status !== UserReferral::REVIEW_PENDING) {
                return;
            }

            $locked->forceFill([
                'review_status' => UserReferral::REVIEW_REJECTED,
                'reviewed_at' => now(),
                'reviewed_by_admin_id' => $admin->id,
                'fraud_notes' => trim($reason),
            ])->save();

            $this->writeLedger([
                'user_referral_id' => $locked->id,
                'referrer_id' => $locked->referrer_id,
                'referred_user_id' => $locked->referred_user_id,
                'action_type' => 'admin_review_rejected',
                'bonus_days' => 0,
                'feature_bonus' => null,
                'reason' => trim($reason),
                'meta' => [
                    'admin_id' => $admin->id,
                    'fraud_flags' => $locked->fraud_flags,
                ],
            ]);
        });
    }

    public function adminFreezeReferrerRewards(User $referrer, User $admin, ?string $reason = null): void
    {
        $referrer->forceFill(['referral_rewards_frozen_at' => now()])->save();

        $this->writeLedger([
            'user_referral_id' => null,
            'referrer_id' => $referrer->id,
            'referred_user_id' => null,
            'action_type' => 'admin_referrer_frozen',
            'bonus_days' => 0,
            'feature_bonus' => null,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : 'Admin froze referrer rewards',
            'meta' => ['admin_id' => $admin->id],
        ]);
    }

    public function adminUnfreezeReferrerRewards(User $referrer, User $admin, ?string $reason = null): void
    {
        $referrer->forceFill(['referral_rewards_frozen_at' => null])->save();

        $this->writeLedger([
            'user_referral_id' => null,
            'referrer_id' => $referrer->id,
            'referred_user_id' => null,
            'action_type' => 'admin_referrer_unfrozen',
            'bonus_days' => 0,
            'feature_bonus' => null,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : 'Admin unfroze referrer rewards',
            'meta' => ['admin_id' => $admin->id],
        ]);
    }

    public function adminDisableReferralCode(User $referrer, User $admin, ?string $reason = null): void
    {
        $referrer->forceFill(['referral_code_disabled_at' => now()])->save();

        $this->writeLedger([
            'user_referral_id' => null,
            'referrer_id' => $referrer->id,
            'referred_user_id' => null,
            'action_type' => 'admin_code_disabled',
            'bonus_days' => 0,
            'feature_bonus' => null,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : 'Admin disabled referral code',
            'meta' => ['admin_id' => $admin->id],
        ]);
    }

    public function adminEnableReferralCode(User $referrer, User $admin, ?string $reason = null): void
    {
        $referrer->forceFill(['referral_code_disabled_at' => null])->save();

        $this->writeLedger([
            'user_referral_id' => null,
            'referrer_id' => $referrer->id,
            'referred_user_id' => null,
            'action_type' => 'admin_code_enabled',
            'bonus_days' => 0,
            'feature_bonus' => null,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : 'Admin enabled referral code',
            'meta' => ['admin_id' => $admin->id],
        ]);
    }

    public function adminRegenerateReferralCode(User $referrer, User $admin, ?string $reason = null): string
    {
        $code = User::generateUniqueReferralCode();
        $referrer->forceFill(['referral_code' => $code])->save();

        $this->writeLedger([
            'user_referral_id' => null,
            'referrer_id' => $referrer->id,
            'referred_user_id' => null,
            'action_type' => 'admin_code_regenerated',
            'bonus_days' => 0,
            'feature_bonus' => null,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : 'Admin regenerated referral code',
            'meta' => [
                'admin_id' => $admin->id,
                'new_code' => $code,
            ],
        ]);

        return $code;
    }

    /**
     * @param  int|null  $cap  null = use global engine cap; 0 = unlimited for this referrer; >0 = custom monthly cap
     */
    public function adminSetReferrerMonthlyCapOverride(User $referrer, User $admin, ?int $cap, ?string $reason = null): void
    {
        $referrer->forceFill([
            'referral_monthly_cap_override' => $cap,
        ])->save();

        $this->writeLedger([
            'user_referral_id' => null,
            'referrer_id' => $referrer->id,
            'referred_user_id' => null,
            'action_type' => 'admin_cap_override',
            'bonus_days' => 0,
            'feature_bonus' => null,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : 'Admin set referrer monthly cap override',
            'meta' => [
                'admin_id' => $admin->id,
                'monthly_cap_override' => $cap,
            ],
        ]);
    }

    public function adminForceApplyPendingClaim(UserReferral $row, User $admin, ?string $reason = null): bool
    {
        $applied = false;

        DB::transaction(function () use ($row, $admin, $reason, &$applied): void {
            $locked = UserReferral::query()->whereKey($row->id)->lockForUpdate()->first();
            if (! $locked
                || $locked->reward_applied
                || $locked->reward_status !== UserReferral::STATUS_PENDING_CLAIM) {
                return;
            }

            $referrer = User::query()->find($locked->referrer_id);
            $buyer = User::query()->find($locked->referred_user_id);
            $plan = $locked->pending_plan_id ? Plan::query()->find($locked->pending_plan_id) : null;
            if (! $referrer || ! $buyer || ! $plan) {
                return;
            }

            $stored = is_array($locked->pending_reward) ? $locked->pending_reward : [];
            $bonusDays = (int) ($stored['bonus_days'] ?? 0);
            /** @var array<string, int> $featureBonus */
            $featureBonus = (array) ($stored['feature_bonus'] ?? []);
            if ($bonusDays <= 0 && $featureBonus === []) {
                $resolved = $this->resolveRewardForPlan($plan);
                $bonusDays = (int) ($resolved['bonus_days'] ?? 0);
                $featureBonus = (array) ($resolved['feature_bonus'] ?? []);
            }

            if ($bonusDays <= 0 && $featureBonus === []) {
                return;
            }

            $sub = app(SubscriptionService::class)->getActiveSubscription($referrer);
            if (! $sub || $sub->ends_at === null) {
                return;
            }

            $this->processReferralReward(
                $locked,
                $referrer,
                $buyer,
                $plan,
                $bonusDays,
                $featureBonus,
                'admin_force_pending_claim',
                $reason !== null && trim($reason) !== '' ? trim($reason) : 'Admin forced pending referral reward',
                bypassReferrerLimits: true,
            );

            $applied = true;
        });

        return $applied;
    }

    public function adminReassignReferral(UserReferral $row, User $newReferrer, User $admin, string $reason): bool
    {
        if ($row->reward_applied) {
            return false;
        }

        if ((int) $newReferrer->id === (int) $row->referred_user_id) {
            return false;
        }

        if (UserReferral::query()
            ->where('referrer_id', $row->referred_user_id)
            ->where('referred_user_id', $newReferrer->id)
            ->exists()) {
            return false;
        }

        $reassigned = false;

        DB::transaction(function () use ($row, $newReferrer, $admin, $reason, &$reassigned): void {
            $locked = UserReferral::query()->whereKey($row->id)->lockForUpdate()->first();
            if (! $locked || $locked->reward_applied) {
                return;
            }

            $oldReferrerId = (int) $locked->referrer_id;
            $locked->forceFill(['referrer_id' => $newReferrer->id])->save();

            $this->writeLedger([
                'user_referral_id' => $locked->id,
                'referrer_id' => $newReferrer->id,
                'referred_user_id' => $locked->referred_user_id,
                'performed_by_admin_id' => $admin->id,
                'action_type' => 'admin_reassign',
                'bonus_days' => 0,
                'feature_bonus' => null,
                'reason' => trim($reason),
                'meta' => [
                    'admin_id' => $admin->id,
                    'old_referrer_id' => $oldReferrerId,
                    'new_referrer_id' => $newReferrer->id,
                ],
            ]);

            $reassigned = true;
        });

        return $reassigned;
    }

    /**
     * @param  array<string, int>  $featureBonus
     */
    public function adminApplyPartialReward(
        UserReferral $row,
        User $admin,
        int $bonusDays,
        array $featureBonus,
        bool $markRewardApplied,
        ?string $reason = null,
    ): bool {
        $bonusDays = max(0, $bonusDays);
        $featureBonus = $this->normalizeFeatureBonusMap($featureBonus);
        if ($bonusDays <= 0 && $featureBonus === []) {
            return false;
        }

        $applied = false;
        $ledgerReason = $reason !== null && trim($reason) !== '' ? trim($reason) : 'Admin partial referral reward';

        DB::transaction(function () use ($row, $admin, $bonusDays, $featureBonus, $markRewardApplied, $ledgerReason, &$applied): void {
            $locked = UserReferral::query()->whereKey($row->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }

            $referrer = User::query()->find((int) $locked->referrer_id);
            $buyer = User::query()->find((int) $locked->referred_user_id);
            if (! $referrer || ! $buyer) {
                return;
            }

            if ($markRewardApplied) {
                if ($locked->reward_applied || ! $locked->isReferrerRewardEligible()) {
                    return;
                }

                $plan = $locked->pending_plan_id ? Plan::query()->find($locked->pending_plan_id) : null;
                if (! $plan) {
                    return;
                }

                $sub = app(SubscriptionService::class)->getActiveSubscription($referrer);
                if (! $sub || $sub->ends_at === null) {
                    $locked->forceFill([
                        'reward_status' => UserReferral::STATUS_PENDING_CLAIM,
                        'pending_plan_id' => $plan->id,
                        'pending_claim_at' => now(),
                        'pending_reward' => [
                            'bonus_days' => $bonusDays,
                            'feature_bonus' => $featureBonus,
                            'plan_slug' => (string) $plan->slug,
                            'plan_name' => (string) $plan->name,
                        ],
                    ])->save();

                    $this->writeLedger([
                        'user_referral_id' => $locked->id,
                        'referrer_id' => $referrer->id,
                        'referred_user_id' => $buyer->id,
                        'performed_by_admin_id' => $admin->id,
                        'action_type' => 'admin_partial_queued',
                        'bonus_days' => $bonusDays,
                        'feature_bonus' => $featureBonus !== [] ? $featureBonus : null,
                        'reason' => $ledgerReason,
                        'meta' => ['admin_id' => $admin->id],
                    ]);

                    $applied = true;

                    return;
                }

                $this->processReferralReward(
                    $locked,
                    $referrer,
                    $buyer,
                    $plan,
                    $bonusDays,
                    $featureBonus,
                    'admin_partial_applied',
                    $ledgerReason,
                    bypassReferrerLimits: true,
                );

                $applied = true;

                return;
            }

            if (! $this->applyBonusToReferrerSubscription($referrer, $bonusDays, $featureBonus)) {
                return;
            }

            $this->writeLedger([
                'user_referral_id' => $locked->id,
                'referrer_id' => $referrer->id,
                'referred_user_id' => $buyer->id,
                'performed_by_admin_id' => $admin->id,
                'action_type' => 'admin_partial_grant',
                'bonus_days' => $bonusDays,
                'feature_bonus' => $featureBonus !== [] ? $featureBonus : null,
                'reason' => $ledgerReason,
                'meta' => ['admin_id' => $admin->id, 'mark_reward_applied' => false],
            ]);

            $applied = true;
        });

        return $applied;
    }

    public function adminRevokeAppliedReward(UserReferral $row, User $admin, string $reason): bool
    {
        $revoked = false;

        DB::transaction(function () use ($row, $admin, $reason, &$revoked): void {
            $locked = UserReferral::query()->whereKey($row->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->reward_applied) {
                return;
            }

            $referrer = User::query()->find((int) $locked->referrer_id);
            if (! $referrer) {
                return;
            }

            $applyLedger = ReferralRewardLedger::query()
                ->where('user_referral_id', $locked->id)
                ->whereIn('action_type', [
                    'auto_applied',
                    'admin_force_pending_claim',
                    'auto_claimed',
                    'admin_partial_applied',
                ])
                ->orderByDesc('id')
                ->first();

            $bonusDays = max(0, (int) ($applyLedger?->bonus_days ?? 0));
            /** @var array<string, int> $featureBonus */
            $featureBonus = is_array($applyLedger?->feature_bonus) ? $applyLedger->feature_bonus : [];

            if ($bonusDays > 0 || $featureBonus !== []) {
                if (! $this->reverseBonusOnReferrerSubscription($referrer, $bonusDays, $featureBonus)) {
                    return;
                }
            }

            $locked->forceFill([
                'reward_applied' => false,
                'reward_status' => UserReferral::STATUS_REWARD_REVOKED,
                'pending_plan_id' => null,
                'pending_reward' => null,
                'pending_claim_at' => null,
            ])->save();

            $this->syncReferrerEngagementStats($referrer);

            $this->writeLedger([
                'user_referral_id' => $locked->id,
                'referrer_id' => $referrer->id,
                'referred_user_id' => $locked->referred_user_id,
                'performed_by_admin_id' => $admin->id,
                'action_type' => 'admin_reward_revoked',
                'bonus_days' => $bonusDays,
                'feature_bonus' => $featureBonus !== [] ? $featureBonus : null,
                'reason' => trim($reason),
                'meta' => [
                    'admin_id' => $admin->id,
                    'reversed_ledger_id' => $applyLedger?->id,
                ],
            ]);

            $revoked = true;
        });

        return $revoked;
    }

    public function adminCancelPendingClaim(UserReferral $row, User $admin, string $reason): void
    {
        DB::transaction(function () use ($row, $admin, $reason): void {
            $locked = UserReferral::query()->whereKey($row->id)->lockForUpdate()->first();
            if (! $locked
                || $locked->reward_applied
                || $locked->reward_status !== UserReferral::STATUS_PENDING_CLAIM) {
                return;
            }

            $locked->forceFill([
                'reward_status' => UserReferral::STATUS_ADMIN_CANCELLED,
                'pending_plan_id' => null,
                'pending_reward' => null,
                'pending_claim_at' => null,
            ])->save();

            $this->writeLedger([
                'user_referral_id' => $locked->id,
                'referrer_id' => $locked->referrer_id,
                'referred_user_id' => $locked->referred_user_id,
                'action_type' => 'admin_pending_cancelled',
                'bonus_days' => 0,
                'feature_bonus' => null,
                'reason' => trim($reason),
                'meta' => ['admin_id' => $admin->id],
            ]);
        });
    }

    /**
     * @return array{
     *     user: User,
     *     summary: array<string, mixed>,
     *     recent_referrals: \Illuminate\Support\Collection<int, UserReferral>,
     *     recent_ledgers: \Illuminate\Support\Collection<int, ReferralRewardLedger>
     * }|null
     */
    public function adminReferrerSupremePanel(User $referrer): array
    {
        $recentReferrals = UserReferral::query()
            ->where('referrer_id', $referrer->id)
            ->with(['referredUser:id,name,mobile'])
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        $recentLedgers = ReferralRewardLedger::query()
            ->where('referrer_id', $referrer->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return [
            'user' => $referrer,
            'summary' => $this->summaryForReferrer($referrer),
            'recent_referrals' => $recentReferrals,
            'recent_ledgers' => $recentLedgers,
        ];
    }

    /**
     * Admin reports tab: invite funnel + economics for a date-filtered cohort.
     *
     * @return array{
     *     summary: array{
     *         total: int,
     *         rewarded: int,
     *         upgraded: int,
     *         profile_ready: int,
     *         pending: int,
     *         conversion_rate: float
     *     },
     *     funnel: array{
     *         invited: int,
     *         profile_ready: int,
     *         upgraded: int,
     *         rewarded: int,
     *         rates: array{profile_ready: float, upgraded: float, rewarded: float}
     *     },
     *     economics: array{
     *         referred_first_paid_revenue: float,
     *         invite_checkout_discount: float,
     *         referrer_reward_bonus_days: int,
     *         referrer_reward_cost_estimate: float,
     *         net_margin_estimate: float,
     *         avg_daily_plan_value: float
     *     }
     * }
     */
    public function adminReportsBundle(?string $fromDate = null, ?string $toDate = null): array
    {
        $base = UserReferral::query();
        $this->applyReferralCohortDateFilters($base, $fromDate, $toDate);

        $invited = (int) (clone $base)->count();

        $profileReady = (int) (clone $base)
            ->whereHas('referredUser.matrimonyProfile', function ($q) {
                $q->where('lifecycle_state', 'active')->where('is_suspended', false);
            })
            ->count();

        $upgraded = (int) (clone $base)->where(function ($q) {
            $q->where('reward_applied', true)
                ->orWhereNotNull('referred_checkout_bonus_used_at')
                ->orWhereIn('reward_status', [
                    UserReferral::STATUS_PENDING_CLAIM,
                    UserReferral::STATUS_CAP_SKIPPED,
                    UserReferral::STATUS_APPLIED,
                    UserReferral::STATUS_QUALITY_PENDING,
                ]);
        })->count();

        $rewarded = (int) (clone $base)->where('reward_applied', true)->count();

        $pending = (int) (clone $base)
            ->where('reward_applied', false)
            ->where(function ($q) {
                $q->whereNull('reward_status')
                    ->orWhereNotIn('reward_status', [
                        UserReferral::STATUS_ADMIN_CANCELLED,
                        UserReferral::STATUS_REWARD_REVOKED,
                        UserReferral::STATUS_PENDING_EXPIRED,
                    ]);
            })
            ->count();

        $referredUserIds = (clone $base)->pluck('referred_user_id')->filter()->unique()->values();
        $economics = $this->referralCohortEconomics($referredUserIds, $fromDate, $toDate);

        $summary = [
            'total' => $invited,
            'rewarded' => $rewarded,
            'upgraded' => $upgraded,
            'profile_ready' => $profileReady,
            'pending' => $pending,
            'conversion_rate' => $this->referralPercent($rewarded, $invited),
        ];

        return [
            'summary' => $summary,
            'funnel' => [
                'invited' => $invited,
                'profile_ready' => $profileReady,
                'upgraded' => $upgraded,
                'rewarded' => $rewarded,
                'rates' => [
                    'profile_ready' => $this->referralPercent($profileReady, $invited),
                    'upgraded' => $this->referralPercent($upgraded, $invited),
                    'rewarded' => $this->referralPercent($rewarded, $invited),
                ],
            ],
            'economics' => $economics,
        ];
    }

    /**
     * Plans-page / member UX: whether this buyer still has the invite checkout offer.
     *
     * @return array{eligible: bool, percent_off: int, extra_days: int}|null
     */
    public function referredCheckoutOfferFor(User $buyer): ?array
    {
        if (! $this->isReferredCheckoutEligible($buyer)) {
            return null;
        }

        $cfg = $this->referredCheckoutConfig();

        return [
            'eligible' => true,
            'percent_off' => $cfg['percent_off'],
            'extra_days' => $cfg['extra_days'],
        ];
    }

    /**
     * Apply invite discount at checkout (only when no manual coupon code).
     *
     * @return array{
     *     discount_amount: float,
     *     extra_duration_days: int,
     *     subscription_meta: array<string, mixed>
     * }|null
     */
    public function computeReferredCheckoutDiscount(User $buyer, Plan $plan, float $baseAmount): ?array
    {
        if (! $this->isReferredCheckoutEligible($buyer) || Plan::isFreeCatalogSlug((string) $plan->slug)) {
            return null;
        }

        $cfg = $this->referredCheckoutBenefitForPlan($plan);
        if (! $cfg['enabled']) {
            return null;
        }

        $percent = max(0, min(100, $cfg['percent_off']));
        $extraDays = max(0, $cfg['extra_days']);
        $discount = $percent > 0
            ? round($baseAmount * ($percent / 100), 2)
            : 0.0;

        if ($discount <= 0.0 && $extraDays <= 0) {
            return null;
        }

        return [
            'discount_amount' => $discount,
            'extra_duration_days' => $extraDays,
            'subscription_meta' => [
                'referred_checkout' => [
                    'percent_off' => $percent,
                    'discount_amount' => $discount,
                    'extra_days' => $extraDays,
                ],
            ],
        ];
    }

    /**
     * Mark invite checkout benefit consumed after a successful paid purchase.
     */
    public function markReferredCheckoutBonusConsumed(User $buyer): void
    {
        if (! $this->isEngineEnabled()) {
            return;
        }

        $row = UserReferral::query()
            ->where('referred_user_id', $buyer->id)
            ->whereNull('referred_checkout_bonus_used_at')
            ->first();

        if (! $row) {
            return;
        }

        $row->forceFill(['referred_checkout_bonus_used_at' => now()])->save();
    }

    /**
     * When {@code $buyer} purchases a paid plan, extend the referrer's subscription if a pending referral exists.
     */
    public function applyPurchaseRewardIfEligible(User $buyer, Plan $plan): void
    {
        if (! $this->isEngineEnabled()) {
            return;
        }

        if ($this->getBoolSetting(self::ENGINE_KEYS['paid_only'], true) && Plan::isFreeCatalogSlug((string) $plan->slug)) {
            return;
        }

        $minPlanAmount = $this->getIntSetting(self::ENGINE_KEYS['min_plan_amount'], 0);
        if ($minPlanAmount > 0 && (float) $plan->price < $minPlanAmount) {
            return;
        }

        $reward = $this->resolveRewardForPlan($plan);
        $bonusDays = (int) ($reward['bonus_days'] ?? 0);
        /** @var array<string, int> $featureBonus */
        $featureBonus = (array) ($reward['feature_bonus'] ?? []);

        if ($bonusDays <= 0 && $featureBonus === []) {
            return;
        }

        $row = UserReferral::query()
            ->where('referred_user_id', $buyer->id)
            ->where('reward_applied', false)
            ->where(function ($q) {
                $q->whereNull('reward_status')
                    ->orWhere('reward_status', UserReferral::STATUS_PENDING_CLAIM);
            })
            ->first();

        if ($row && $row->isReferrerRewardEligible()) {
            DB::transaction(function () use ($row, $bonusDays, $featureBonus, $buyer, $plan) {
                $locked = UserReferral::query()->whereKey($row->id)->lockForUpdate()->first();
                if (! $locked || $locked->reward_applied || ! $locked->isReferrerRewardEligible()) {
                    return;
                }

                $referrer = User::query()->find($locked->referrer_id);
                if (! $referrer) {
                    return;
                }

                $buyer->loadMissing('matrimonyProfile');
                $displayName = $this->privacySafeReferredName($buyer, $buyer->matrimonyProfile);
                try {
                    app(NotificationService::class)->notifyReferralInviteUpgraded($referrer, $displayName, (string) $plan->name);
                } catch (\Throwable $e) {
                    Log::warning('Referral invite upgraded notification failed', ['error' => $e->getMessage()]);
                }

                $buyer->loadMissing('matrimonyProfile');
                if (! $this->referredBuyerMeetsQualityGates($buyer)) {
                    $this->holdReferralRewardForQualityGates($locked, $buyer, $plan, $bonusDays, $featureBonus);

                    return;
                }

                $this->processReferralReward(
                    $locked,
                    $referrer,
                    $buyer,
                    $plan,
                    $bonusDays,
                    $featureBonus,
                    'auto_applied',
                    'Purchase reward applied',
                );
            });

            return;
        }

        if ($row !== null) {
            return;
        }

        $this->applyRenewalMicroBonusIfEligible($buyer, $plan);
    }

    /**
     * Optional small referrer bonus when a referred member renews onto a paid plan (once per invite).
     */
    public function applyRenewalMicroBonusIfEligible(User $buyer, Plan $plan): void
    {
        if (! $this->isEngineEnabled() || ! $this->renewalMicroBonusEnabled()) {
            return;
        }

        if ($this->getBoolSetting(self::ENGINE_KEYS['paid_only'], true) && Plan::isFreeCatalogSlug((string) $plan->slug)) {
            return;
        }

        $bonusDays = $this->renewalMicroBonusDays();
        if ($bonusDays <= 0) {
            return;
        }

        $row = UserReferral::query()
            ->where('referred_user_id', $buyer->id)
            ->where('reward_applied', true)
            ->whereNull('renewal_micro_bonus_applied_at')
            ->first();

        if (! $row || ! $row->isReferrerRewardEligible()) {
            return;
        }

        DB::transaction(function () use ($row, $buyer, $plan, $bonusDays): void {
            $locked = UserReferral::query()->whereKey($row->id)->lockForUpdate()->first();
            if (! $locked || $locked->renewal_micro_bonus_applied_at !== null || ! $locked->reward_applied) {
                return;
            }

            $referrer = User::query()->find($locked->referrer_id);
            if (! $referrer || $referrer->isReferralRewardsFrozen() || $this->isMonthlyCapReached($referrer)) {
                return;
            }

            $sub = app(SubscriptionService::class)->getActiveSubscription($referrer);
            if (! $sub || $sub->ends_at === null) {
                return;
            }

            $sub->ends_at = $sub->ends_at->copy()->addDays($bonusDays);
            $sub->save();
            app(EntitlementService::class)->resyncFromActiveSubscription((int) $referrer->id);

            $locked->forceFill(['renewal_micro_bonus_applied_at' => now()])->save();

            $this->writeLedger([
                'user_referral_id' => $locked->id,
                'referrer_id' => $referrer->id,
                'referred_user_id' => $buyer->id,
                'action_type' => 'renewal_micro_applied',
                'bonus_days' => $bonusDays,
                'feature_bonus' => null,
                'reason' => 'Renewal micro-bonus for referred member paid renewal',
                'meta' => [
                    'plan_id' => $plan->id,
                    'plan_slug' => (string) $plan->slug,
                    'plan_name' => (string) $plan->name,
                ],
            ]);
        });
    }

    /**
     * Apply queued referral rewards after the referrer gains an active paid subscription.
     */
    public function claimPendingReferralRewards(User $referrer): void
    {
        if (! $this->isEngineEnabled() || $referrer->isReferralRewardsFrozen()) {
            return;
        }

        $this->expireStalePendingClaims($referrer);

        $sub = app(SubscriptionService::class)->getActiveSubscription($referrer);
        if (! $sub || $sub->ends_at === null) {
            return;
        }

        $pendingRows = UserReferral::query()
            ->where('referrer_id', $referrer->id)
            ->where('reward_status', UserReferral::STATUS_PENDING_CLAIM)
            ->where('reward_applied', false)
            ->orderBy('id')
            ->get();

        foreach ($pendingRows as $row) {
            $buyer = User::query()->find($row->referred_user_id);
            if (! $buyer) {
                continue;
            }

            $plan = $row->pending_plan_id
                ? Plan::query()->find($row->pending_plan_id)
                : null;
            if (! $plan) {
                continue;
            }

            $stored = is_array($row->pending_reward) ? $row->pending_reward : [];
            $bonusDays = (int) ($stored['bonus_days'] ?? 0);
            /** @var array<string, int> $featureBonus */
            $featureBonus = (array) ($stored['feature_bonus'] ?? []);
            if ($bonusDays <= 0 && $featureBonus === []) {
                $resolved = $this->resolveRewardForPlan($plan);
                $bonusDays = (int) ($resolved['bonus_days'] ?? 0);
                $featureBonus = (array) ($resolved['feature_bonus'] ?? []);
            }

            if ($bonusDays <= 0 && $featureBonus === []) {
                continue;
            }

            DB::transaction(function () use ($row, $referrer, $buyer, $plan, $bonusDays, $featureBonus) {
                $locked = UserReferral::query()->whereKey($row->id)->lockForUpdate()->first();
            if (! $locked || $locked->reward_applied || $locked->reward_status !== UserReferral::STATUS_PENDING_CLAIM || ! $locked->isReferrerRewardEligible()) {
                return;
            }

            $this->processReferralReward(
                    $locked,
                    $referrer,
                    $buyer,
                    $plan,
                    $bonusDays,
                    $featureBonus,
                    'auto_claimed',
                    'Pending referral reward claimed after subscription',
                );
            });
        }
    }

    public function countPendingClaimsForReferrer(User $referrer): int
    {
        if (! $this->isEngineEnabled()) {
            return 0;
        }

        $this->expireStalePendingClaims($referrer);

        return UserReferral::query()
            ->where('referrer_id', $referrer->id)
            ->where('reward_status', UserReferral::STATUS_PENDING_CLAIM)
            ->where('reward_applied', false)
            ->count();
    }

    /**
     * Monthly reward cap progress for member UI (null when cap is disabled).
     *
     * @return array{cap: int, earned: int, remaining: int, at_cap: bool}|null
     */
    public function monthlyCapProgressForReferrer(User $referrer): ?array
    {
        if (! $this->isEngineEnabled()) {
            return null;
        }

        $cap = $this->monthlyCapForReferrer($referrer);
        if ($cap <= 0) {
            return null;
        }

        $earned = $this->countRewardsAppliedThisMonth($referrer);
        $remaining = max(0, $cap - $earned);

        return [
            'cap' => $cap,
            'earned' => $earned,
            'remaining' => $remaining,
            'at_cap' => $earned >= $cap,
        ];
    }

    /**
     * @return array{
     *     engine_enabled: bool,
     *     invited: int,
     *     converted: int,
     *     referrals_done: int,
     *     rewards_earned: int,
     *     pending_claim: int,
     *     monthly_cap_progress: array{cap: int, earned: int, remaining: int, at_cap: bool}|null
     * }
     */
    public function summaryForReferrer(User $referrer): array
    {
        if (! $this->isEngineEnabled()) {
            return [
                'engine_enabled' => false,
                'invited' => 0,
                'converted' => 0,
                'referrals_done' => 0,
                'rewards_earned' => 0,
                'pending_claim' => 0,
                'monthly_cap_progress' => null,
            ];
        }

        $this->expireStalePendingClaims($referrer);

        $base = UserReferral::query()->where('referrer_id', $referrer->id);
        $referralsDone = $this->referralsDoneForReferrer($referrer);

        return [
            'engine_enabled' => true,
            'invited' => (int) (clone $base)->count(),
            'converted' => (int) (clone $base)->where(function ($q) {
                $q->where('reward_applied', true)
                    ->orWhereNotNull('referred_checkout_bonus_used_at')
                    ->orWhereIn('reward_status', [
                        UserReferral::STATUS_PENDING_CLAIM,
                        UserReferral::STATUS_CAP_SKIPPED,
                        UserReferral::STATUS_APPLIED,
                        UserReferral::STATUS_QUALITY_PENDING,
                    ]);
            })->count(),
            'referrals_done' => $referralsDone,
            'rewards_earned' => $referralsDone,
            'pending_claim' => $this->countPendingClaimsForReferrer($referrer),
            'monthly_cap_progress' => $this->monthlyCapProgressForReferrer($referrer),
        ];
    }

    /**
     * Live proof for members: referral bonuses currently merged into the active paid subscription.
     *
     * @return array{
     *     has_active_plan: bool,
     *     paid_until: string|null,
     *     carry_lines: list<string>,
     *     rewards_applied_count: int
     * }
     */
    public function activeReferralBonusProofForReferrer(User $referrer): array
    {
        $rewardsAppliedCount = (int) UserReferral::query()
            ->where('referrer_id', $referrer->id)
            ->where('reward_applied', true)
            ->count();

        $sub = app(SubscriptionService::class)->getActiveSubscription($referrer);
        if (! $sub || $sub->ends_at === null) {
            return [
                'has_active_plan' => false,
                'paid_until' => null,
                'carry_lines' => [],
                'rewards_applied_count' => $rewardsAppliedCount,
            ];
        }

        return [
            'has_active_plan' => true,
            'paid_until' => $sub->ends_at->timezone(config('app.timezone'))->format('d M Y, H:i'),
            'carry_lines' => $this->carryQuotaLinesFromSubscription($sub),
            'rewards_applied_count' => $rewardsAppliedCount,
        ];
    }

    /**
     * Member-facing referral rows (privacy-safe labels, no mobile/email).
     *
     * @return list<array{
     *     id: int,
     *     display_name: string,
     *     stage: string,
     *     reward_hint: string|null,
     *     quota_hint: string|null,
     *     reward_detail_lines: list<string>,
     *     reward_summary: string|null,
     *     reward_plan_name: string|null,
     *     reward_applied_at: \Illuminate\Support\Carbon|null,
     *     joined_at: \Illuminate\Support\Carbon|null
     * }>
     */
    public function listEntriesForReferrer(User $referrer): array
    {
        if (! $this->isEngineEnabled()) {
            return [];
        }

        $rows = UserReferral::query()
            ->where('referrer_id', $referrer->id)
            ->with(['referredUser.matrimonyProfile:id,user_id,full_name,lifecycle_state,is_suspended'])
            ->orderByDesc('created_at')
            ->get();

        $appliedLedgers = $this->latestAppliedLedgersByReferralId($rows);

        return $rows->map(function (UserReferral $row) use ($appliedLedgers): array {
            $referred = $row->referredUser;
            $profile = $referred?->matrimonyProfile;
            $stage = $this->resolveMemberReferralStage($row, $profile, $referred);
            $appliedLedger = $appliedLedgers[(int) $row->id] ?? null;
            $rewardDetails = $this->memberRewardDetails($row, $stage, $appliedLedger);
            $rewardHint = $this->memberRewardHint($row, $stage, $rewardDetails);
            $quotaHint = $this->memberQuotaRewardHintFromDetails($rewardDetails, $stage);

            return [
                'id' => (int) $row->id,
                'display_name' => $this->privacySafeReferredName($referred, $profile),
                'stage' => $stage,
                'reward_hint' => $rewardHint,
                'quota_hint' => $quotaHint,
                'reward_detail_lines' => $rewardDetails['detail_lines'],
                'reward_summary' => $rewardDetails['summary'],
                'reward_plan_name' => $rewardDetails['plan_name'],
                'reward_applied_at' => $row->reward_applied ? ($appliedLedger?->created_at ?? $row->updated_at) : null,
                'joined_at' => $row->created_at,
            ];
        })->values()->all();
    }

    /**
     * @return list<string>
     */
    private function carryQuotaLinesFromSubscription(Subscription $subscription): array
    {
        $meta = is_array($subscription->meta) ? $subscription->meta : [];
        $carry = $meta['carry_quota'] ?? null;
        if (! is_array($carry) || $carry === []) {
            return [];
        }

        $lines = [];
        foreach ($carry as $key => $raw) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            $units = (int) $raw;
            if ($units === 0) {
                continue;
            }
            $lines[] = __('revenue_summary.carry_quota_line', [
                'label' => PlanFeatureLabel::label($key),
                'units' => $units,
            ]);
        }

        return $lines;
    }

    /**
     * Human-readable list of referral bonus items (days + quota bumps) for notifications and UI.
     *
     * @param  array<string, int>  $featureBonus
     * @return list<string>
     */
    public function formatRewardBenefitLines(int $bonusDays, array $featureBonus): array
    {
        $lines = [];
        if ($bonusDays > 0) {
            $lines[] = __('referrals.reward_item_days', ['days' => $bonusDays]);
        }

        foreach ($featureBonus as $key => $value) {
            $increment = (int) $value;
            if ($increment <= 0) {
                continue;
            }
            $label = $this->memberFeatureBonusLabel((string) $key);
            if ($label === '') {
                continue;
            }
            $lines[] = __('referrals.quota_bonus_line', [
                'label' => $label,
                'amount' => $increment,
            ]);
        }

        return $lines;
    }

    /**
     * @param  array<string, int>  $featureBonus
     */
    public function formatRewardBenefitsSummary(int $bonusDays, array $featureBonus): string
    {
        $lines = $this->formatRewardBenefitLines($bonusDays, $featureBonus);

        return $lines !== [] ? implode(' · ', $lines) : '';
    }

    /**
     * @param  array<string, int>  $featureBonus
     * @return array{en: string, mr: string}
     */
    public function formatRewardBenefitsSummaryBilingual(int $bonusDays, array $featureBonus): array
    {
        return [
            'en' => $this->formatRewardBenefitsSummaryForLocale($bonusDays, $featureBonus, 'en'),
            'mr' => $this->formatRewardBenefitsSummaryForLocale($bonusDays, $featureBonus, 'mr'),
        ];
    }

    /**
     * @param  array<string, int>  $featureBonus
     */
    private function formatRewardBenefitsSummaryForLocale(int $bonusDays, array $featureBonus, string $locale): string
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);
        \App\Models\Translation::loadIntoTranslator($locale);

        try {
            return $this->formatRewardBenefitsSummary($bonusDays, $featureBonus);
        } finally {
            app()->setLocale($previous);
            \App\Models\Translation::loadIntoTranslator($previous);
        }
    }

    private function resolveMemberReferralStage(UserReferral $row, ?MatrimonyProfile $profile, ?User $referred): string
    {
        if ($row->review_status === UserReferral::REVIEW_PENDING) {
            return 'review_pending';
        }

        if ($row->review_status === UserReferral::REVIEW_REJECTED) {
            return 'review_rejected';
        }

        if ($row->reward_applied) {
            return 'reward_earned';
        }

        if ($row->reward_status === UserReferral::STATUS_PENDING_CLAIM) {
            return 'pending_claim';
        }

        if ($row->reward_status === UserReferral::STATUS_CAP_SKIPPED) {
            return 'cap_skipped';
        }

        if ($row->reward_status === UserReferral::STATUS_ADMIN_CANCELLED) {
            return 'admin_cancelled';
        }

        if ($row->reward_status === UserReferral::STATUS_REWARD_REVOKED) {
            return 'reward_revoked';
        }

        if ($row->reward_status === UserReferral::STATUS_PENDING_EXPIRED) {
            return 'pending_expired';
        }

        if ($row->reward_status === UserReferral::STATUS_QUALITY_PENDING) {
            return 'quality_pending';
        }

        if ($this->referredInviteeHasUpgraded($referred, $row)) {
            return 'upgraded';
        }

        if ($profile
            && ($profile->lifecycle_state ?? '') === 'active'
            && ! ($profile->is_suspended ?? false)) {
            return 'profile_active';
        }

        return 'registered';
    }

    private function referredInviteeHasUpgraded(?User $referred, UserReferral $row): bool
    {
        if ($row->referred_checkout_bonus_used_at !== null) {
            return true;
        }

        return $referred !== null && $this->buyerHasPriorPaidSubscription($referred);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, UserReferral>  $rows
     * @return array<int, ReferralRewardLedger>
     */
    private function latestAppliedLedgersByReferralId(\Illuminate\Support\Collection $rows): array
    {
        $ids = $rows
            ->filter(fn (UserReferral $row): bool => (bool) $row->reward_applied)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return [];
        }

        $map = [];
        ReferralRewardLedger::query()
            ->whereIn('user_referral_id', $ids)
            ->whereIn('action_type', self::APPLIED_REWARD_LEDGER_ACTIONS)
            ->orderByDesc('id')
            ->get()
            ->each(function (ReferralRewardLedger $ledger) use (&$map): void {
                $referralId = (int) $ledger->user_referral_id;
                if (! isset($map[$referralId])) {
                    $map[$referralId] = $ledger;
                }
            });

        return $map;
    }

    /**
     * @return array{
     *     bonus_days: int,
     *     feature_bonus: array<string, int>,
     *     plan_name: string|null,
     *     detail_lines: list<string>,
     *     summary: string|null
     * }
     */
    private function memberRewardDetails(UserReferral $row, string $stage, ?ReferralRewardLedger $appliedLedger): array
    {
        $bonusDays = 0;
        /** @var array<string, int> $featureBonus */
        $featureBonus = [];
        $planName = null;

        $showBreakdown = in_array($stage, ['reward_earned', 'pending_claim', 'upgraded', 'quality_pending', 'cap_skipped'], true);

        if ($showBreakdown && $row->reward_applied && $appliedLedger !== null) {
            $bonusDays = max(0, (int) $appliedLedger->bonus_days);
            $featureBonus = is_array($appliedLedger->feature_bonus) ? $appliedLedger->feature_bonus : [];
            $meta = is_array($appliedLedger->meta) ? $appliedLedger->meta : [];
            $planName = isset($meta['plan_name']) ? trim((string) $meta['plan_name']) : null;
        } elseif ($showBreakdown) {
            $stored = is_array($row->pending_reward) ? $row->pending_reward : [];
            $bonusDays = max(0, (int) ($stored['bonus_days'] ?? 0));
            $featureBonus = (array) ($stored['feature_bonus'] ?? []);
            $planName = isset($stored['plan_name']) ? trim((string) $stored['plan_name']) : null;
        }

        if ($planName === '') {
            $planName = null;
        }

        $detailLines = $showBreakdown ? $this->formatRewardBenefitLines($bonusDays, $featureBonus) : [];
        $summary = $showBreakdown ? $this->formatRewardBenefitsSummary($bonusDays, $featureBonus) : null;

        return [
            'bonus_days' => $bonusDays,
            'feature_bonus' => $featureBonus,
            'plan_name' => $planName,
            'detail_lines' => $detailLines,
            'summary' => $summary !== '' ? $summary : null,
        ];
    }

    /**
     * @param  array{
     *     bonus_days: int,
     *     feature_bonus: array<string, int>,
     *     plan_name: string|null,
     *     detail_lines: list<string>,
     *     summary: string|null
     * }  $rewardDetails
     */
    private function memberRewardHint(UserReferral $row, string $stage, array $rewardDetails): ?string
    {
        $bonusDays = (int) ($rewardDetails['bonus_days'] ?? 0);

        if (in_array($stage, ['reward_earned', 'pending_claim', 'upgraded', 'quality_pending', 'cap_skipped'], true) && $bonusDays > 0) {
            return (string) $bonusDays;
        }

        if ($stage === 'reward_earned' && ($rewardDetails['summary'] ?? null) !== null) {
            return 'applied';
        }

        return null;
    }

    /**
     * @param  array{
     *     bonus_days: int,
     *     feature_bonus: array<string, int>,
     *     plan_name: string|null,
     *     detail_lines: list<string>,
     *     summary: string|null
     * }  $rewardDetails
     */
    private function memberQuotaRewardHintFromDetails(array $rewardDetails, string $stage): ?string
    {
        if (! in_array($stage, ['reward_earned', 'pending_claim', 'upgraded', 'quality_pending', 'cap_skipped'], true)) {
            return null;
        }

        return $rewardDetails['summary'] ?? null;
    }

    private function memberFeatureBonusLabel(string $featureKey): string
    {
        return match ($featureKey) {
            'chat_send_limit' => __('referrals.quota_label_chat'),
            'contact_view_limit' => __('referrals.quota_label_contact'),
            'interest_send_limit' => __('referrals.quota_label_interest'),
            'daily_profile_view_limit' => __('referrals.quota_label_profile_views'),
            'who_viewed_me_preview_limit' => __('referrals.quota_label_who_viewed'),
            default => '',
        };
    }

    private function referrerDisplayNameForReferredUser(User $buyer): ?string
    {
        $referral = UserReferral::query()
            ->where('referred_user_id', $buyer->id)
            ->with('referrer.matrimonyProfile:id,user_id,full_name')
            ->first();

        $referrer = $referral?->referrer;
        if (! $referrer) {
            return null;
        }

        $displayName = $this->privacySafeDisplayName($referrer, $referrer->matrimonyProfile);
        if ($displayName === __('referrals.member_placeholder')) {
            return null;
        }

        return $displayName;
    }

    private function privacySafeReferredName(?User $referred, ?MatrimonyProfile $profile): string
    {
        $displayName = $this->privacySafeDisplayName($referred, $profile);

        return $displayName !== '' ? $displayName : __('referrals.member_placeholder');
    }

    private function privacySafeDisplayName(?User $user, ?MatrimonyProfile $profile): string
    {
        $name = trim((string) ($profile?->full_name ?? $user?->name ?? ''));
        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\h+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) > 1) {
            return $parts[0].' '.mb_substr((string) end($parts), 0, 1, 'UTF-8').'.';
        }

        return $parts[0] ?? $name;
    }

    /**
     * @param  array<string, int>  $featureBonus
     */
    private function processReferralReward(
        UserReferral $locked,
        User $referrer,
        User $buyer,
        Plan $plan,
        int $bonusDays,
        array $featureBonus,
        string $ledgerAction,
        string $ledgerReason,
        bool $bypassReferrerLimits = false,
    ): void {
        if (! $bypassReferrerLimits && $referrer->isReferralRewardsFrozen()) {
            return;
        }

        if (! $bypassReferrerLimits && $this->isMonthlyCapReached($referrer)) {
            $monthlyCap = $this->getIntSetting(self::ENGINE_KEYS['monthly_cap'], 0);
            Log::info('Referral reward skipped due to monthly cap', [
                'referrer_id' => $referrer->id,
                'referred_user_id' => $buyer->id,
                'monthly_cap' => $monthlyCap,
            ]);

            $locked->forceFill([
                'reward_status' => UserReferral::STATUS_CAP_SKIPPED,
            ])->save();

            $buyer->loadMissing('matrimonyProfile');
            $displayName = $this->privacySafeReferredName($buyer, $buyer->matrimonyProfile);
            try {
                app(NotificationService::class)->notifyReferralCapSkipped($referrer, $displayName, (string) $plan->name);
            } catch (\Throwable $e) {
                Log::warning('Referral cap skipped notification failed', ['error' => $e->getMessage()]);
            }

            $this->writeLedger([
                'user_referral_id' => $locked->id,
                'referrer_id' => $referrer->id,
                'referred_user_id' => $buyer->id,
                'action_type' => 'auto_skipped_cap',
                'bonus_days' => 0,
                'feature_bonus' => null,
                'reason' => 'Monthly reward cap reached',
                'meta' => [
                    'monthly_cap' => $monthlyCap,
                ],
            ]);

            return;
        }

        $sub = app(SubscriptionService::class)->getActiveSubscription($referrer);
        if (! $sub || $sub->ends_at === null) {
            $locked->forceFill([
                'reward_status' => UserReferral::STATUS_PENDING_CLAIM,
                'pending_claim_at' => now(),
                'pending_plan_id' => $plan->id,
                'pending_reward' => [
                    'bonus_days' => max(0, $bonusDays),
                    'feature_bonus' => $featureBonus,
                    'plan_slug' => (string) $plan->slug,
                    'plan_name' => (string) $plan->name,
                ],
            ])->save();

            $this->writeLedger([
                'user_referral_id' => $locked->id,
                'referrer_id' => $referrer->id,
                'referred_user_id' => $buyer->id,
                'action_type' => 'pending_no_active_plan',
                'bonus_days' => max(0, $bonusDays),
                'feature_bonus' => $featureBonus !== [] ? $featureBonus : null,
                'reason' => 'Referrer has no active paid plan; reward queued',
                'meta' => [
                    'plan_id' => $plan->id,
                    'plan_slug' => (string) $plan->slug,
                    'plan_name' => (string) $plan->name,
                ],
            ]);

            $buyer->loadMissing('matrimonyProfile');
            $displayName = $this->privacySafeReferredName($buyer, $buyer->matrimonyProfile);
            try {
                app(NotificationService::class)->notifyReferralRewardPending(
                    $referrer,
                    $displayName,
                    (string) $plan->name,
                    max(0, $bonusDays),
                );
            } catch (\Throwable $e) {
                Log::warning('Referral reward pending notification failed', ['error' => $e->getMessage()]);
            }

            return;
        }

        if ($bonusDays > 0) {
            $sub->ends_at = $sub->ends_at->copy()->addDays($bonusDays);
        }

        if ($featureBonus !== []) {
            $meta = is_array($sub->meta) ? $sub->meta : [];
            $carry = is_array($meta['carry_quota'] ?? null) ? $meta['carry_quota'] : [];
            foreach ($featureBonus as $featureKey => $inc) {
                $carry[$featureKey] = max(0, (int) ($carry[$featureKey] ?? 0)) + max(0, (int) $inc);
            }
            $meta['carry_quota'] = $carry;
            $sub->meta = $meta;
        }

        $sub->save();
        app(EntitlementService::class)->resyncFromActiveSubscription((int) $referrer->id);

        $locked->forceFill([
            'reward_applied' => true,
            'reward_status' => UserReferral::STATUS_APPLIED,
            'pending_plan_id' => null,
            'pending_reward' => null,
            'pending_claim_at' => null,
        ])->save();

        $this->syncReferrerEngagementStats($referrer);

        $this->writeLedger([
            'user_referral_id' => $locked->id,
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'action_type' => $ledgerAction,
            'bonus_days' => max(0, $bonusDays),
            'feature_bonus' => $featureBonus !== [] ? $featureBonus : null,
            'reason' => $ledgerReason,
            'meta' => [
                'plan_id' => $plan->id,
                'plan_slug' => (string) $plan->slug,
                'plan_name' => (string) $plan->name,
            ],
        ]);

        try {
            if ($bonusDays > 0 || $featureBonus !== []) {
                app(NotificationService::class)->notifyReferralReward(
                    $referrer,
                    $buyer,
                    max(0, $bonusDays),
                    (string) $plan->name,
                    $featureBonus,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Referral reward notification failed', ['error' => $e->getMessage()]);
        }
    }

    private function isMonthlyCapReached(User $referrer): bool
    {
        $monthlyCap = $this->monthlyCapForReferrer($referrer);
        if ($monthlyCap <= 0) {
            return false;
        }

        return $this->countRewardsAppliedThisMonth($referrer) >= $monthlyCap;
    }

    /**
     * null override = global engine cap; 0 = unlimited for this referrer; >0 = custom cap.
     */
    private function monthlyCapForReferrer(User $referrer): int
    {
        if ($referrer->referral_monthly_cap_override !== null) {
            return max(0, (int) $referrer->referral_monthly_cap_override);
        }

        return $this->getIntSetting(self::ENGINE_KEYS['monthly_cap'], 0);
    }

    private function countRewardsAppliedThisMonth(User $referrer): int
    {
        return (int) UserReferral::query()
            ->where('referrer_id', $referrer->id)
            ->where('reward_applied', true)
            ->whereBetween('updated_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
    }

    private function referralsDoneForReferrer(User $referrer): int
    {
        $engagement = app(UserEngagementStatsService::class);
        $stored = $engagement->referralsDoneFor($referrer);
        $live = (int) UserReferral::query()
            ->where('referrer_id', $referrer->id)
            ->where('reward_applied', true)
            ->count();

        if ($stored !== $live) {
            $engagement->syncReferralsDone($referrer);

            return $engagement->referralsDoneFor($referrer);
        }

        return $stored;
    }

    private function syncReferrerEngagementStats(User $referrer): void
    {
        app(UserEngagementStatsService::class)->syncReferralsDone($referrer);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<UserReferral>  $query
     */
    private function applyReferralCohortDateFilters($query, ?string $fromDate, ?string $toDate): void
    {
        if ($fromDate !== null && $fromDate !== '') {
            $query->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate !== null && $toDate !== '') {
            $query->whereDate('created_at', '<=', $toDate);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int|string>  $referredUserIds
     * @return array{
     *     referred_first_paid_revenue: float,
     *     invite_checkout_discount: float,
     *     referrer_reward_bonus_days: int,
     *     referrer_reward_cost_estimate: float,
     *     net_margin_estimate: float,
     *     avg_daily_plan_value: float
     * }
     */
    private function referralCohortEconomics($referredUserIds, ?string $fromDate, ?string $toDate): array
    {
        $referredRevenue = 0.0;
        $inviteDiscount = 0.0;

        if ($referredUserIds->isNotEmpty()) {
            $subs = Subscription::query()
                ->whereIn('user_id', $referredUserIds->all())
                ->with('plan:id,slug,price,duration_days')
                ->orderBy('user_id')
                ->orderBy('created_at')
                ->get()
                ->groupBy('user_id');

            foreach ($subs as $userSubs) {
                foreach ($userSubs as $sub) {
                    $slug = (string) ($sub->plan?->slug ?? '');
                    if ($slug === '' || Plan::isFreeCatalogSlug($slug)) {
                        continue;
                    }

                    $meta = is_array($sub->meta) ? $sub->meta : [];
                    $paid = (float) ($meta['amount_paid'] ?? $meta['final_amount'] ?? $sub->plan?->price ?? 0);
                    $referredRevenue += max(0, $paid);

                    $rc = is_array($meta['referred_checkout'] ?? null) ? $meta['referred_checkout'] : [];
                    $inviteDiscount += max(0, (float) ($rc['discount_amount'] ?? 0));

                    break;
                }
            }
        }

        $ledgerQuery = ReferralRewardLedger::query()
            ->whereIn('action_type', [
                'auto_applied',
                'auto_claimed',
                'admin_force_pending_claim',
                'admin_partial_applied',
            ]);

        if ($fromDate !== null && $fromDate !== '') {
            $ledgerQuery->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate !== null && $toDate !== '') {
            $ledgerQuery->whereDate('created_at', '<=', $toDate);
        }

        $bonusDaysGranted = (int) $ledgerQuery->sum('bonus_days');
        $avgDaily = $this->averagePaidPlanDailyValue();
        $referrerCost = round($bonusDaysGranted * $avgDaily, 2);
        $netMargin = round($referredRevenue - $inviteDiscount - $referrerCost, 2);

        return [
            'referred_first_paid_revenue' => round($referredRevenue, 2),
            'invite_checkout_discount' => round($inviteDiscount, 2),
            'referrer_reward_bonus_days' => $bonusDaysGranted,
            'referrer_reward_cost_estimate' => $referrerCost,
            'net_margin_estimate' => $netMargin,
            'avg_daily_plan_value' => $avgDaily,
        ];
    }

    private function averagePaidPlanDailyValue(): float
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->where('duration_days', '>', 0)
            ->where('price', '>', 0)
            ->get(['price', 'duration_days', 'slug']);

        $rates = [];
        foreach ($plans as $plan) {
            if (Plan::isFreeCatalogSlug((string) $plan->slug)) {
                continue;
            }
            $days = max(1, (int) $plan->duration_days);
            $rates[] = (float) $plan->price / $days;
        }

        if ($rates === []) {
            return 0.0;
        }

        return round(array_sum($rates) / count($rates), 2);
    }

    private function referralPercent(int $part, int $whole): float
    {
        if ($whole <= 0) {
            return 0.0;
        }

        return round(($part * 100) / $whole, 2);
    }

    /**
     * @param  array<string, int>  $featureBonus
     */
    private function applyBonusToReferrerSubscription(User $referrer, int $bonusDays, array $featureBonus): bool
    {
        $sub = app(SubscriptionService::class)->getActiveSubscription($referrer);
        if (! $sub || $sub->ends_at === null) {
            return false;
        }

        if ($bonusDays > 0) {
            $sub->ends_at = $sub->ends_at->copy()->addDays($bonusDays);
        }

        if ($featureBonus !== []) {
            $meta = is_array($sub->meta) ? $sub->meta : [];
            $carry = is_array($meta['carry_quota'] ?? null) ? $meta['carry_quota'] : [];
            foreach ($featureBonus as $featureKey => $inc) {
                $carry[$featureKey] = max(0, (int) ($carry[$featureKey] ?? 0)) + max(0, (int) $inc);
            }
            $meta['carry_quota'] = $carry;
            $sub->meta = $meta;
        }

        $sub->save();
        app(EntitlementService::class)->resyncFromActiveSubscription((int) $referrer->id);

        return true;
    }

    /**
     * @param  array<string, int>  $featureBonus
     */
    private function reverseBonusOnReferrerSubscription(User $referrer, int $bonusDays, array $featureBonus): bool
    {
        $sub = app(SubscriptionService::class)->getActiveSubscription($referrer);
        if (! $sub || $sub->ends_at === null) {
            return false;
        }

        if ($bonusDays > 0) {
            $floor = $sub->starts_at ?? now();
            $sub->ends_at = $sub->ends_at->copy()->subDays($bonusDays);
            if ($sub->ends_at->lessThan($floor)) {
                $sub->ends_at = $floor->copy();
            }
        }

        if ($featureBonus !== []) {
            $meta = is_array($sub->meta) ? $sub->meta : [];
            $carry = is_array($meta['carry_quota'] ?? null) ? $meta['carry_quota'] : [];
            foreach ($featureBonus as $featureKey => $inc) {
                $carry[$featureKey] = max(0, (int) ($carry[$featureKey] ?? 0) - max(0, (int) $inc));
            }
            $meta['carry_quota'] = $carry;
            $sub->meta = $meta;
        }

        $sub->save();
        app(EntitlementService::class)->resyncFromActiveSubscription((int) $referrer->id);

        return true;
    }

    /**
     * @param  array<string, int>  $featureBonus
     * @return array<string, int>
     */
    private function normalizeFeatureBonusMap(array $featureBonus): array
    {
        $out = [];
        $normalizer = app(FeatureUsageService::class);
        foreach ($featureBonus as $key => $value) {
            $inc = max(0, (int) $value);
            if ($inc <= 0) {
                continue;
            }
            try {
                $normalized = $normalizer->normalizeFeatureKey((string) $key);
            } catch (InvalidArgumentException) {
                continue;
            }
            $out[$normalized] = ($out[$normalized] ?? 0) + $inc;
        }

        return $out;
    }

    /**
     * @return array{bonus_days:int, feature_bonus:array<string,int>}
     */
    private function resolveRewardForPlan(Plan $plan): array
    {
        $slug = strtolower((string) $plan->slug);
        $base = preg_replace('/(?:[_-])(male|female|all)$/', '', $slug) ?? $slug;

        $dbRule = ReferralRewardRule::query()
            ->where('is_active', true)
            ->whereIn('plan_slug', [$slug, $base])
            ->orderByRaw('CASE WHEN plan_slug = ? THEN 0 ELSE 1 END', [$slug])
            ->first();

        if ($dbRule) {
            $normalizer = app(FeatureUsageService::class);
            $featureBonus = [];
            $raw = [
                'chat_send_limit' => (int) $dbRule->chat_send_limit_bonus,
                'contact_view_limit' => (int) $dbRule->contact_view_limit_bonus,
                'interest_send_limit' => (int) $dbRule->interest_send_limit_bonus,
                'daily_profile_view_limit' => (int) $dbRule->daily_profile_view_limit_bonus,
                'who_viewed_me_preview_limit' => (int) $dbRule->who_viewed_me_preview_limit_bonus,
            ];
            foreach ($raw as $k => $inc) {
                if ($inc <= 0) {
                    continue;
                }
                try {
                    $normalized = $normalizer->normalizeFeatureKey($k);
                } catch (InvalidArgumentException) {
                    continue;
                }
                $featureBonus[$normalized] = ($featureBonus[$normalized] ?? 0) + $inc;
            }

            return [
                'bonus_days' => max(0, (int) $dbRule->bonus_days),
                'feature_bonus' => $featureBonus,
            ];
        }

        $daysMap = (array) config('referral.rewards_by_plan_slug', []);
        $featureMap = (array) config('referral.feature_bonus_by_plan_slug', []);

        $bonusDays = (int) ($daysMap[$slug] ?? $daysMap[$base] ?? 0);
        if ($bonusDays <= 0) {
            $featureDays = $plan->featureValue('referral_bonus_days');
            if (is_string($featureDays) && is_numeric($featureDays)) {
                $bonusDays = max(0, (int) $featureDays);
            }
        }

        $rawFeatureBonus = $featureMap[$slug] ?? $featureMap[$base] ?? [];
        $featureBonus = [];
        if (is_array($rawFeatureBonus)) {
            $normalizer = app(FeatureUsageService::class);
            foreach ($rawFeatureBonus as $k => $v) {
                $inc = max(0, (int) $v);
                if ($inc <= 0 || ! is_string($k) || $k === '') {
                    continue;
                }
                try {
                    $normalized = $normalizer->normalizeFeatureKey($k);
                } catch (InvalidArgumentException) {
                    continue;
                }
                $featureBonus[$normalized] = ($featureBonus[$normalized] ?? 0) + $inc;
            }
        }

        return [
            'bonus_days' => max(0, $bonusDays),
            'feature_bonus' => $featureBonus,
        ];
    }

    private function isReferredCheckoutEligible(User $buyer): bool
    {
        if (! $this->isEngineEnabled()) {
            return false;
        }

        $cfg = $this->referredCheckoutConfig();
        if (! $cfg['enabled']) {
            return false;
        }

        if ($cfg['percent_off'] <= 0 && $cfg['extra_days'] <= 0) {
            return false;
        }

        $row = UserReferral::query()->where('referred_user_id', $buyer->id)->first();
        if (! $row || ! $row->isReferredBuyerBenefitEligible() || $row->referred_checkout_bonus_used_at !== null) {
            return false;
        }

        return ! $this->buyerHasPriorPaidSubscription($buyer);
    }

    /**
     * @return array{enabled: bool, percent_off: int, extra_days: int}
     */
    /**
     * Global referred-buyer checkout defaults (admin overrides env config).
     *
     * @return array{enabled: bool, percent_off: int, extra_days: int}
     */
    private function referredCheckoutConfig(): array
    {
        $raw = (array) config('referral.referred_checkout', []);

        return [
            'enabled' => $this->getBoolSetting(
                self::ENGINE_KEYS['referred_checkout_enabled'],
                (bool) ($raw['enabled'] ?? true),
            ),
            'percent_off' => max(0, min(100, $this->getIntSetting(
                self::ENGINE_KEYS['referred_checkout_percent'],
                (int) ($raw['percent_off'] ?? 0),
            ))),
            'extra_days' => max(0, $this->getIntSetting(
                self::ENGINE_KEYS['referred_checkout_extra_days'],
                (int) ($raw['extra_days'] ?? 0),
            )),
        ];
    }

    /**
     * Effective invite checkout benefit for a plan (global + optional plan rule override).
     *
     * @return array{enabled: bool, percent_off: int, extra_days: int}
     */
    private function referredCheckoutBenefitForPlan(Plan $plan): array
    {
        $cfg = $this->referredCheckoutConfig();
        if (! $cfg['enabled']) {
            return ['enabled' => false, 'percent_off' => 0, 'extra_days' => 0];
        }

        $rule = ReferralRewardRule::query()->where('plan_slug', (string) $plan->slug)->first();
        if ($rule?->referred_checkout_excluded) {
            return ['enabled' => false, 'percent_off' => 0, 'extra_days' => 0];
        }

        $percent = $cfg['percent_off'];
        $extraDays = $cfg['extra_days'];
        if ($rule !== null) {
            if ($rule->referred_checkout_percent_off !== null) {
                $percent = max(0, min(100, (int) $rule->referred_checkout_percent_off));
            }
            if ($rule->referred_checkout_extra_days !== null) {
                $extraDays = max(0, (int) $rule->referred_checkout_extra_days);
            }
        }

        if ($percent <= 0 && $extraDays <= 0) {
            return ['enabled' => false, 'percent_off' => 0, 'extra_days' => 0];
        }

        return [
            'enabled' => true,
            'percent_off' => $percent,
            'extra_days' => $extraDays,
        ];
    }

    private function buyerHasPriorPaidSubscription(User $buyer): bool
    {
        return $this->countBuyerPaidSubscriptions($buyer) >= 1;
    }

    private function countBuyerPaidSubscriptions(User $buyer): int
    {
        $count = 0;
        $subs = Subscription::query()
            ->where('user_id', $buyer->id)
            ->with('plan:id,slug')
            ->get();

        foreach ($subs as $sub) {
            $slug = (string) ($sub->plan?->slug ?? '');
            if ($slug !== '' && ! Plan::isFreeCatalogSlug($slug)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{utm_source: string, utm_medium: string, utm_campaign: string, utm_content: string}
     */
    private function defaultUtmParams(string $channel): array
    {
        $cfg = (array) config('referral.growth.utm', []);
        $channel = $this->sanitizeUtmValue($channel, 'link');

        return [
            'utm_source' => $this->sanitizeUtmValue((string) ($cfg['source'] ?? 'member_referral'), 'member_referral'),
            'utm_medium' => $channel === 'whatsapp' ? 'whatsapp' : 'share',
            'utm_campaign' => $this->sanitizeUtmValue((string) ($cfg['campaign'] ?? 'invite'), 'invite'),
            'utm_content' => $channel,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @return array{utm_source: string|null, utm_medium: string|null, utm_campaign: string|null, utm_content: string|null}
     */
    private function normalizeRegistrationAttribution(?array $input): array
    {
        $defaults = $this->defaultUtmParams('direct');
        $input = is_array($input) ? $input : [];

        return [
            'utm_source' => $this->sanitizeUtmValue((string) ($input['utm_source'] ?? $defaults['utm_source']), $defaults['utm_source']),
            'utm_medium' => $this->sanitizeUtmValue((string) ($input['utm_medium'] ?? $defaults['utm_medium']), $defaults['utm_medium']),
            'utm_campaign' => $this->sanitizeUtmValue((string) ($input['utm_campaign'] ?? $defaults['utm_campaign']), $defaults['utm_campaign']),
            'utm_content' => $this->sanitizeUtmValue((string) ($input['utm_content'] ?? $defaults['utm_content']), $defaults['utm_content']),
        ];
    }

    private function sanitizeUtmValue(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_\-]/', '', $value) ?? '';

        return $value !== '' ? substr($value, 0, 64) : substr($fallback, 0, 64);
    }

    private function renewalMicroBonusEnabled(): bool
    {
        return $this->getBoolSetting(
            self::ENGINE_KEYS['renewal_micro_bonus_enabled'],
            (bool) config('referral.growth.renewal_micro_bonus.enabled', false),
        );
    }

    private function renewalMicroBonusDays(): int
    {
        return max(0, $this->getIntSetting(
            self::ENGINE_KEYS['renewal_micro_bonus_days'],
            (int) config('referral.growth.renewal_micro_bonus.bonus_days', 1),
        ));
    }

    /** Admin-configured site name matching current locale (EN message → EN name, MR → MR). */
    private function shareMessageSiteName(): string
    {
        return app(SiteIdentityService::class)->siteNameForLocale();
    }

    /**
     * @param  list<string>  $fraudFlags
     */
    private function buildWhatsappShareMessage(string $shareUrl, string $code): string
    {
        $app = $this->shareMessageSiteName();

        $cfg = $this->referredCheckoutConfig();
        $percent = (int) ($cfg['percent_off'] ?? 0);
        $extraDays = (int) ($cfg['extra_days'] ?? 0);

        if ($cfg['enabled'] && ($percent > 0 || $extraDays > 0)) {
            if ($percent > 0 && $extraDays > 0) {
                return __('referrals.whatsapp_share_message_offer_both', [
                    'app' => $app,
                    'link' => $shareUrl,
                    'code' => $code,
                    'percent' => $percent,
                    'days' => $extraDays,
                ]);
            }

            if ($percent > 0) {
                return __('referrals.whatsapp_share_message_offer_percent', [
                    'app' => $app,
                    'link' => $shareUrl,
                    'code' => $code,
                    'percent' => $percent,
                ]);
            }

            return __('referrals.whatsapp_share_message_offer_days', [
                'app' => $app,
                'link' => $shareUrl,
                'code' => $code,
                'days' => $extraDays,
            ]);
        }

        return __('referrals.whatsapp_share_message', [
            'app' => $app,
            'link' => $shareUrl,
            'code' => $code,
        ]);
    }

    private function resolveInitialReviewStatus(array $fraudFlags): string
    {
        if ($fraudFlags !== [] && $this->fraudAutoHoldEnabled()) {
            return UserReferral::REVIEW_PENDING;
        }

        return UserReferral::REVIEW_APPROVED;
    }

    private function fraudAutoHoldEnabled(): bool
    {
        return $this->getBoolSetting(
            self::ENGINE_KEYS['fraud_auto_hold'],
            (bool) config('referral.fraud.auto_hold_on_flags', true),
        );
    }

    private function pendingClaimExpiryDays(): int
    {
        $fromAdmin = $this->getIntSetting(
            self::ENGINE_KEYS['pending_claim_expiry_days'],
            (int) config('referral.pending_claim_expiry_days', 90),
        );

        return max(0, $fromAdmin);
    }

    /**
     * @param  array<string, int>  $featureBonus
     */
    private function holdReferralRewardForQualityGates(
        UserReferral $locked,
        User $buyer,
        Plan $plan,
        int $bonusDays,
        array $featureBonus,
    ): void {
        $flags = $this->assessReferralQualityGates($buyer);

        $locked->forceFill([
            'reward_status' => UserReferral::STATUS_QUALITY_PENDING,
            'pending_plan_id' => $plan->id,
            'pending_reward' => [
                'bonus_days' => max(0, $bonusDays),
                'feature_bonus' => $featureBonus,
                'plan_slug' => (string) $plan->slug,
                'plan_name' => (string) $plan->name,
            ],
            'pending_claim_at' => null,
        ])->save();

        $this->writeLedger([
            'user_referral_id' => $locked->id,
            'referrer_id' => $locked->referrer_id,
            'referred_user_id' => $locked->referred_user_id,
            'action_type' => 'quality_pending_hold',
            'bonus_days' => max(0, $bonusDays),
            'feature_bonus' => $featureBonus !== [] ? $featureBonus : null,
            'reason' => 'Referrer reward held until referred buyer passes quality gates',
            'meta' => [
                'quality_flags' => $flags,
            ],
        ]);
    }

    private function anyQualityGateEnabled(): bool
    {
        return $this->qualityGateRequireProfileActive()
            || $this->qualityGateRequireMobileVerified()
            || $this->qualityGateRequirePhotoApproved()
            || $this->qualityGateCoolingPeriodDays() > 0;
    }

    private function qualityGateRequireProfileActive(): bool
    {
        return $this->getBoolSetting(
            self::ENGINE_KEYS['quality_require_profile_active'],
            (bool) config('referral.quality_gates.require_profile_active', false),
        );
    }

    private function qualityGateRequireMobileVerified(): bool
    {
        return $this->getBoolSetting(
            self::ENGINE_KEYS['quality_require_mobile_verified'],
            (bool) config('referral.quality_gates.require_mobile_verified', false),
        );
    }

    private function qualityGateRequirePhotoApproved(): bool
    {
        return $this->getBoolSetting(
            self::ENGINE_KEYS['quality_require_photo_approved'],
            (bool) config('referral.quality_gates.require_photo_approved', false),
        );
    }

    private function qualityGateCoolingPeriodDays(): int
    {
        return max(0, $this->getIntSetting(
            self::ENGINE_KEYS['quality_cooling_period_days'],
            (int) config('referral.quality_gates.cooling_period_days', 0),
        ));
    }

    private function expirePendingClaimRow(UserReferral $row, string $reason): void
    {
        if ($row->reward_status !== UserReferral::STATUS_PENDING_CLAIM || $row->reward_applied) {
            return;
        }

        $stored = is_array($row->pending_reward) ? $row->pending_reward : [];

        $row->forceFill([
            'reward_status' => UserReferral::STATUS_PENDING_EXPIRED,
            'pending_plan_id' => null,
            'pending_reward' => null,
            'pending_claim_at' => null,
        ])->save();

        $this->writeLedger([
            'user_referral_id' => $row->id,
            'referrer_id' => $row->referrer_id,
            'referred_user_id' => $row->referred_user_id,
            'action_type' => 'pending_claim_expired',
            'bonus_days' => (int) ($stored['bonus_days'] ?? 0),
            'feature_bonus' => isset($stored['feature_bonus']) && is_array($stored['feature_bonus']) ? $stored['feature_bonus'] : null,
            'reason' => $reason,
            'meta' => [
                'expiry_days' => $this->pendingClaimExpiryDays(),
            ],
        ]);
    }

    private function fraudRapidInvitesPerDay(): int
    {
        $raw = AdminSetting::getValue(self::ENGINE_KEYS['fraud_rapid_invites'], '');
        if ($raw !== '' && $raw !== null) {
            return max(0, (int) $raw);
        }

        return max(0, (int) config('referral.fraud.rapid_invites_per_day', 5));
    }

    private function normalizeRegistrationIp(?string $ip): ?string
    {
        $ip = trim((string) $ip);
        if ($ip === '' || strlen($ip) > 45) {
            return null;
        }

        return $ip;
    }

    private function normalizeMobileForCompare(?string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', (string) $mobile) ?? '';

        return strlen($digits) >= 10 ? substr($digits, -10) : '';
    }

    private function isEngineEnabled(): bool
    {
        return $this->getBoolSetting(self::ENGINE_KEYS['enabled'], (bool) config('referral.enabled', true));
    }

    private function getBoolSetting(string $key, bool $default): bool
    {
        return filter_var(AdminSetting::getValue($key, $default ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);
    }

    private function getIntSetting(string $key, int $default): int
    {
        return max(0, (int) AdminSetting::getValue($key, (string) $default));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeLedger(array $payload): void
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        if (! isset($payload['performed_by_admin_id']) && isset($meta['admin_id'])) {
            $payload['performed_by_admin_id'] = (int) $meta['admin_id'];
        }

        try {
            ReferralRewardLedger::query()->create($payload);
        } catch (\Throwable $e) {
            Log::warning('Referral ledger write failed', [
                'error' => $e->getMessage(),
                'action_type' => (string) ($payload['action_type'] ?? 'unknown'),
                'referrer_id' => (int) ($payload['referrer_id'] ?? 0),
            ]);
        }
    }
}
