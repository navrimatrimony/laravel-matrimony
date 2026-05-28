<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserEngagementStats;
use App\Models\UserReferral;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5 engagement SSOT ({@see user_engagement_stats}). Referral counts are synced from {@see UserReferral}.
 */
class UserEngagementStatsService
{
    private const CACHE_TTL_SECONDS = 60;

    /**
     * @return array{
     *     referrals_done: int,
     *     ads_viewed_count: int,
     *     profiles_completed: int,
     *     daily_login_streak: int,
     *     unlock_credits_available: int
     * }
     */
    public function forUser(User $user): array
    {
        if (! Schema::hasTable('user_engagement_stats')) {
            return $this->emptyPayload();
        }

        $cacheKey = 'user_engagement_stats_'.$user->id;

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($user) {
            $row = UserEngagementStats::query()->firstOrCreate(
                ['user_id' => $user->id],
                $this->emptyPayload(),
            );

            return [
                'referrals_done' => max(0, (int) $row->referrals_done),
                'ads_viewed_count' => max(0, (int) $row->ads_viewed_count),
                'profiles_completed' => max(0, (int) $row->profiles_completed),
                'daily_login_streak' => max(0, (int) $row->daily_login_streak),
                'unlock_credits_available' => max(0, (int) $row->unlock_credits_available),
            ];
        });
    }

    public function referralsDoneFor(User $user): int
    {
        return (int) ($this->forUser($user)['referrals_done'] ?? 0);
    }

    /**
     * Recompute referrals_done from user_referrals (idempotent SSOT writer).
     */
    public function syncReferralsDone(User $referrer): void
    {
        if (! Schema::hasTable('user_engagement_stats') || ! Schema::hasTable('user_referrals')) {
            return;
        }

        $count = (int) UserReferral::query()
            ->where('referrer_id', $referrer->id)
            ->where('reward_applied', true)
            ->count();

        UserEngagementStats::query()->updateOrCreate(
            ['user_id' => $referrer->id],
            ['referrals_done' => max(0, $count)],
        );

        Cache::forget('user_engagement_stats_'.$referrer->id);
    }

    /**
     * @return array{referrals_done: int, ads_viewed_count: int, profiles_completed: int, daily_login_streak: int, unlock_credits_available: int}
     */
    private function emptyPayload(): array
    {
        return [
            'referrals_done' => 0,
            'ads_viewed_count' => 0,
            'profiles_completed' => 0,
            'daily_login_streak' => 0,
            'unlock_credits_available' => 0,
        ];
    }
}
