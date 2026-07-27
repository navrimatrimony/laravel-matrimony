<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Push\DeviceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Member-app FCM device registration.
 *
 * Thin by contract: every rule about what happens to an existing token lives in
 * {@see DeviceTokenService}, because the Suchak controller must behave identically.
 */
class DeviceTokenApiController extends Controller
{
    public function __construct(private readonly DeviceTokenService $deviceTokens) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:512'],
            'platform' => ['sometimes', Rule::in(DeviceToken::PLATFORMS)],
            'app' => ['sometimes', Rule::in([DeviceToken::APP_MEMBER])],
        ]);

        $row = $this->deviceTokens->register(
            $user,
            $validated['token'],
            DeviceToken::APP_MEMBER,
            $validated['platform'] ?? DeviceToken::PLATFORM_ANDROID,
        );

        return response()->json([
            'success' => true,
            'message' => 'Device token registered.',
            'data' => [
                'id' => $row->id,
                'app' => $row->app,
                'platform' => $row->platform,
                'last_seen_at' => $row->last_seen_at?->toIso8601String(),
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        $removed = $this->deviceTokens->forget($user, $validated['token']);

        // Always 200: logging out with a token the server no longer has is a
        // success from the app's point of view, not an error to retry.
        return response()->json([
            'success' => true,
            'message' => $removed ? 'Device token removed.' : 'Device token was not registered.',
            'data' => ['removed' => $removed],
        ]);
    }
}
