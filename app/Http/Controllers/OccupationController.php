<?php

namespace App\Http\Controllers;

use App\Models\OccupationMaster;
use App\Services\OccupationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Occupation picker API (Tom Select): search matches {@see EducationDegreeSearchController} shape.
 *
 * POST /api/occupations/create — registered under web middleware (session + CSRF) for Blade fetch.
 */
class OccupationController extends Controller
{
    /**
     * GET /api/occupations/search?q=
     *
     * @return JsonResponse array{results: list<array{id:int,name:string,category:?string}>, suggestion: ?string}
     */
    public function search(Request $request, OccupationService $service): JsonResponse
    {
        $result = $service->search((string) $request->query('q', ''), 10);

        return response()->json([
            'results' => $result['occupations']->map(fn ($o) => [
                'id' => $o->id,
                'name' => $o->name,
                'category' => optional($o->category)->name,
            ])->values()->all(),
            'suggestion' => $result['suggestion'],
        ]);
    }

    /**
     * GET /api/occupations/category/{occupation_master}
     */
    public function category(OccupationMaster $occupation_master, OccupationService $service): JsonResponse
    {
        return response()->json($service->getCategoryPayload($occupation_master));
    }

    /**
     * POST /api/occupations/create — body: { "name": "..." }
     *
     * Response matches a single search row + temporary flag (education search row shape).
     *
     * @return JsonResponse array{id:int,name:string,category:?string,temporary:bool}
     */
    public function create(Request $request, OccupationService $service): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
        ]);

        $user = $request->user();
        $out = $service->createCustom($validated['name'], (int) $user->id);

        return response()->json([
            'id' => $out['id'],
            'name' => $out['name'],
            'category' => null,
            'temporary' => true,
        ]);
    }
}
