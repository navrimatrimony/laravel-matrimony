<?php

namespace App\Services;

use App\Models\MatchBoostSetting;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Applies admin-configured boosts on top of rule-based match score (activity, premium tier, similarity, optional AI).
 */
class MatchBoostService
{
    private const PAIR_CACHE_TTL_SECONDS = 86400;

    public function __construct(
        protected AiBoostService $aiBoost,
        protected SubscriptionService $subscriptions,
    ) {}

    /**
     * @param  User  $userA  Seeker (viewer context)
     * @param  User  $userB  Candidate profile owner
     */
    public function applyBoost(User $userA, User $userB, int $baseScore): int
    {
        $baseScore = max(0, min(100, $baseScore));

        $profileA = $userA->matrimonyProfile;
        $profileB = $userB->matrimonyProfile;
        if (! $profileA || ! $profileB) {
            return $baseScore;
        }

        $settings = MatchBoostSetting::current();
        $ver = (string) ($settings->updated_at?->timestamp ?? '0');
        $id1 = min($profileA->id, $profileB->id);
        $id2 = max($profileA->id, $profileB->id);
        $pairKey = 'match_boost_added:'.$id1.':'.$id2.':'.$ver;

        $added = Cache::remember($pairKey, self::PAIR_CACHE_TTL_SECONDS, function () use ($settings, $profileA, $profileB, $userB): int {
            return $this->computeAddedBoost($settings, $profileA, $profileB, $userB);
        });

        return max(0, min(100, $baseScore + $added));
    }

    private function computeAddedBoost(MatchBoostSetting $settings, MatrimonyProfile $profileA, MatrimonyProfile $profileB, User $userB): int
    {
        $rule = 0;

        $days = max(1, (int) $settings->active_within_days);
        $seen = $userB->last_seen_at;
        if ($seen && $seen->greaterThanOrEqualTo(now()->subDays($days))) {
            $rule += (int) $settings->boost_active_weight;
        }

        $rule += $this->tierBoostForUser($settings, $userB);

        if ($this->profilesSimilarityHit($profileA, $profileB)) {
            $rule += (int) $settings->boost_similarity_weight;
        }

        $ai = 0;
        if ($settings->use_ai && strtolower((string) ($settings->ai_provider ?? '')) === 'sarvam') {
            $ai = $this->aiBoost->getBoostScore($profileA, $profileB);
        }

        $cap = max(0, (int) $settings->max_boost_limit);
        $combined = $rule + $ai;

        return min($cap, $combined);
    }

    private function tierBoostForUser(MatchBoostSetting $settings, User $userB): int
    {
        if ($userB->isAnyAdmin()) {
            return 0;
        }

        $plan = $this->subscriptions->getEffectivePlan($userB);
        $slug = strtolower(trim((string) ($plan->slug ?? '')));
        if ($slug === '' || Plan::isFreeCatalogSlug($slug)) {
            return 0;
        }

        $add = (int) $settings->boost_premium_weight;
        if (str_contains($slug, 'gold')) {
            $add += (int) $settings->boost_gold_extra;
        } elseif (str_contains($slug, 'silver')) {
            $add += (int) $settings->boost_silver_extra;
        }

        return $add;
    }

    private function profilesSimilarityHit(MatrimonyProfile $a, MatrimonyProfile $b): bool
    {
        $a->loadMissing(['profession']);
        $b->loadMissing(['profession']);

        $pA = (int) ($a->profession_id ?? 0);
        $pB = (int) ($b->profession_id ?? 0);
        if ($pA > 0 && $pA === $pB) {
            return true;
        }

        $cA = (int) ($a->city_id ?? 0);
        $cB = (int) ($b->city_id ?? 0);
        if ($cA > 0 && $cA === $cB) {
            return true;
        }

        $sA = (int) ($a->state_id ?? 0);
        $sB = (int) ($b->state_id ?? 0);

        return $sA > 0 && $sA === $sB;
    }
}
