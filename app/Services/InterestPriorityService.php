<?php

namespace App\Services;

use App\Models\Interest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

/**
 * Stamps {@see \App\Models\Interest::priority_score} when sending.
 * Paid (non–free-plan active subscription) senders rank above free users in received lists.
 *
 * Future: multiply {@see self::baseScoreForSender} by a profile/plan boost factor before persisting.
 */
class InterestPriorityService
{
    public function baseScoreForSender(User $user): int
    {
        if ($user->isAnyAdmin()) {
            return Interest::PRIORITY_SCORE_PAID;
        }

        $sub = Subscription::queryAuthoritativeAccessForUser($user)->first();

        if (! $sub) {
            return Interest::PRIORITY_SCORE_FREE;
        }

        $plan = $sub->plan()->first();
        if (! $plan instanceof Plan) {
            return Interest::PRIORITY_SCORE_FREE;
        }

        return $this->isFreePlan($plan) ? Interest::PRIORITY_SCORE_FREE : Interest::PRIORITY_SCORE_PAID;
    }

    private function isFreePlan(Plan $plan): bool
    {
        return Plan::isFreeCatalogSlug((string) $plan->slug);
    }
}
