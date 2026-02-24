<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\LocationSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationSearchController extends Controller
{
    public function __construct(
        private LocationSearchService $locationSearchService
    ) {
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'max:100'],
        ]);

        $query = trim($request->input('q'));
        $result = $this->locationSearchService->search($query);
        $results = $result['results'] ?? [];
        $contextDetected = $result['context_detected'] ?? null;

        if ($results !== []) {
            return response()->json([
                'success' => true,
                'data' => $results,
                'no_match' => false,
                'context_detected' => $contextDetected,
            ]);
        }

        $canSuggest = strlen($query) >= 3 && !(strlen($query) === 6 && ctype_digit($query));

        return response()->json([
            'success' => true,
            'data' => [],
            'no_match' => true,
            'can_suggest' => $canSuggest,
            'context_detected' => $contextDetected,
        ]);
    }
}
