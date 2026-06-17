<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Religion;
use Illuminate\Http\JsonResponse;

class ReligionLookupController extends Controller
{
    /**
     * GET /api/v1/religions
     * Returns active religions for authenticated member clients.
     */
    public function index(): JsonResponse
    {
        $religions = Religion::where('is_active', true)
            ->orderBy('label')
            ->get(['id', 'label', 'label_en', 'label_mr'])
            ->map(function (Religion $religion) {
                return [
                    'id' => $religion->id,
                    'label' => $religion->display_label,
                    'label_en' => $religion->label_en ?? $religion->label,
                    'label_mr' => $religion->label_mr,
                ];
            });

        return response()->json($religions);
    }
}
