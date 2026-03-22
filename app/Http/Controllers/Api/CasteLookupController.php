<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caste;
use App\Models\SubCaste;
use App\Support\MasterData\ReligionCasteSubcasteSlugger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CasteLookupController extends Controller
{
    /**
     * GET /castes?religion_id=
     * Returns castes for the given religion (is_active=1).
     * `label` is locale-aware display label; `label_en` / `label_mr` always included when present.
     */
    public function getCastes(Request $request): JsonResponse
    {
        $religionId = $request->input('religion_id') ?? $request->route('religionId');
        $request->merge(['religion_id' => $religionId]);
        $request->validate(['religion_id' => ['required', 'exists:religions,id']]);

        $castes = Caste::where('religion_id', $religionId)
            ->where('is_active', true)
            ->orderBy('label')
            ->get(['id', 'label', 'label_en', 'label_mr'])
            ->map(function (Caste $c) {
                return [
                    'id' => $c->id,
                    'label' => $c->display_label,
                    'label_en' => $c->label_en ?? $c->label,
                    'label_mr' => $c->label_mr,
                ];
            });

        return response()->json($castes);
    }

    /**
     * GET /sub-castes?caste_id=&q= or /api/subcastes/{casteId}?q=
     * Requires q length >= 2; status=approved, is_active=1; returns id + label only.
     */
    public function getSubCastes(Request $request): JsonResponse
    {
        try {
            $casteId = $request->input('caste_id') ?? $request->route('casteId');
            $request->merge(['caste_id' => $casteId]);
            $request->validate(['caste_id' => ['required', 'exists:castes,id']]);

            $q = trim((string) $request->input('q', ''));
            if (strlen($q) < 2) {
                return response()->json([]);
            }

            $items = SubCaste::where('caste_id', $casteId)
                ->where('status', 'approved')
                ->where('is_active', true)
                ->where(function ($query) use ($q) {
                    $query->where('label', 'like', '%'.$q.'%')
                        ->orWhere('label_en', 'like', '%'.$q.'%')
                        ->orWhere('label_mr', 'like', '%'.$q.'%');
                })
                ->orderBy('label')
                ->limit(20)
                ->get(['id', 'label', 'label_en', 'label_mr'])
                ->map(function (SubCaste $s) {
                    return [
                        'id' => $s->id,
                        'label' => $s->display_label,
                        'label_en' => $s->label_en ?? $s->label,
                        'label_mr' => $s->label_mr,
                    ];
                });

            return response()->json($items);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    /**
     * POST /sub-castes
     * Create a new sub-caste (status=pending, is_active=0); duplicate under same caste returns existing.
     */
    public function createSubCaste(Request $request, ReligionCasteSubcasteSlugger $slugger): JsonResponse
    {
        $request->validate([
            'caste_id' => ['required', 'exists:castes,id'],
            'label' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        $casteId = (int) $request->input('caste_id');
        $label = $slugger->normalizeLabel($request->input('label'));
        $key = $slugger->makeKey($label);

        $existing = SubCaste::where('caste_id', $casteId)
            ->where(function ($q) use ($key, $label) {
                $q->where('key', $key)->orWhere('label', $label);
            })
            ->first();

        if ($existing) {
            return response()->json(array_merge(
                $existing->only('id', 'label', 'caste_id', 'status'),
                [
                    'label_en' => $existing->label_en ?? $existing->label,
                    'label_mr' => $existing->label_mr,
                ]
            ));
        }

        $subCaste = SubCaste::create([
            'caste_id' => $casteId,
            'key' => $key,
            'label' => $label,
            'label_en' => $label,
            'label_mr' => null,
            'status' => 'pending',
            'created_by_user_id' => auth()->id(),
            'is_active' => false,
        ]);

        return response()->json(array_merge(
            $subCaste->only('id', 'label', 'caste_id', 'status'),
            [
                'label_en' => $subCaste->label_en ?? $subCaste->label,
                'label_mr' => $subCaste->label_mr,
            ]
        ), 201);
    }
}
