<?php

namespace App\Services\Location;

use App\Models\City;
use App\Models\Location;
use App\Models\LocationOpenPlaceSuggestion;
use App\Models\LocationSuggestionApprovalPattern;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-analysis when an open-place suggestion row is created: duplicate detection,
 * type/parent hints, confidence, and structured recommendations for the admin queue.
 */
class OpenPlaceSuggestionAnalysisService
{
    public const ENGINE_VERSION = 1;

    private const SCORE_LEARNED_BASE = 0.9;

    private const SCORE_ALIAS = 0.92;

    private const SCORE_EXACT_CITY = 0.88;

    private const SCORE_STRONG_FUZZY = 0.82;

    private const SCORE_LOCATION_EXACT = 0.86;

    public function __construct(
        private readonly LocationService $locationService,
    ) {}

    /**
     * Runs after a new suggestion row is persisted (not on usage bump merges).
     */
    public function enrichNewSuggestion(LocationOpenPlaceSuggestion $suggestion): void
    {
        $normalized = trim((string) $suggestion->normalized_input);
        if ($normalized === '') {
            return;
        }

        $payload = [
            'engine_version' => self::ENGINE_VERSION,
            'duplicate_candidates' => [],
            'recommended_action' => 'review',
            'recommended_city_id' => null,
            'recommended_location_id' => null,
            'confidence_basis' => null,
        ];

        $candidates = [];

        $pattern = LocationSuggestionApprovalPattern::query()
            ->where('normalized_input', $normalized)
            ->first();

        if ($pattern !== null && $pattern->resolved_city_id !== null) {
            $learnedScore = $this->learnedScore((int) $pattern->confirmation_count);
            $city = City::query()->with('taluka.district.state')->find($pattern->resolved_city_id);
            if ($city !== null) {
                $candidates[] = [
                    'kind' => 'city',
                    'id' => (int) $city->id,
                    'name' => (string) $city->name,
                    'score' => round($learnedScore, 4),
                    'reason' => 'learned_pattern',
                    'confirmation_count' => (int) $pattern->confirmation_count,
                ];
                $payload['recommended_action'] = 'map';
                $payload['recommended_city_id'] = (int) $city->id;
                $payload['confidence_basis'] = 'learned_pattern';
            }
        }

        if ($suggestion->resolved_city_id !== null && $suggestion->match_type === 'alias') {
            $city = City::query()->with('taluka.district.state')->find((int) $suggestion->resolved_city_id);
            if ($city !== null) {
                $candidates[] = [
                    'kind' => 'city',
                    'id' => (int) $city->id,
                    'name' => (string) $city->name,
                    'score' => self::SCORE_ALIAS,
                    'reason' => 'city_alias_match',
                ];
                $payload['recommended_action'] = 'map';
                $payload['recommended_city_id'] = (int) $city->id;
                $payload['confidence_basis'] = 'alias';
            }
        }

        $this->appendCityFuzzyCandidates($normalized, $candidates);
        $this->appendLocationCandidates($normalized, $candidates);

        usort($candidates, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']));

        $deduped = [];
        $seenCity = [];
        foreach ($candidates as $row) {
            if (($row['kind'] ?? '') === 'city') {
                $cid = (int) ($row['id'] ?? 0);
                if ($cid <= 0 || isset($seenCity[$cid])) {
                    continue;
                }
                $seenCity[$cid] = true;
            }
            $deduped[] = $row;
            if (count($deduped) >= 12) {
                break;
            }
        }

        $payload['duplicate_candidates'] = array_slice($deduped, 0, 10);

        $bestCityId = null;
        $bestScore = 0.0;
        foreach ($payload['duplicate_candidates'] as $row) {
            if (($row['kind'] ?? '') === 'city' && ($row['score'] ?? 0) > $bestScore) {
                $bestScore = (float) $row['score'];
                $bestCityId = (int) $row['id'];
            }
        }

        if ($payload['recommended_city_id'] === null && $bestCityId !== null && $bestScore >= 0.77) {
            $payload['recommended_action'] = 'map';
            $payload['recommended_city_id'] = $bestCityId;
            if ($payload['confidence_basis'] === null) {
                $payload['confidence_basis'] = 'duplicate_detection';
            }
        }

        $bestLocation = null;
        $bestLocScore = 0.0;
        foreach ($payload['duplicate_candidates'] as $row) {
            if (($row['kind'] ?? '') === 'location' && (float) ($row['score'] ?? 0) > $bestLocScore) {
                $bestLocScore = (float) ($row['score'] ?? 0);
                $bestLocation = $row;
            }
        }

        $suggestedType = $pattern?->suggested_type;
        $suggestedParentId = $pattern?->suggested_parent_id;

        if ($bestLocation !== null) {
            $loc = Location::query()->find((int) $bestLocation['id']);
            if ($loc !== null) {
                $suggestedType = $suggestedType ?? (string) $loc->type;
                $suggestedParentId = $suggestedParentId ?? $loc->parent_id;
                if ($payload['recommended_location_id'] === null && ($bestLocation['score'] ?? 0) >= 0.8) {
                    $payload['recommended_location_id'] = (int) $loc->id;
                }
            }
        }

        $computedConfidence = max(
            (float) ($suggestion->confidence_score ?? 0),
            $bestScore,
            $bestLocation !== null ? (float) ($bestLocation['score'] ?? 0) : 0.0,
        );

        $suggestion->suggested_type = $suggestedType ?? ($suggestion->suggested_type ?? 'city');
        if ($suggestedParentId !== null) {
            $suggestion->suggested_parent_id = (int) $suggestedParentId;
        }

        $suggestion->analysis_json = $payload;
        if ($computedConfidence > (float) ($suggestion->confidence_score ?? 0)) {
            $suggestion->confidence_score = round($computedConfidence, 6);
        }

        $suggestion->saveQuietly();
    }

    private function learnedScore(int $confirmationCount): float
    {
        $bonus = min(0.09, log(1 + max(1, $confirmationCount), 2) * 0.035);

        return min(0.99, self::SCORE_LEARNED_BASE + $bonus);
    }

    /**
     * @param  list<array{kind:string,id:int,name:string,score:float,reason:string,...}>  $candidates
     */
    private function appendCityFuzzyCandidates(string $normalized, array &$candidates): void
    {
        if (! Schema::hasTable('addresses')) {
            return;
        }

        $needle = mb_strtolower($normalized, 'UTF-8');
        $likePrefix = mb_substr($needle, 0, max(1, min(mb_strlen($needle, 'UTF-8'), 4)), 'UTF-8').'%';

        $rows = City::query()
            ->with('taluka.district.state')
            ->where(function ($q) use ($needle, $likePrefix) {
                $q->whereRaw('LOWER(TRIM(name)) = ?', [$needle])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$likePrefix])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%'.$needle.'%']);
            })
            ->orderByRaw('CASE WHEN LOWER(TRIM(name)) = ? THEN 0 ELSE 1 END', [$needle])
            ->limit(40)
            ->get();

