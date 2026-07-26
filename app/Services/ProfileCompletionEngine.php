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
     * Drop memoized {@see for()} / {@see forProfile()} results for a user (e.g. after profile save —
     * see {@see \App\Observers\MatrimonyProfileObserver}).
     */
    public function forgetRequestCacheForUser(int $userId): void
    {
        unset($this->requestCache[$userId], $this->requestCache['profile_completion_'.$userId]);
    }

    /**
     * Same, for the user-less key {@see forProfile()} falls back to (Suchak-created profiles with no
     * account). Paired with the observer's `Cache::forget('profile_completion_profile_…')`.
     */
    public function forgetRequestCacheForProfile(int $profileId): void
    {
        unset($this->requestCache['profile_completion_profile_'.$profileId]);
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

        return $this->requestCache[$key] = $this->remember('profile_completion_'.$user->id, $profile);
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

        return $this->remember(
            $uid > 0 ? 'profile_completion_'.$uid : 'profile_completion_profile_'.$profile->id,
            $profile
        );
    }

    /**
     * Shared read path: in-request memo in front of the shared cache.
     *
     * `Cache::remember()` is not free — the production store is `database`, so every call was a
     * `cache` table SELECT. The matching feed asks for the same profile's completion repeatedly
     * (once per relaxation-ladder tier, plus the boost layer), for values that cannot change
     * mid-request. Same value, same cache semantics, just not re-fetched within one request.
     *
     * @return array<string, mixed>
     */
    private function remember(string $cacheKey, MatrimonyProfile $profile): array
    {
        if (array_key_exists($cacheKey, $this->requestCache)) {
            return $this->requestCache[$cacheKey];
        }

        return $this->requestCache[$cacheKey] = Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => $this->computeForProfile($profile)
        );
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

        return [
            'mandatory_core' => $mandatory,
            'detailed' => $detailed,
            'score' => $this->calculateScore($mandatory, $detailed),
            'is_mandatory_complete' => $mandatory >= 100,
            'is_detailed_complete' => $detailed >= 100,
            // {@see ProfileCompletenessService::breakdown()} is literally
            // `['core' => percentage(), 'detailed' => detailedPercentage()]`, so calling it here ran
            // the whole section sweep a second time — every field-config read, every section COUNT and
            // the education/address lookups behind them, twice per profile. Same two numbers, reused.
            'breakdown' => ['core' => $mandatory, 'detailed' => $detailed],
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

    /**
     * Single read path for section status chips used in wizard/edit flows.
     *
     * @param  list<string>  $sectionKeys
     * @return array<string, string>
     */
    public function sectionStatuses(MatrimonyProfile $profile, array $sectionKeys): array
    {
        return ProfileCompletionService::getSectionStatuses($profile, $sectionKeys);
    }
}
