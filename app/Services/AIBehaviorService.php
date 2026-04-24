<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Models\UserMatchBehavior;
use Illuminate\Support\Facades\Schema;

class AIBehaviorService
{
    /**
     * Bounded boost from the actor’s past interest_sent targets vs the candidate profile (education / caste / city).
     */
    public function getBoost(User $actor, MatrimonyProfile $targetProfile): int
    {
        if (! Schema::hasTable('user_match_behaviors')) {
            return 0;
        }

        $ids = UserMatchBehavior::query()
            ->where('actor_user_id', $actor->id)
            ->where('action', 'interest_sent')
            ->pluck('target_profile_id')
            ->unique()
            ->filter(fn ($id) => (int) $id > 0)
            ->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        $profiles = MatrimonyProfile::query()->whereIn('id', $ids)->get();

        $score = 0;

        foreach ($profiles as $p) {
            if ($p->highest_education && $p->highest_education === $targetProfile->highest_education) {
                $score += 5;
            }

            if ($p->caste_id && $p->caste_id === $targetProfile->caste_id) {
                $score += 5;
            }

            if ($p->city_id && $p->city_id === $targetProfile->city_id) {
                $score += 5;
            }
        }

        return min(20, $score);
    }
}
