<?php

namespace App\Services\Matching;

use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Models\UserMatchBehavior;
use Illuminate\Support\Facades\Schema;

class MatchingBehaviorScoringService
{
    public function __construct(
        protected MatchingConfigService $config,
    ) {}

    /**
     * Bounded adjustment from recent viewer→target behaviors (views, likes, skips, chat).
     */
    public function scoreAdjustment(User $seeker, MatrimonyProfile $candidate): int
    {
        if (! Schema::hasTable('user_match_behaviors')) {
            return 0;
        }

        $this->config->ensureDefaults();
        $weights = $this->config->getBehaviorWeights();
        if ($weights === []) {
            return 0;
        }

        $cap = $this->config->behaviorMaxPoints();
        $uid = (int) $seeker->id;
        $tid = (int) $candidate->id;

        $total = 0;
        foreach ($weights as $action => $row) {
            if (! ($row['is_active'] ?? false)) {
                continue;
            }
            $w = (int) ($row['weight'] ?? 0);
            if ($w === 0) {
                continue;
            }
            $decay = max(1, (int) ($row['decay_days'] ?? 30));
            $since = now()->subDays($decay);
            $count = UserMatchBehavior::query()
                ->where('actor_user_id', $uid)
                ->where('target_profile_id', $tid)
                ->where('action', $action)
                ->where('created_at', '>=', $since)
                ->count();
            if ($count > 0) {
                $total += $w * min(5, $count);
            }
        }

        $total = max(-$cap, min($cap, $total));

        return $total;
    }
}
