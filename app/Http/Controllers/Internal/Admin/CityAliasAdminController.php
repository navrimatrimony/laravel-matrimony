<?php

namespace App\Http\Controllers\Internal\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\CityAlias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CityAliasAdminController extends Controller
{
    public function store(int $cityId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'alias_name' => 'required|string|max:100',
        ]);

        $city = City::findOrFail($cityId);
        $aliasName = trim($validated['alias_name']);
        $normalized = strtolower($aliasName);

        if (CityAlias::where('city_id', $cityId)->where('normalized_alias', $normalized)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'An alias with this name already exists for this city.',
            ], 422);
        }

        if (City::where('taluka_id', $city->taluka_id)->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'A city with this name already exists in the same taluka.',
            ], 422);
        }

        CityAlias::create([
            'city_id' => $cityId,
            'alias_name' => $aliasName,
            'normalized_alias' => $normalized,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Alias created.',
        ]);
    }
}
