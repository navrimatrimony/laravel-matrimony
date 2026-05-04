<?php

namespace App\Services\Location;

use App\Models\Location;
use App\Models\LocationAlias;
use App\Models\LocationOpenPlaceSuggestion;
use App\Models\LocationSuggestionApprovalPattern;
use App\Models\LocationUsageStat;
use App\Models\MatrimonyProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves every FK reference from duplicate {@see Location} onto the canonical row; deletes the duplicate.
 */
class LocationMergeService
{
    public function __construct(
        private readonly LocationHierarchyIntegrityService $integrity,
    ) {}

    /**
     * @throws \RuntimeException
     */
    public function mergeInto(int $sourceLocationId, int $targetLocationId): void
    {
        if ($sourceLocationId <= 0 || $targetLocationId <= 0 || $sourceLocationId === $targetLocationId) {
            throw new \RuntimeException('Invalid merge source or target.');
        }

        DB::transaction(function () use ($sourceLocationId, $targetLocationId): void {
            $source = Location::query()->whereKey($sourceLocationId)->lockForUpdate()->firstOrFail();
            $target = Location::query()->whereKey($targetLocationId)->lockForUpdate()->firstOrFail();

            if ($this->integrity->isInAncestorChain($targetLocationId, $sourceLocationId)) {
                throw new \RuntimeException('Cannot merge an ancestor location into its descendant.');
            }

            foreach (Location::query()->where('parent_id', $sourceLocationId)->lockForUpdate()->cursor() as $child) {
                if ($this->integrity->duplicateSiblingExists($targetLocationId, (string) $child->name, null, (string) $child->type)) {
                    throw new \RuntimeException(
                        'Merge blocked: target already has a child named "'.$child->name.'" with the same type. Rename one side first.'
                    );
                }
                $child->parent_id = $targetLocationId;
                $child->save();
            }

            $this->rewriteProfileForeignKeys($sourceLocationId, $targetLocationId);
            $this->rewritePinCodes($sourceLocationId, $targetLocationId);
            $this->mergeAliases($sourceLocationId, $targetLocationId);
            $this->mergeUsageStats($sourceLocationId, $targetLocationId);
            $this->rewriteMiscForeignKeys($sourceLocationId, $targetLocationId);

            $source->delete();
        });
    }

    private function rewriteProfileForeignKeys(int $sourceId, int $targetId): void
    {
        if (! Schema::hasTable('matrimony_profiles')) {
            return;
        }

        foreach (['location_id', 'birth_city_id', 'native_city_id', 'work_city_id'] as $col) {
            if (Schema::hasColumn('matrimony_profiles', $col)) {
                MatrimonyProfile::query()->where($col, $sourceId)->update([$col => $targetId]);
            }
        }

        if (Schema::hasTable('profile_career') && Schema::hasColumn('profile_career', 'city_id')) {
            DB::table('profile_career')->where('city_id', $sourceId)->update(['city_id' => $targetId]);
        }
    }

    private function rewritePinCodes(int $sourceId, int $targetId): void
    {
        $source = Location::query()->whereKey($sourceId)->first();
        $target = Location::query()->whereKey($targetId)->first();
        if ($source === null || $target === null) {
            return;
        }

        foreach (['pincode', 'latitude', 'longitude'] as $col) {
            if (($target->{$col} ?? null) === null && ($source->{$col} ?? null) !== null) {
                $target->{$col} = $source->{$col};
            }
        }

        $target->save();
    }

    private function mergeAliases(int $sourceId, int $targetId): void
    {
        if (! Schema::hasTable('location_aliases')) {
            return;
        }

        foreach (LocationAlias::query()->where('location_id', $sourceId)->cursor() as $alias) {
            LocationAlias::query()->firstOrCreate(
                [
                    'location_id' => $targetId,
                    'normalized_alias' => $alias->normalized_alias,
                ],
                [
                    'alias' => $alias->alias,
                ]
            );
            $alias->delete();
        }
    }

    private function mergeUsageStats(int $sourceId, int $targetId): void
    {
        if (! Schema::hasTable('location_usage_stats')) {
            return;
        }

        $src = LocationUsageStat::query()->where('location_id', $sourceId)->first();
        $dst = LocationUsageStat::query()->where('location_id', $targetId)->first();

        if ($src === null) {
            return;
        }

        $added = (int) $src->usage_count;
        $last = $src->last_used_at;

        if ($dst === null) {
            LocationUsageStat::query()->create([
                'location_id' => $targetId,
                'usage_count' => $added,
                'last_used_at' => $last,
            ]);
        } else {
            $dst->usage_count = (int) $dst->usage_count + $added;
            if ($last !== null && ($dst->last_used_at === null || $last->greaterThan($dst->last_used_at))) {
                $dst->last_used_at = $last;
            }
            $dst->save();
        }

        $src->delete();
    }

    private function rewriteMiscForeignKeys(int $sourceId, int $targetId): void
    {
        if (Schema::hasTable('location_open_place_suggestions')) {
            LocationOpenPlaceSuggestion::query()->where('resolved_location_id', $sourceId)->update(['resolved_location_id' => $targetId]);
            LocationOpenPlaceSuggestion::query()->where('suggested_parent_id', $sourceId)->update(['suggested_parent_id' => $targetId]);
        }

        if (Schema::hasTable('location_suggestion_approval_patterns')) {
            LocationSuggestionApprovalPattern::query()->where('resolved_location_id', $sourceId)->update(['resolved_location_id' => $targetId]);
            LocationSuggestionApprovalPattern::query()->where('suggested_parent_id', $sourceId)->update(['suggested_parent_id' => $targetId]);
        }
    }
}
