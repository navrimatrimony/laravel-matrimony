<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EducationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/education-degrees/search?q=… — {@see \App\Models\EducationDegree} master rows only.
 *
 * Response: { "results": [ { id, name, category } ], "suggestion": string|null }
 */
class EducationDegreeSearchController extends Controller
{
    public function __invoke(Request $request, EducationService $educationService): JsonResponse
    {
        $q = (string) $request->query('q', '');
        $result = $educationService->searchDegreesWithSuggestion($q, 60);

        return response()->json([
            'results' => $result['degrees']->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->title,
                'category' => optional($d->category)->name,
            ])->values()->all(),
            'suggestion' => $result['suggestion'],
        ]);
    }
}
