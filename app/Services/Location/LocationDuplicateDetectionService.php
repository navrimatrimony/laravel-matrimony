<?php

namespace App\Services\Location;

use App\Models\Location;
use App\Models\LocationAlias;
use Illuminate\Support\Facades\Schema;

/**
 * Admin assist: surface likely duplicate {@see Location} rows (name + alias similarity).
 */
class LocationDuplicateDetectionService
{
    /**
     * @return list<array{id:int,name:string,type:string,parent_id:int|null,reason:string,score:float}>
     */
    public function findSimilar(Location $location, int $limit = 15): array
    {
        $needle = mb_strtolower(trim($location->name), 'UTF-8');
        if ($needle === '') {
            return [];
        }

        $like = '%'.addcslashes($needle, '%_\\').'%';

        $query = Location::query()
            ->where('id', '!=', $location->id)
            ->where(function ($q) use ($needle, $like) {
                $q->where(function ($q2) use ($needle, $like) {
                    $q2->whereRaw('LOWER(TRIM(name)) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(TRIM(name)) = ?', [$needle]);
                });
                if (Schema::hasTable('location_aliases')) {
                    $q->orWhereHas('aliases', function ($qa) use ($like, $needle) {
                        $qa->where('normalized_alias', 'like', $like)
                            ->orWhere('normalized_alias', $needle);
                    });
                }
            });

        $candidates = $query->limit(max(50, $limit * 4))->get();

        $scored = [];
        foreach ($candidates as $cand) {
            if ((int) $cand->id === (int) $location->id) {
                continue;
            }
            $score = $this->scorePair($needle, mb_strtolower(trim((string) $cand->name), 'UTF-8'));
            $reason = 'name_similarity';
            if ($score < 0.42) {
                continue;
            }

            $aliasBoost = $this->aliasOverlapScore($location, $cand);
            if ($aliasBoost > 0) {
                $score = min(1.0, $score + $aliasBoost * 0.25);
                $reason = 'alias_or_name';
            }

            $scored[] = [
                'id' => (int) $cand->id,
                'name' => (string) $cand->name,
                'type' => (string) $cand->type,
                'parent_id' => $cand->parent_id !== null ? (int) $cand->parent_id : null,
                'reason' => $reason,
                'score' => round(min(1.0, $score), 4),
            ];
        }

        usort($scored, static fn ($a, $b) => ($b['score'] <=> $a['score']));

        return array_slice($scored, 0, $limit);
    }

    private function scorePair(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        similar_text($a, $b, $pct);

        return max(0.0, min(1.0, (float) $pct / 100));
    }

    private function aliasOverlapScore(Location $a, Location $b): float
    {
        if (! Schema::hasTable('location_aliases')) {
            return 0.0;
        }

        $setA = LocationAlias::query()->where('location_id', $a->id)->pluck('normalized_alias')->all();
        $setB = LocationAlias::query()->where('location_id', $b->id)->pluck('normalized_alias')->all();
        if ($setA === [] || $setB === []) {
            return 0.0;
        }

        $intersect = count(array_intersect($setA, $setB));

        return $intersect > 0 ? min(1.0, $intersect / max(count($setA), count($setB))) : 0.0;
    }
}
