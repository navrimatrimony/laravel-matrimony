<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\District;
use App\Models\State;
use App\Models\Taluka;
use App\Support\Validation\AddressHierarchyRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationHierarchyController extends Controller
{
    /**
     * States (addresses.type = state).
     * Optional {@code parent_ids[]} lists country row ids (parents of states); when omitted, returns all states.
     */
    public function states(Request $request): JsonResponse
    {
        if ($request->has('parent_ids')) {
            $ids = $request->input('parent_ids', []);
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
            $request->validate([
                'parent_ids' => ['required', 'array', 'min:1'],
                'parent_ids.*' => ['integer', AddressHierarchyRules::existsCountryId()],
            ]);
            $states = State::whereIn('parent_id', $ids)->orderBy('name')->get(['id', 'name', 'parent_id']);
        } else {
            $states = State::orderBy('name')->get(['id', 'name', 'parent_id']);
        }

        return response()->json([
            'success' => true,
            'data' => $states,
        ]);
    }

    /**
     * Districts under one or more state rows. Parent id(s) must be {@code addresses} rows with type=state.
     */
    public function districts(Request $request): JsonResponse
    {
        if ($request->filled('parent_ids')) {
            $request->validate([
                'parent_ids' => ['required', 'array', 'min:1'],
                'parent_ids.*' => ['integer', AddressHierarchyRules::existsStateId()],
            ]);
            $ids = array_values(array_unique(array_map('intval', $request->input('parent_ids', []))));
            $districts = District::whereIn('parent_id', $ids)->orderBy('name')->get(['id', 'name', 'slug', 'parent_id']);

            return response()->json([
                'success' => true,
                'data' => $districts,
            ]);
        }

        $request->validate([
            'parent_id' => ['required', 'integer', AddressHierarchyRules::existsStateId()],
        ]);

        $districts = District::where('parent_id', $request->integer('parent_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'parent_id']);

        return response()->json([
            'success' => true,
            'data' => $districts,
        ]);
    }

    /**
     * Talukas under one or more district rows.
     */
    public function talukas(Request $request): JsonResponse
    {
        if ($request->filled('parent_ids')) {
            $request->validate([
                'parent_ids' => ['required', 'array', 'min:1'],
                'parent_ids.*' => ['integer', AddressHierarchyRules::existsDistrictId()],
            ]);
            $ids = array_values(array_unique(array_map('intval', $request->input('parent_ids', []))));
            $talukas = Taluka::whereIn('parent_id', $ids)->orderBy('name')->get(['id', 'name', 'parent_id']);

            return response()->json([
                'success' => true,
                'data' => $talukas,
            ]);
        }

        $request->validate([
            'parent_id' => ['required', 'integer', AddressHierarchyRules::existsDistrictId()],
        ]);

        $talukas = Taluka::where('parent_id', $request->integer('parent_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        return response()->json([
            'success' => true,
            'data' => $talukas,
        ]);
    }

    /**
     * Cities under one taluka row (parent must be type=taluka).
     */
    public function cities(Request $request): JsonResponse
    {
        $request->validate([
            'parent_id' => ['required', 'integer', AddressHierarchyRules::existsTalukaId()],
        ]);

        $cities = City::where('parent_id', $request->integer('parent_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        return response()->json([
            'success' => true,
            'data' => $cities,
        ]);
    }
}
