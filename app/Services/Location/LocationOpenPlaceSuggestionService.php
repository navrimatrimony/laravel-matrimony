<?php

namespace App\Services\Location;

use App\Models\LocationOpenPlaceSuggestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records ambiguous place strings (e.g. Wakad), merges duplicate normalized keys, bumps usage for analytics / auto-promote rules.
 *
 * Normalization matches {@see LocationNormalizationService::mergeKeyFromRaw} so keys align with location_aliases lookups.
 */
class LocationOpenPlaceSuggestionService
{
    public const AUTO_APPROVE_USAGE_THRESHOLD = 5;

    public function __construct(
        private readonly LocationNormalizationService $locationNormalization,
        private readonly OpenPlaceSuggestionAnalysisService $analysis,
    ) {}

    /**
     * Merge/dedupe key — punctuation stripped, Unicode-safe (same contract as alias table).
     */
    public function normalizeRawInput(string $raw): string
    {
        return $this->locationNormalization->mergeKeyFromRaw($raw);
    }

    /**
     * @param  array{country_id?: int|null, state_id?: int|null, district_id?: int|null, taluka_id?: int|null}  $optionalHierarchy
     */
    public function recordOrBumpUsage(
        string $rawInput,
        int $suggestedByUserId,
        array $optionalHierarchy = [],
        string $matchType = 'none',
        ?float $confidenceScore = null,
    ): ?LocationOpenPlaceSuggestion {
        if (! Schema::hasTable((new LocationOpenPlaceSuggestion)->getTable())) {
            return null;
        }

        $normalized = $this->locationNormalization->mergeKeyFromRaw($rawInput);
        $aliasHit = $this->locationNormalization->normalizeFromText($rawInput);

        return DB::transaction(function () use ($rawInput, $normalized, $suggestedByUserId, $optionalHierarchy, $matchType, $confidenceScore, $aliasHit) {
            $existing = LocationOpenPlaceSuggestion::query()
                ->where('normalized_input', $normalized)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->increment('usage_count');
                $existing->touch();
                $existing->refresh();

                return $existing;
            }

            $resolved = $aliasHit['matched'];
            $countryId = $optionalHierarchy['country_id'] ?? ($resolved ? $aliasHit['country_id'] : null);
            $stateId = $optionalHierarchy['state_id'] ?? ($resolved ? $aliasHit['state_id'] : null);
            $districtId = $optionalHierarchy['district_id'] ?? ($resolved ? $aliasHit['district_id'] : null);
            $talukaId = $optionalHierarchy['taluka_id'] ?? ($resolved ? $aliasHit['taluka_id'] : null);
            $resolvedCityId = $resolved ? $aliasHit['city_id'] : null;
            $resolvedLocationId = null;
            if ($resolved && $resolvedCityId === null && ! empty($aliasHit['location_id'])) {
                $resolvedLocationId = (int) $aliasHit['location_id'];
            }

            $effectiveMatch = $resolved ? 'alias' : $matchType;
            $effectiveConfidence = $resolved ? $aliasHit['confidence'] : $confidenceScore;

            $created = LocationOpenPlaceSuggestion::query()->create([
                'raw_input' => $rawInput,
                'normalized_input' => $normalized,
                'country_id' => $countryId,
                'state_id' => $stateId,
                'district_id' => $districtId,
                'taluka_id' => $talukaId,
                'resolved_city_id' => $resolvedCityId,
                'resolved_location_id' => $resolvedLocationId,
                'match_type' => $effectiveMatch,
                'confidence_score' => $effectiveConfidence,
                'status' => 'pending',
                'usage_count' => 1,
                'suggested_by' => $suggestedByUserId,
            ]);

            $created->refresh();
            $this->analysis->enrichNewSuggestion($created);

            return $created->fresh();
        });
    }
}
