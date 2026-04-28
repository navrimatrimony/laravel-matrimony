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
        $mr = app()->getLocale() === 'mr';

        return response()->json([
            'results' => $result['degrees']->map(function ($d) use ($mr) {
                $cat = $d->category;
                $name = ($mr && filled($d->title_mr)) ? $d->title_mr : $d->title;
                $category = $mr && $cat && filled($cat->name_mr) ? $cat->name_mr : optional($cat)->name;

                return [
                    'id' => $d->id,
                    'name' => $name,
                    'category' => $category,
                ];
            })->values()->all(),
            'suggestion' => $result['suggestion'],
        ]);
    }
}
