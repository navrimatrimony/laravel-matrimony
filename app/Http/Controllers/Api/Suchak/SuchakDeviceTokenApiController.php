<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\SuchakAccount;
use App\Models\User;
use App\Services\Push\DeviceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Suchak-app FCM device registration.
 *
 * Tokens are owned by the SuchakAccount, not the User behind it: the same person
 * may run both apps on the same phone with different installs, and a push for
 * "your customer replied" must reach the Suchak app specifically.
 */
class SuchakDeviceTokenApiController extends Controller
{
    public function __construct(private readonly DeviceTokenService $deviceTokens) {}

    public function store(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        $validated = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:512'],
            'platform' => ['sometimes', Rule::in(DeviceToken::PLATFORMS)],
            'app' => ['sometimes', Rule::in([DeviceToken::APP_SUCHAK])],
        ]);

        $row = $this->deviceTokens->register(
            $account,
            $validated['token'],
            DeviceToken::APP_SUCHAK,
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
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        $removed = $this->deviceTokens->forget($account, $validated['token']);

        return response()->json([
            'success' => true,
            'message' => $removed ? 'Device token removed.' : 'Device token was not registered.',
            'data' => ['removed' => $removed],
        ]);
    }

    private function requireAccount(Request $request): SuchakAccount|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $account = $user->suchakAccount;
        if (! $account instanceof SuchakAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Suchak account is required to access this section.',
            ], 403);
        }

        return $account;
    }
}
