<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Location\LocationOpenPlaceSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationSuggestionController extends Controller
{
    public function store(Request $request, LocationOpenPlaceSuggestionService $suggestions): JsonResponse
    {
        $validated = $request->validate([
            'input_text' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        $record = $suggestions->recordOrBumpUsage(
            rawInput: (string) $validated['input_text'],
            suggestedByUserId: (int) $user->id
        );

        if ($record === null) {
            return response()->json([
                'success' => false,
                'message' => 'Suggestion queue is unavailable (run migrations).',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $record->id,
                'input_text' => (string) $record->raw_input,
                'created_at' => optional($record->created_at)?->toISOString(),
                'confidence_score' => $record->confidence_score,
                'suggested_type' => $record->suggested_type,
                'recommended_city_id' => is_array($record->analysis_json)
                    ? ($record->analysis_json['recommended_city_id'] ?? null)
                    : null,
                'recommended_action' => is_array($record->analysis_json)
                    ? ($record->analysis_json['recommended_action'] ?? null)
                    : null,
            ],
        ]);
    }
}

