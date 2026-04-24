<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Single read-side entry for profile completion percentages and breakdown (Phase 2 SSOT).
 * Calculation remains in {@see ProfileCompletenessService}; this engine unifies reads + optional cache.
 *
 * Registered as a singleton so in-request memoization is shared across resolves.
 */
class ProfileCompletionEngine
{
    private const CACHE_TTL_SECONDS = 60;

    /** @var array<int|string, array<string, mixed>> */
    protected array $requestCache = [];

    /**
     * Drop memoized {@see for()} result for a user (e.g. after profile save — see {@see \App\Observers\MatrimonyProfileObserver}).
     */
    public function forgetRequestCacheForUser(int $userId): void
    {
        unset($this->requestCache[$userId]);
    }

    /**
     * @return array{
     *     mandatory_core: int,
     *     detailed: int,
     *     score: int,
     *     is_mandatory_complete: bool,
     *     is_detailed_complete: bool,
     *     breakdown: array{core: int, detailed: int}|array{}
     * }
     */
    public function for(User $user): array
    {
        $key = $user->id;
        if (array_key_exists($key, $this->requestCache)) {
            return $this->requestCache[$key];
        }

        $profile = $user->matrimonyProfile;
        if (! $profile instanceof MatrimonyProfile) {
            $empty = $this->emptyPayload();
            $this->requestCache[$key] = $empty;

            return $empty;
        }

        $cacheKey = 'profile_completion_'.$user->id;
        $result = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, fn () => $this->computeForProfile($profile));
        $this->requestCache[$key] = $result;

        return $result;
    }

    /**
     * @return array{
     *     mandatory_core: int,
     *     detailed: int,
     *     score: int,
     *     is_mandatory_complete: bool,
     *     is_detailed_complete: bool,
     *     breakdown: array{core: int, detailed: int}
     * }
     */
    public function forProfile(MatrimonyProfile $profile): array
    {
        $uid = (int) ($profile->user_id ?? 0);
        $key = $uid > 0 ? 'profile_completion_'.$uid : 'profile_completion_profile_'.$profile->id;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, fn () => $this->computeForProfile($profile));
    }

    /**
     * @return array{
     *     mandatory_core: int,
     *     detailed: int,
     *     score: int,
     *     is_mandatory_complete: bool,
     *     is_detailed_complete: bool,
     *     breakdown: array{core: int, detailed: int}
     * }
     */
    private function computeForProfile(MatrimonyProfile $profile): array
    {
        $mandatory = ProfileCompletenessService::percentage($profile);
        $detailed = ProfileCompletenessService::detailedPercentage($profile);
        $breakdown = ProfileCompletenessService::breakdown($profile);

        return [
            'mandatory_core' => $mandatory,
            'detailed' => $detailed,
            'score' => $this->calculateScore($mandatory, $detailed),
            'is_mandatory_complete' => $mandatory >= 100,
            'is_detailed_complete' => $detailed >= 100,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * @return array{
     *     mandatory_core: int,
     *     detailed: int,
     *     score: int,
     *     is_mandatory_complete: bool,
     *     is_detailed_complete: bool,
     *     breakdown: array{}
     * }
     */
    private function emptyPayload(): array
    {
        return [
            'mandatory_core' => 0,
            'detailed' => 0,
            'score' => 0,
            'is_mandatory_complete' => false,
            'is_detailed_complete' => false,
            'breakdown' => [],
        ];
    }

    private function calculateScore(int $mandatory, int $detailed): int
    {
        return (int) round(($mandatory + $detailed) / 2);
    }
}
