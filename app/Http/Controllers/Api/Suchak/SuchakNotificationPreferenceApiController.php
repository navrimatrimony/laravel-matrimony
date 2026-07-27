<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Api\Concerns\HandlesNotificationPreferences;
use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suchak-app notification settings screen.
 *
 * The owner passed downstream is the SuchakAccount, which
 * UserNotificationPreferencesService resolves to its linked User — the single
 * home for this preference. See that service's docblock for why one engine
 * serves both actors.
 */
class SuchakNotificationPreferenceApiController extends Controller
{
    use HandlesNotificationPreferences;

    public function show(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        return $this->notificationPreferencePayload($account, DeviceToken::APP_SUCHAK);
    }

    public function update(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        return $this->applyNotificationPreferences($request, $account, DeviceToken::APP_SUCHAK);
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
