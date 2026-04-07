<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use App\Models\UserReferral;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReferralService
{
    /**
     * Record referral at registration when {@code referral_code} matches another user's code.
     */
    public function recordReferralIfEligible(User $newUser, ?string $rawCode): void
    {
        if (! config('referral.enabled', true)) {
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
        if (! config('referral.enabled', true)) {
            return;
        }

        if (strtolower((string) $plan->slug) === 'free') {
            return;
        }

        $slug = strtolower((string) $plan->slug);
        $bonusDays = (int) (config('referral.rewards_by_plan_slug', [])[$slug] ?? 0);
        if ($bonusDays <= 0) {
            return;
        }

        $row = UserReferral::query()
            ->where('referred_user_id', $buyer->id)
            ->where('reward_applied', false)
            ->first();

        if (! $row) {
            return;
        }

        DB::transaction(function () use ($row, $bonusDays, $buyer, $slug, $plan) {
            $locked = UserReferral::query()->whereKey($row->id)->lockForUpdate()->first();
            if (! $locked || $locked->reward_applied) {
                return;
            }

            $referrer = User::query()->find($locked->referrer_id);
            if (! $referrer) {
                return;
            }

            $sub = app(SubscriptionService::class)->getActiveSubscription($referrer);
            if ($sub && $sub->ends_at !== null) {
                $sub->ends_at = $sub->ends_at->copy()->addDays($bonusDays);
                $sub->save();
                app(EntitlementService::class)->resyncFromActiveSubscription((int) $referrer->id);
            }

            $locked->forceFill(['reward_applied' => true])->save();

            try {
                app(NotificationService::class)->notifyReferralReward(
                    $referrer,
                    $buyer,
                    $bonusDays,
                    $plan->name,
                );
            } catch (\Throwable $e) {
                Log::warning('Referral reward notification failed', ['error' => $e->getMessage()]);
            }
        });
    }
}
