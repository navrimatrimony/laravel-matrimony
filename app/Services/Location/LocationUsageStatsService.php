<?php

namespace App\Services\Location;

use App\Models\Location;
use App\Models\LocationUsageStat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Popularity signals when canonical {@see Location} ids appear on profiles (city_id holds locations.id in newer flows).
 */
class LocationUsageStatsService
{
    public function recordUse(int $locationId): void
    {
        if ($locationId <= 0 || ! Schema::hasTable('location_usage_stats')) {
            return;
        }

        if (! Location::query()->whereKey($locationId)->exists()) {
            return;
        }

        DB::transaction(function () use ($locationId): void {
            $row = LocationUsageStat::query()->where('location_id', $locationId)->lockForUpdate()->first();
            if ($row === null) {
                LocationUsageStat::query()->create([
                    'location_id' => $locationId,
                    'usage_count' => 1,
                    'last_used_at' => now(),
                ]);

                return;
            }

            $row->increment('usage_count');
            $row->forceFill(['last_used_at' => now()]);
            $row->save();
        });
    }

    /**
     * @param  list<int>  $locationIds
     */
    public function recordUses(array $locationIds): void
    {
        foreach (array_unique(array_filter($locationIds)) as $id) {
            $this->recordUse((int) $id);
        }
    }
}
