<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Image\ProfilePhotoUrlService;
use App\Support\NotificationLocalization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MobileNotificationApiController extends Controller
{
    /** @var list<string> */
    private const TEASER_DISPLAY_KEYS = [
        'headline',
        'lines',
        'viewed_summary',
        'photo_url',
        'avatar_style',
        'blur_photo_class',
        'accent_line',
        'match_line',
        'interest_hint',
    ];

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
        $collection = $notifications->getCollection();
        $actorProfiles = $this->actorProfilesFor($collection);

        return response()->json([
            'success' => true,
            'message' => 'Notifications loaded.',
            'unread_count' => $this->unreadCountFor($user),
            'notifications' => $notifications
                ->getCollection()
                ->map(fn (DatabaseNotification $notification): array => $this->notificationPayload($notification, $user, $actorProfiles))
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

    /**
     * @param  Collection<int, MatrimonyProfile>|null  $actorProfiles
     */
    private function notificationPayload(DatabaseNotification $notification, User $user, ?Collection $actorProfiles = null): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $locale = NotificationLocalization::preferredLocaleForUser($user);
        $key = $this->notificationKey($notification, $data);
        $message = NotificationLocalization::displayMessage($data, $locale);
        $action = $this->actionPayload($data);
        $actor = $this->actorPayload($data, $actorProfiles);
        $display = $this->displayPayload($key, $data, $action, $actor, $locale);

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
            'display' => $display,
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

        if (in_array($key, ['mediation_request_received', 'mediation_request_response'], true)) {
            return $this->compactAction([
                'action_type' => 'mediation_inbox',
                'route_hint' => 'mediation_inbox',
                'request_id' => $requestId,
            ]);
        }

        if (str_starts_with($key, 'contact_request') || $key === 'contact_grant_revoked') {
            return $this->compactAction([
                'action_type' => 'contact_inbox',
                'route_hint' => 'contact_inbox',
                'request_id' => $requestId,
            ]);
        }

        if (! $revealed && in_array($key, ['interest_sent', 'profile_viewed'], true) && is_array($data['teaser'] ?? null)) {
            return [
                'action_type' => 'plans',
                'route_hint' => 'plans',
            ];
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
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return Collection<int, MatrimonyProfile>
     */
    private function actorProfilesFor(Collection $notifications): Collection
    {
        $ids = $notifications
            ->map(fn (DatabaseNotification $notification): ?int => $this->actorProfileId(is_array($notification->data) ? $notification->data : []))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return MatrimonyProfile::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function actorProfileId(array $data): ?int
    {
        if (($data['revealed'] ?? true) === false) {
            return null;
        }

        return $this->firstPositiveInt($data, [
            'sender_profile_id',
            'viewer_profile_id',
            'accepter_profile_id',
            'rejecter_profile_id',
            'receiver_profile_id',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, MatrimonyProfile>|null  $actorProfiles
     * @return array<string, mixed>|null
     */
    private function actorPayload(array $data, ?Collection $actorProfiles = null): ?array
    {
        $profileId = $this->actorProfileId($data);
        if ($profileId === null) {
            return null;
        }

        $profile = $actorProfiles?->get($profileId);
        if (! $profile instanceof MatrimonyProfile) {
            $profile = MatrimonyProfile::query()->whereKey($profileId)->first();
        }
        if (! $profile instanceof MatrimonyProfile) {
            return null;
        }

        $hasApprovedPhoto = $profile->profile_photo && $profile->photo_approved !== false;
        $photoUrl = $hasApprovedPhoto
            ? app(ProfilePhotoUrlService::class)->publicUrl($profile->profile_photo)
            : (string) ($profile->profile_photo_url ?? '');

        return $this->compactAction([
            'id' => (int) $profile->id,
            'name' => trim((string) ($profile->full_name ?? '')),
            'photo_url' => $photoUrl,
            'photo_state' => $hasApprovedPhoto ? 'approved' : 'placeholder',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $action
     * @param  array<string, mixed>|null  $actor
     * @return array<string, mixed>
     */
    private function displayPayload(string $key, array $data, ?array $action, ?array $actor, string $locale): array
    {
        $teaser = $this->teaserDisplayPayload($key, $data);

        return [
            'layout' => $this->displayLayout($key, $teaser, $actor),
            'actor' => $actor,
            'teaser' => $teaser,
            'cta' => $this->ctaPayload($key, $action, $locale),
            'secondary_cta' => $this->secondaryCtaPayload($key, $data, $teaser, $locale),
            'privacy' => $this->privacyPayload($teaser, $actor),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $teaser
     * @param  array<string, mixed>|null  $actor
     */
    private function displayLayout(string $key, ?array $teaser, ?array $actor): string
    {
        if ($teaser !== null) {
            return 'locked_teaser';
        }

        if (str_starts_with($key, 'contact_request') || $key === 'contact_grant_revoked') {
            return 'contact_request';
        }

        if (in_array($key, ['mediation_request_received', 'mediation_request_response'], true)) {
            return 'mediation';
        }

        if ($actor !== null) {
            return 'profile';
        }

        if ($key === 'chat_message_locked') {
            return 'locked_action';
        }

        return 'system';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function teaserDisplayPayload(string $key, array $data): ?array
    {
        if (($data['revealed'] ?? true) !== false || ! in_array($key, ['interest_sent', 'profile_viewed'], true)) {
            return null;
        }

        $teaser = $data['teaser'] ?? null;
        if (! is_array($teaser)) {
            return null;
        }

        $display = [];
        foreach (self::TEASER_DISPLAY_KEYS as $field) {
            if (! array_key_exists($field, $teaser)) {
                continue;
            }

            if ($field === 'lines') {
                $lines = is_array($teaser[$field])
                    ? array_values(array_filter(array_map('strval', $teaser[$field]), fn (string $line): bool => trim($line) !== ''))
                    : [];
                $display[$field] = $lines;
                continue;
            }

            $value = $teaser[$field];
            if (is_scalar($value) || $value === null) {
                $display[$field] = $value;
            }
        }

        return $display;
    }

    /**
     * @param  array<string, mixed>|null  $teaser
     * @param  array<string, mixed>|null  $actor
     * @return array<string, string>
     */
    private function privacyPayload(?array $teaser, ?array $actor): array
    {
        if ($teaser !== null) {
            $photoState = (($teaser['avatar_style'] ?? null) === 'blur' && ! empty($teaser['photo_url']))
                ? 'blurred'
                : 'silhouette';

            return [
                'state' => 'locked_teaser',
                'photo' => $photoState,
                'contact' => 'hidden',
            ];
        }

        if ($actor !== null) {
            return [
                'state' => 'revealed',
                'photo' => 'clear_or_placeholder',
                'contact' => 'hidden',
            ];
        }

        return [
            'state' => 'system',
            'photo' => 'none',
            'contact' => 'hidden',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $action
     * @return array<string, mixed>|null
     */
    private function ctaPayload(string $key, ?array $action, string $locale): ?array
    {
        if ($action === null) {
            return null;
        }

        $routeHint = (string) ($action['route_hint'] ?? '');

        return $this->compactAction([
            'label' => $this->ctaLabelFor($key, $routeHint, $locale),
            'action_type' => $action['action_type'] ?? null,
            'route_hint' => $routeHint,
            'profile_id' => $action['profile_id'] ?? null,
            'request_id' => $action['request_id'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $teaser
     * @return array<string, mixed>|null
     */
    private function secondaryCtaPayload(string $key, array $data, ?array $teaser, string $locale): ?array
    {
        if ($teaser === null) {
            return null;
        }

        $marathi = NotificationLocalization::isMarathi($locale);
        $routeHint = $key === 'interest_sent' ? 'received_interests' : 'who_viewed';
        $defaultLabel = $key === 'interest_sent'
            ? ($marathi ? 'आलेल्या इच्छा पहा' : 'View received interests')
            : ($marathi ? 'कोणी प्रोफाइल पाहिले ते पहा' : 'View who viewed');

        return [
            'label' => trim((string) ($data['teaser_context_label'] ?? '')) ?: $defaultLabel,
            'action_type' => $routeHint,
            'route_hint' => $routeHint,
        ];
    }

    private function ctaLabelFor(string $key, string $routeHint, string $locale): string
    {
        $marathi = NotificationLocalization::isMarathi($locale);

        if ($routeHint === 'plans' && in_array($key, ['interest_sent', 'profile_viewed', 'chat_message_locked'], true)) {
            return $marathi ? 'Unlock करा' : 'Unlock';
        }

        return match ($routeHint) {
            'profile' => $marathi ? 'प्रोफाइल पहा' : 'View profile',
            'contact_inbox' => $marathi ? 'कॉन्टॅक्ट इनबॉक्स उघडा' : 'Open contact inbox',
            'mediation_inbox' => $marathi ? 'WhatsApp Response उघडा' : 'Open WhatsApp Response',
            'plans' => $marathi ? 'प्लॅन पहा' : 'View plans',
            'matches' => $marathi ? 'जुळण्या पहा' : 'View matches',
            default => $marathi ? 'उघडा' : 'Open',
        };
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
