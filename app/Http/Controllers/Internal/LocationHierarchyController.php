<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\State;
use App\Models\Taluka;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationHierarchyController extends Controller
{
    /**
     * When `country_ids` is present (array query), return only states for those countries.
     * When omitted, return all states (backward compatible with existing typeahead/modals).
     */
    public function states(Request $request): JsonResponse
    {
        if ($request->has('country_ids')) {
            $ids = $request->input('country_ids', []);
            if (! is_array($ids)) {
                $ids = [];
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            if ($ids === []) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }
            $states = State::whereIn('country_id', $ids)->orderBy('name')->get(['id', 'name', 'country_id']);
        } else {
            $states = State::orderBy('name')->get(['id', 'name', 'country_id']);
        }

        return response()->json([
            'success' => true,
            'data' => $states,
        ]);
    }

    /**
     * Single `state_id` (legacy) or `state_ids` for multi-state filtering.
     */
    public function districts(Request $request): JsonResponse
    {
        if ($request->filled('state_ids')) {
            $request->validate([
                'state_ids' => ['required', 'array', 'min:1'],
                'state_ids.*' => ['integer', 'exists:states,id'],
            ]);
            $ids = array_values(array_unique(array_map('intval', $request->input('state_ids', []))));
            $districts = District::whereIn('state_id', $ids)->orderBy('name')->get(['id', 'name', 'state_id']);

            return response()->json([
                'success' => true,
                'data' => $districts,
            ]);
        }

        $request->validate([
            'state_id' => ['required', 'integer', 'exists:states,id'],
        ]);

        $districts = District::where('state_id', $request->integer('state_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'state_id']);

        return response()->json([
            'success' => true,
            'data' => $districts,
        ]);
    }

    /**
     * Single `district_id` (legacy) or `district_ids` for multi-district filtering.
     */
    public function talukas(Request $request): JsonResponse
    {
        if ($request->filled('district_ids')) {
            $request->validate([
                'district_ids' => ['required', 'array', 'min:1'],
                'district_ids.*' => ['integer', 'exists:districts,id'],
            ]);
            $ids = array_values(array_unique(array_map('intval', $request->input('district_ids', []))));
            $talukas = Taluka::whereIn('district_id', $ids)->orderBy('name')->get(['id', 'name', 'district_id']);

            return response()->json([
                'success' => true,
                'data' => $talukas,
            ]);
        }

        $request->validate([
            'district_id' => ['required', 'integer', 'exists:districts,id'],
        ]);

        $talukas = Taluka::where('district_id', $request->integer('district_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'district_id']);

        return response()->json([
            'success' => true,
            'data' => $talukas,
        ]);
    }
}

