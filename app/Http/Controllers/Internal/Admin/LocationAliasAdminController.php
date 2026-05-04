<?php

namespace App\Http\Controllers\Internal\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Location;
use App\Models\LocationAlias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationAliasAdminController extends Controller
{
    public function store(Location $location, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'alias_name' => 'required|string|max:100',
        ]);

        $aliasName = trim($validated['alias_name']);
        $normalized = strtolower($aliasName);

        if (LocationAlias::where('location_id', $location->id)->where('normalized_alias', $normalized)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'An alias with this name already exists for this place.',
            ], 422);
        }

        if ($location->type === 'city') {
            $city = City::find($location->id);
            if ($city && City::where('taluka_id', $city->taluka_id)->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'A city with this name already exists in the same taluka.',
                ], 422);
            }
        }

        LocationAlias::create([
            'location_id' => $location->id,
            'alias' => $aliasName,
            'normalized_alias' => $normalized,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Alias created.',
        ]);
    }
}
