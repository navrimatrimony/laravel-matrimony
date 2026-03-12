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
    public function states(): JsonResponse
    {
        $states = State::orderBy('name')->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $states,
        ]);
    }

    public function districts(Request $request): JsonResponse
    {
        $request->validate([
            'state_id' => ['required', 'integer', 'exists:states,id'],
        ]);

        $districts = District::where('state_id', $request->integer('state_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $districts,
        ]);
    }

    public function talukas(Request $request): JsonResponse
    {
        $request->validate([
            'district_id' => ['required', 'integer', 'exists:districts,id'],
        ]);

        $talukas = Taluka::where('district_id', $request->integer('district_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $talukas,
        ]);
    }
}

