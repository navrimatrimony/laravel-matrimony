<?php

namespace App\Services\Matching;

use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Models\UserMatchBehavior;
use App\Support\SchemaPresence;

class MatchingBehaviorScoringService
{
    /**
     * Per-request memo of the admin-configured behaviour weights.
     *
     * {@see MatchingConfigService::getBehaviorWeights()} runs `ensureDefaults()` plus a full table read
     * on every call, and this service is asked once per candidate — the same handful of admin rows,
     * re-read for a pool of hundreds. Config cannot change mid-request.
     *
     * @var array<string, array{weight: int, decay_days: int, is_active: bool}>|null
     */
    private ?array $weightsMemo = null;

    /**
     * Per-request behaviour counts for one seeker: `[actorUserId][action][targetProfileId] => count`.
     *
     * @var array<int, array<string, array<int, int>>>
     */
    private array $countsMemo = [];

    public function __construct(
        protected MatchingConfigService $config,
    ) {}

    /**
     * Bounded adjustment from recent viewer→target behaviors (views, likes, skips, chat).
     */
    public function scoreAdjustment(User $seeker, MatrimonyProfile $candidate): int
    {
        if (! SchemaPresence::hasTable('user_match_behaviors')) {
            return 0;
        }

        $weights = $this->weights();
        if ($weights === []) {
            return 0;
        }

        $cap = $this->config->behaviorMaxPoints();
        $uid = (int) $seeker->id;
        $tid = (int) $candidate->id;

        $counts = $this->countsForSeeker($uid, $weights);

        $total = 0;
        foreach ($weights as $action => $row) {
            if (! ($row['is_active'] ?? false)) {
                continue;
            }
            $w = (int) ($row['weight'] ?? 0);
            if ($w === 0) {
                continue;
            }
            $count = (int) ($counts[(string) $action][$tid] ?? 0);
            if ($count > 0) {
                $total += $w * min(5, $count);
            }
        }

        $total = max(-$cap, min($cap, $total));

        return $total;
    }

    /**
     * @return array<string, array{weight: int, decay_days: int, is_active: bool}>
     */
    private function weights(): array
    {
        if ($this->weightsMemo !== null) {
            return $this->weightsMemo;
        }

        // Kept in the original order: the defaults are seeded before the weights are read.
        $this->config->ensureDefaults();

        return $this->weightsMemo = $this->config->getBehaviorWeights();
    }

    /**
     * One grouped read per active action for the WHOLE of a seeker's behaviour history inside that
     * action's decay window, replacing a `COUNT(*)` per (candidate × action).
     *
     * The result set is bounded by how many profiles the member has actually interacted with, not by
     * the candidate pool — a seeker with no history returns empty maps and every candidate then scores
     * exactly the 0 the per-candidate COUNT used to return.
     *
     * @param  array<string, array{weight: int, decay_days: int, is_active: bool}>  $weights
     * @return array<string, array<int, int>>
     */
    private function countsForSeeker(int $actorUserId, array $weights): array
    {
        if (isset($this->countsMemo[$actorUserId])) {
            return $this->countsMemo[$actorUserId];
        }

        $out = [];
        foreach ($weights as $action => $row) {
            if (! ($row['is_active'] ?? false)) {
                continue;
            }
            if ((int) ($row['weight'] ?? 0) === 0) {
                continue;
            }

            $decay = max(1, (int) ($row['decay_days'] ?? 30));
            $since = now()->subDays($decay);

            $out[(string) $action] = UserMatchBehavior::query()
                ->where('actor_user_id', $actorUserId)
                ->where('action', $action)
                ->where('created_at', '>=', $since)
                ->groupBy('target_profile_id')
                ->selectRaw('target_profile_id, COUNT(*) as aggregate_count')
                ->pluck('aggregate_count', 'target_profile_id')
                ->mapWithKeys(static fn ($count, $targetId): array => [(int) $targetId => (int) $count])
                ->all();
        }

        return $this->countsMemo[$actorUserId] = $out;
    }
}
