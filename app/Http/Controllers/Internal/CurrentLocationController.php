<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\Location\CurrentLocationResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * User-triggered GPS → canonical location (suggestion only; profile writes stay in MutationService via form submit).
 */
class CurrentLocationController extends Controller
{
    public function __construct(
        private CurrentLocationResolverService $currentLocationResolverService
    ) {}

    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'status' => 'unauthenticated'], 401);
        }

        $result = $this->currentLocationResolverService->resolve(
            (int) $user->id,
            (float) $validated['lat'],
            (float) $validated['lon']
        );

        $status = ($result['success'] ?? false) ? 200 : 422;
        if (($result['status'] ?? '') === 'busy') {
            $status = 429;
        }
        if (($result['status'] ?? '') === 'unauthenticated') {
            $status = 401;
        }

        return response()->json($result, $status);
    }
}
