<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\NotificationLocalization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class MobileNotificationApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(1, min(50, $perPage));

        $notifications = $user->notifications()
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Notifications loaded.',
            'unread_count' => $this->unreadCountFor($user),
            'notifications' => $notifications
                ->getCollection()
                ->map(fn (DatabaseNotification $notification): array => $this->notificationPayload($notification, $user))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
                'has_more_pages' => $notifications->hasMorePages(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        return response()->json([
            'success' => true,
            'unread_count' => $this->unreadCountFor($user),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $notification = $user->notifications()->whereKey($id)->first();
        if (! $notification instanceof DatabaseNotification) {
            return $this->error('Notification not found.', 404);
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
            $notification->refresh();
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'unread_count' => $this->unreadCountFor($user),
            'notification' => $this->notificationPayload($notification, $user),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $user->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.',
            'unread_count' => 0,
        ]);
    }

    private function notificationPayload(DatabaseNotification $notification, User $user): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $locale = NotificationLocalization::preferredLocaleForUser($user);
        $key = $this->notificationKey($notification, $data);
        $message = NotificationLocalization::displayMessage($data, $locale);
        $action = $this->actionPayload($data);

        return [
            'id' => (string) $notification->id,
            'type' => (string) $notification->type,
            'key' => $key,
            'title' => $this->titleFor($key, $locale),
            'message' => $message,
            'body' => $message,
            'created_at' => $this->dateValue($notification->created_at),
            'read_at' => $this->dateValue($notification->read_at),
            'is_unread' => $notification->read_at === null,
            'action' => $action,
            'action_type' => $action['action_type'] ?? null,
            'profile_id' => $action['profile_id'] ?? null,
            'request_id' => $action['request_id'] ?? null,
            'route_hint' => $action['route_hint'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notificationKey(DatabaseNotification $notification, array $data): string
    {
        $key = trim((string) ($data['type'] ?? ''));
        if ($key !== '') {
            return $key;
        }

        return Str::snake(class_basename((string) $notification->type));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function actionPayload(array $data): ?array
    {
        $key = (string) ($data['type'] ?? '');
        $revealed = ($data['revealed'] ?? true) !== false;
        $requestId = $this->positiveInt($data['contact_request_id'] ?? null)
            ?? $this->positiveInt($data['mediation_request_id'] ?? null);

        if (str_starts_with($key, 'contact_request') || $key === 'contact_grant_revoked') {
            return $this->compactAction([
                'action_type' => 'contact_inbox',
                'route_hint' => 'contact_inbox',
                'request_id' => $requestId,
            ]);
        }

        if ($revealed) {
            $profileId = $this->firstPositiveInt($data, [
                'sender_profile_id',
                'viewer_profile_id',
                'accepter_profile_id',
                'rejecter_profile_id',
                'receiver_profile_id',
                'subject_profile_id',
            ]);

            if ($profileId !== null) {
                return [
                    'action_type' => 'profile',
                    'route_hint' => 'profile',
                    'profile_id' => $profileId,
                    'request_id' => $requestId,
                ];
            }
        }

        if (in_array($key, ['plan_expiring_soon', 'chat_message_locked', 'referral_reward_pending'], true)
            || str_contains((string) ($data['mail_action_url'] ?? ''), '/plans')) {
            return [
                'action_type' => 'plans',
                'route_hint' => 'plans',
            ];
        }

        if ($key === 'new_matches_digest') {
            return [
                'action_type' => 'matches',
                'route_hint' => 'matches',
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|null
     */
    private function compactAction(array $values): ?array
    {
        $action = array_filter(
            $values,
            fn (mixed $value): bool => $value !== null && $value !== ''
        );

        return $action === [] ? null : $action;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function firstPositiveInt(array $data, array $keys): ?int
    {
        foreach ($keys as $key) {
            $id = $this->positiveInt($data[$key] ?? null);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function titleFor(string $key, string $locale): string
    {
        $marathi = NotificationLocalization::isMarathi($locale);

        return match ($key) {
            'interest_sent' => $marathi ? 'नवीन इच्छा' : 'New interest',
            'interest_accepted' => $marathi ? 'इच्छा स्वीकारली' : 'Interest accepted',
            'interest_rejected' => $marathi ? 'इच्छा नाकारली' : 'Interest declined',
            'contact_request_received' => $marathi ? 'कॉन्टॅक्ट विनंती' : 'Contact request',
            'contact_request_accepted' => $marathi ? 'कॉन्टॅक्ट मंजूर' : 'Contact approved',
            'contact_request_rejected' => $marathi ? 'कॉन्टॅक्ट नाकारला' : 'Contact declined',
            'contact_request_expired' => $marathi ? 'कॉन्टॅक्ट विनंती कालबाह्य' : 'Contact request expired',
            'contact_grant_revoked' => $marathi ? 'कॉन्टॅक्ट access रद्द' : 'Contact access revoked',
            'profile_viewed' => $marathi ? 'प्रोफाइल पाहिले' : 'Profile viewed',
            'image_approved' => $marathi ? 'फोटो मंजूर' : 'Photo approved',
            'image_rejected' => $marathi ? 'फोटो नाकारला' : 'Photo rejected',
            'plan_expiring_soon' => $marathi ? 'प्लॅन संपत आहे' : 'Plan expiring soon',
            'new_matches_digest' => $marathi ? 'नवीन जुळण्या' : 'New matches',
            'chat_message', 'chat_message_locked' => $marathi ? 'चॅट संदेश' : 'Chat message',
            'mediation_request_received', 'mediation_request_response' => $marathi ? 'WhatsApp Response' : 'WhatsApp Response',
            default => Str::headline(str_replace('_', ' ', $key)),
        };
    }

    private function unreadCountFor(User $user): int
    {
        return (int) $user->unreadNotifications()->count();
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
