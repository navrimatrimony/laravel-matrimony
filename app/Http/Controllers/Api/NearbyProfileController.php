<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\SetsApiLocaleFromRequest;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\Location\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NearbyProfileController extends Controller
{
    use SetsApiLocaleFromRequest;

    public function index(Request $request, LocationService $locationService): JsonResponse
    {
        $this->applyLocaleFromApiRequest($request);

        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:'.Location::geoTable().',id'],
            'radius' => ['nullable', 'integer', 'min:1', 'max:200'],
            'type' => ['nullable', 'string', Rule::in(['city', 'suburb', 'village'])],
        ]);

        $locationId = (int) $validated['location_id'];
        $radiusKm = (int) ($validated['radius'] ?? 10);
        $type = isset($validated['type']) ? (string) $validated['type'] : null;

        $rows = $locationService->getNearbyProfiles($locationId, $radiusKm, $type);

        return response()->json($rows);
    }
}

