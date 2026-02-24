<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\CityAlias;
use App\Models\LocationSuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationSuggestionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'suggested_name' => ['required', 'string', 'max:100'],
            'country_id' => ['required', 'exists:countries,id'],
            'state_id' => ['required', 'exists:states,id'],
            'district_id' => ['required', 'exists:districts,id'],
            'taluka_id' => ['required', 'exists:talukas,id'],
            'suggestion_type' => ['required', 'in:city,village'],
        ]);

        $normalized = strtolower(trim($validated['suggested_name']));

        $cityExists = City::where('taluka_id', $validated['taluka_id'])
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->exists();
        if ($cityExists) {
            return response()->json([
                'success' => false,
                'message' => 'Location already exists.',
            ]);
        }

        if (CityAlias::where('normalized_alias', $normalized)
            ->whereHas('city', function ($q) use ($validated) {
                $q->where('taluka_id', $validated['taluka_id']);
            })
            ->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Location already exists.',
            ]);
        }

        $pendingExists = LocationSuggestion::where('normalized_name', $normalized)
            ->where('taluka_id', $validated['taluka_id'])
            ->where('status', 'pending')
            ->exists();
        if ($pendingExists) {
            return response()->json([
                'success' => false,
                'message' => 'Suggestion already under review.',
            ]);
        }

        LocationSuggestion::create([
            'suggested_name' => $validated['suggested_name'],
            'normalized_name' => $normalized,
            'country_id' => $validated['country_id'],
            'state_id' => $validated['state_id'],
            'district_id' => $validated['district_id'],
            'taluka_id' => $validated['taluka_id'],
            'suggestion_type' => $validated['suggestion_type'],
            'suggested_by' => auth()->id(),
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Location submitted for admin approval.',
        ]);
    }
}
