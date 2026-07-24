<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\Location\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    public function search(Request $request, LocationService $locationService): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        if (mb_strlen($q, 'UTF-8') < 2) {
            return response()->json([]);
        }

        $limit = max(1, min(50, (int) $request->integer('limit', 20)));
        $preferredStateId = $request->integer('preferred_state_id') > 0
            ? (int) $request->integer('preferred_state_id')
            : null;
        $preferredStateName = trim((string) $request->input('preferred_state_name', 'Maharashtra'));

        $results = $locationService->search($q, [
            'limit' => $limit,
            'preferred_state_id' => $preferredStateId,
            'preferred_state_name' => $preferredStateName,
        ]);

        $payload = array_map(static function (array $row): array {
            $id = (int) ($row['id'] ?? 0);
            $stateId = isset($row['state_id']) ? (int) $row['state_id'] : null;

            return [
                'id' => $id,
                'location_id' => $id,
                'city_id' => $id,
                'name' => (string) ($row['name'] ?? ''),
                'hierarchy' => (string) ($row['hierarchy'] ?? ''),
                'display_label' => (string) ($row['display_label'] ?? ''),
                'state_id' => $stateId,
                'preferred_state' => (bool) ($row['preferred_state'] ?? false),
            ];
        }, $results);

        return response()->json($payload);
    }

    public function nearby(Request $request, LocationService $locationService): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:'.Location::geoTable().',id'],
            'radius' => ['nullable', 'integer', 'min:1', 'max:200'],
            'hierarchy' => ['nullable', 'string', Rule::in(['country', 'state', 'district', 'taluka', 'village'])],
        ]);

        $locationId = (int) $validated['location_id'];
        $radiusKm = (int) ($validated['radius'] ?? 10);
        $hierarchy = isset($validated['hierarchy']) ? (string) $validated['hierarchy'] : null;

        $results = $locationService->getNearbyLocations($locationId, $radiusKm, $hierarchy);

        return response()->json($results);
    }
}

