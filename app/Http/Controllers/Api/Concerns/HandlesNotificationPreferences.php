<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Services\Push\PushDispatchService;
use App\Services\UserNotificationPreferencesService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Shared body of the member and Suchak notification-preference endpoints.
 *
 * Both actors answer the same question ("which notifications may interrupt me?")
 * against the same storage, so there is one implementation here and two thin
 * controllers that differ only in which owner and which app they resolve.
 *
 * The response is SERVER-DRIVEN by design: the apps render whatever category list
 * arrives and hardcode nothing. Adding a notification type later makes it appear
 * in both settings screens with no new APK.
 */
trait HandlesNotificationPreferences
{
    /**
     * GET — the whole settings screen in one payload.
     */
    protected function notificationPreferencePayload(Model $owner, string $app): JsonResponse
    {
        $dispatcher = app(PushDispatchService::class);

        return response()->json([
            'success' => true,
            'message' => 'Notification preferences loaded.',
            'data' => [
                'quiet_hours' => $dispatcher->quietHoursPayload($owner),
                'categories' => $dispatcher->settingsCategoriesFor($app, $owner),
            ],
        ]);
    }

    /**
     * PUT — partial update. Unknown or admin-disabled category keys are rejected
     * rather than ignored, so a stale app build fails loudly instead of silently
     * writing a preference that can never take effect.
     */
    protected function applyNotificationPreferences(Request $request, Model $owner, string $app): JsonResponse
    {
        $validated = $request->validate([
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['boolean'],
            'quiet_hours_enabled' => ['sometimes', 'boolean'],
        ]);

        $dispatcher = app(PushDispatchService::class);

        // Only categories this app shows AND the admin has enabled are writable.
        $allowed = array_column($dispatcher->settingsCategoriesFor($app, $owner), 'key');

        $updates = [];

        foreach ((array) ($validated['categories'] ?? []) as $key => $value) {
            if (! in_array((string) $key, $allowed, true)) {
                throw ValidationException::withMessages([
                    'categories.'.$key => 'Unknown notification category: '.$key,
                ]);
            }

            $updates[UserNotificationPreferencesService::KEY_PUSH_PREFIX.$key] = (bool) $value;
        }

        if (array_key_exists('quiet_hours_enabled', $validated)) {
            $updates[UserNotificationPreferencesService::KEY_PUSH_QUIET_HOURS] = (bool) $validated['quiet_hours_enabled'];
        }

        if ($updates !== [] && ! app(UserNotificationPreferencesService::class)->saveForOwner($owner, $updates)) {
            return response()->json([
                'success' => false,
                'message' => 'Notification preferences are not available for this account.',
            ], 422);
        }

        return $this->notificationPreferencePayload($owner->refresh(), $app);
    }
}