        foreach ($rows as $city) {
            $name = (string) $city->name;
            $nameLower = mb_strtolower(trim($name), 'UTF-8');
            if ($needle === $nameLower) {
                $mapped = self::SCORE_EXACT_CITY;
                $reason = 'exact_name';
            } else {
                similar_text($needle, $nameLower, $pct);
                $mapped = max(0.48, min(self::SCORE_STRONG_FUZZY, ((float) $pct / 100) * self::SCORE_STRONG_FUZZY));
                $reason = 'fuzzy_name';
            }

            $candidates[] = [
                'kind' => 'city',
                'id' => (int) $city->id,
                'name' => $name,
                'score' => round($mapped, 4),
                'reason' => $reason,
                'district' => $city->taluka?->district?->name,
                'state' => $city->taluka?->district?->state?->name,
            ];
        }
    }

    /**
     * @param  list<array{kind:string,id:int,name:string,score:float,reason:string,...}>  $candidates
     */
    private function appendLocationCandidates(string $normalized, array &$candidates): void
    {
        if (! Schema::hasTable(Location::geoTable())) {
            return;
        }

        $hits = $this->locationService->search(mb_substr($normalized, 0, 80, 'UTF-8'));
        foreach ($hits as $hit) {
            $nameLower = mb_strtolower((string) ($hit['name'] ?? ''), 'UTF-8');
            $needle = mb_strtolower($normalized, 'UTF-8');
            $sim = $this->scoreTwoStrings($needle, $nameLower);
            if ($sim < 0.4) {
                continue;
            }
            $score = min(0.94, $sim * self::SCORE_LOCATION_EXACT);
            $candidates[] = [
                'kind' => 'location',
                'id' => (int) $hit['id'],
                'name' => (string) ($hit['name'] ?? ''),
                'type' => (string) ($hit['type'] ?? ''),
                'display_label' => (string) ($hit['display_label'] ?? ''),
                'score' => round($score, 4),
                'reason' => $needle === $nameLower ? 'location_exact' : 'location_fuzzy',
            ];
        }
    }

    private function scoreTwoStrings(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        similar_text($a, $b, $pct);

        return max(0.0, min(1.0, (float) $pct / 100));
    }
}
