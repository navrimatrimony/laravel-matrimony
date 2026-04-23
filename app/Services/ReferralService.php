<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\Plan;
use App\Models\ReferralRewardLedger;
use App\Models\ReferralRewardRule;
use App\Models\User;
use App\Models\UserReferral;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ReferralService
{
    private const ENGINE_KEYS = [
        'enabled' => 'referral_engine_enabled',
        'paid_only' => 'referral_engine_paid_plans_only',
        'min_plan_amount' => 'referral_engine_min_plan_amount',
        'monthly_cap' => 'referral_engine_monthly_cap_per_referrer',
    ];

    /**
     * Record referral at registration when {@code referral_code} matches another user's code.
     */
    public function recordReferralIfEligible(User $newUser, ?string $rawCode): void
    {
        if (! $this->isEngineEnabled()) {
            return;
        }

        $code = strtoupper(trim((string) $rawCode));
        if ($code === '') {
            return;
        }

        $referrer = User::query()->where('referral_code', $code)->where('id', '!=', $newUser->id)->first();
        if (! $referrer) {
            return;
        }

        try {
            UserReferral::query()->firstOrCreate(
                [
                    'referred_user_id' => $newUser->id,
                ],
                [
                    'referrer_id' => $referrer->id,
                    'reward_applied' => false,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('Referral record failed', ['error' => $e->getMessage(), 'user_id' => $newUser->id]);
        }
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
            ->first();

        if (! $row) {
            return;
        }

        DB::transaction(function () use ($row, $bonusDays, $featureBonus, $buyer, $plan) {
            $locked = UserReferral::query()->whereKey($row->id)->lockForUpdate()->first();
            if (! $locked || $locked->reward_applied) {
                return;
            }

            $referrer = User::query()->find($locked->referrer_id);
            if (! $referrer) {
                return;
            }

            $monthlyCap = $this->getIntSetting(self::ENGINE_KEYS['monthly_cap'], 0);
            if ($monthlyCap > 0) {
                $awardedThisMonth = UserReferral::query()
                    ->where('referrer_id', $referrer->id)
                    ->where('reward_applied', true)
                    ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                    ->count();

                if ($awardedThisMonth >= $monthlyCap) {
                    Log::info('Referral reward skipped due to monthly cap', [
                        'referrer_id' => $referrer->id,
                        'referred_user_id' => $buyer->id,
                        'monthly_cap' => $monthlyCap,
                    ]);

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
            }

            $sub = app(SubscriptionService::class)->getActiveSubscription($referrer);
            if ($sub && $sub->ends_at !== null) {
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
            }

            $locked->forceFill(['reward_applied' => true])->save();

            $this->writeLedger([
                'user_referral_id' => $locked->id,
                'referrer_id' => $referrer->id,
                'referred_user_id' => $buyer->id,
                'action_type' => 'auto_applied',
                'bonus_days' => max(0, $bonusDays),
                'feature_bonus' => $featureBonus !== [] ? $featureBonus : null,
                'reason' => 'Purchase reward applied',
                'meta' => [
                    'plan_id' => $plan->id,
                    'plan_slug' => (string) $plan->slug,
                    'plan_name' => (string) $plan->name,
                ],
            ]);

            try {
                if ($bonusDays > 0) {
                    app(NotificationService::class)->notifyReferralReward(
                        $referrer,
                        $buyer,
                        $bonusDays,
                        $plan->name,
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Referral reward notification failed', ['error' => $e->getMessage()]);
            }
        });
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
