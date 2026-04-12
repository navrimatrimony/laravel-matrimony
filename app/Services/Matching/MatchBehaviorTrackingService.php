<?php

namespace App\Services\Matching;

use App\Models\User;
use App\Models\UserMatchBehavior;
use Illuminate\Support\Facades\Schema;

/**
 * Persists lightweight behavior rows for the matching engine (best-effort, non-blocking for core flows).
 */
class MatchBehaviorTrackingService
{
    public static function record(?User $actor, int $targetProfileId, string $action, ?array $meta = null): void
    {
        if (! $actor || ! Schema::hasTable('user_match_behaviors')) {
            return;
        }
        if ($targetProfileId <= 0 || ! in_array($action, ['view', 'like', 'skip', 'chat'], true)) {
            return;
        }

        UserMatchBehavior::query()->create([
            'actor_user_id' => $actor->id,
            'target_profile_id' => $targetProfileId,
            'action' => $action,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }
}
