<?php

namespace App\Services\Location;

use App\Models\LocationSuggestionApprovalPattern;
use App\Models\LocationOpenPlaceSuggestion;

/**
 * Persists admin-approved outcomes so future identical normalized inputs rank higher automatically.
 */
class LocationSuggestionPatternLearningService
{
    public function recordFromApprovedSuggestion(LocationOpenPlaceSuggestion $suggestion): void
    {
        if ($suggestion->status !== 'approved') {
            return;
        }

        $normalized = trim((string) $suggestion->normalized_input);
        if ($normalized === '') {
            return;
        }

        $pattern = LocationSuggestionApprovalPattern::query()
            ->where('normalized_input', $normalized)
            ->first();

        if ($pattern === null) {
            LocationSuggestionApprovalPattern::query()->create([
                'normalized_input' => $normalized,
                'resolved_city_id' => $suggestion->resolved_city_id,
                'resolved_location_id' => $suggestion->resolved_location_id,
                'suggested_type' => $suggestion->suggested_type,
                'suggested_parent_id' => $suggestion->suggested_parent_id,
                'confirmation_count' => 1,
                'last_confirmed_at' => now(),
            ]);

            return;
        }

        $pattern->resolved_city_id = $suggestion->resolved_city_id ?? $pattern->resolved_city_id;
        $pattern->resolved_location_id = $suggestion->resolved_location_id ?? $pattern->resolved_location_id;
        $pattern->suggested_type = $suggestion->suggested_type ?? $pattern->suggested_type;
        $pattern->suggested_parent_id = $suggestion->suggested_parent_id ?? $pattern->suggested_parent_id;
        $pattern->confirmation_count = (int) $pattern->confirmation_count + 1;
        $pattern->last_confirmed_at = now();
        $pattern->save();
    }
}
