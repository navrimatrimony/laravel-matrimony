<?php

namespace App\Services;

use App\Models\ProfileBoost;
use App\Models\User;
use InvalidArgumentException;

class ProfileBoostService
{
    /**
     * @return ProfileBoost Active row (caller may show ends_at to the member).
     */
    public function startBoost(User $user, int $durationHours, string $source = 'admin'): ProfileBoost
    {
        if (! config('monetization.boost.enabled', true)) {
            throw new InvalidArgumentException('Profile boost is disabled.');
        }

        $hours = max(1, $durationHours);
        $starts = now();

        return ProfileBoost::query()->create([
            'user_id' => $user->id,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHours($hours),
            'source' => $source,
        ]);
    }

    public function hasActiveBoost(int $userId): bool
    {
        return ProfileBoost::query()
            ->where('user_id', $userId)
            ->activeAt()
            ->exists();
    }
}
