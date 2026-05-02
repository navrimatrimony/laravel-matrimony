<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\SetsApiLocaleFromRequest;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\Location\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    use SetsApiLocaleFromRequest;

    public function search(Request $request, LocationService $locationService): JsonResponse
    {
        $this->applyLocaleFromApiRequest($request);

        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return response()->json([]);
        }

        $results = $locationService->search($q);
        $results = array_slice($results, 0, 25);

        $payload = array_map(static function (array $row): array {
            $id = (int) ($row['id'] ?? 0);
            return [
                'id' => $id,
                'location_id' => $id,
                'city_id' => $id,
                'name' => (string) ($row['name'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'display_label' => (string) ($row['display_label'] ?? ''),
            ];
        }, $results);

        return response()->json($payload);
    }

    public function nearby(Request $request, LocationService $locationService): JsonResponse
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

        $results = $locationService->getNearbyLocations($locationId, $radiusKm, $type);

        return response()->json($results);
    }
}

