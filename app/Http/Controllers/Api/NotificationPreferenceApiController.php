<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesNotificationPreferences;
use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Member-app notification settings screen (server-driven category list).
 */
class NotificationPreferenceApiController extends Controller
{
    use HandlesNotificationPreferences;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        return $this->notificationPreferencePayload($user, DeviceToken::APP_MEMBER);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        return $this->applyNotificationPreferences($request, $user, DeviceToken::APP_MEMBER);
    }
}
