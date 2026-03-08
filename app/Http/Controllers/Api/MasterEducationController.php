<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EducationCategory;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/master/education
 * Returns Shaadi.com-style education hierarchy: category name => [ { code, full_form }, ... ].
 */
class MasterEducationController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = EducationCategory::where('is_active', true)
            ->with(['degrees' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        $result = [];
        foreach ($categories as $cat) {
            $result[$cat->name] = $cat->degrees->map(fn ($d) => [
                'code' => $d->code,
                'full_form' => $d->full_form,
            ])->values()->all();
        }

        return response()->json($result);
    }
}
