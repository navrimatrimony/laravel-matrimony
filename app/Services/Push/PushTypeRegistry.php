<?php

namespace App\Services\Push;

use App\Models\DeviceToken;

/**
 * The ONE place a notification type declares its push identity.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HOW TO ADD A NEW NOTIFICATION TYPE (the whole checklist — it is 3 steps)
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. Add one row to self::TYPES below, keyed by a stable snake_case push key.
 *    That key is a CONTRACT: it is the admin toggle key, the per-user preference
 *    key, and the `type` field inside the FCM data block. Never rename it.
 * 2. Add `title` + `body` under that key in lang/en/push.php AND lang/mr/push.php.
 *    Body may use `:placeholders`; they are filled from the notification's own
 *    database `data` array, so use the key names that array already carries.
 * 3. Nothing else. No admin-panel change (the settings screen renders this
 *    registry), no API change, no new APK (both apps render the category list
 *    the server sends), no business-event wiring (PushDispatchService listens to
 *    every database notification that is written).
 *
 * An UNKNOWN notification class — one with no row here — is deliberately NOT
 * pushed. It is not an error: it is logged once and skipped, because a push with
 * no reviewed Marathi wording and no deep-link target is worse than no push.
 * That is the "safe default" for an unseen type.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Row shape:
 *   notification  FQCN of the Laravel Notification class. This is what lands in
 *                 `notifications.type`, so it is the lookup key at dispatch time.
 *   group         UI grouping for the settings screens (see self::GROUPS).
 *   target        Stable deep-link target the Flutter apps switch on. Never a URL
 *                 or a route name — the apps own their own navigation.
 *   data_keys     Keys copied from the notification's stored `data` array into the
 *                 FCM data block, so the app can open the exact row. Keep it small.
 *   apps          Which app's devices may receive it.
 *   default_push  Admin-side default before anyone touches the admin panel.
 */
final class PushTypeRegistry
{
    /** Settings-screen groups, in display order. */
    public const GROUPS = ['interest', 'chat', 'contact', 'profile', 'engagement', 'account'];

    /**
     * @var array<string, array{notification: string, group: string, target: string, data_keys: list<string>, apps: list<string>, default_push: bool}>
     */
    private const TYPES = [
        'new_interest' => [
            'notification' => \App\Notifications\InterestSentNotification::class,
            'group' => 'interest',
            'target' => 'interests_received',
            'data_keys' => ['interest_id', 'profile_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'interest_accepted' => [
            'notification' => \App\Notifications\InterestAcceptedNotification::class,
            'group' => 'interest',
            'target' => 'interests_sent',
            'data_keys' => ['interest_id', 'profile_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'interest_rejected' => [
            'notification' => \App\Notifications\InterestRejectedNotification::class,
            'group' => 'interest',
            'target' => 'interests_sent',
            'data_keys' => ['interest_id', 'profile_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => false,
        ],
        'new_chat_message' => [
            'notification' => \App\Notifications\NewChatMessageNotification::class,
            'group' => 'chat',
            'target' => 'chat_thread',
            'data_keys' => ['conversation_id', 'message_id'],
            'apps' => [DeviceToken::APP_MEMBER, DeviceToken::APP_SUCHAK],
            'default_push' => true,
        ],
        'chat_message_locked' => [
            'notification' => \App\Notifications\ChatMessageLockedNotification::class,
            'group' => 'chat',
            'target' => 'plans',
            'data_keys' => ['conversation_id'],
            'apps' => [DeviceToken::APP_MEMBER, DeviceToken::APP_SUCHAK],
            'default_push' => false,
        ],
        'contact_request_received' => [
            'notification' => \App\Notifications\ContactRequestReceivedNotification::class,
            'group' => 'contact',
            'target' => 'contact_inbox',
            'data_keys' => ['contact_request_id', 'profile_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'contact_request_accepted' => [
            'notification' => \App\Notifications\ContactRequestAcceptedNotification::class,
            'group' => 'contact',
            'target' => 'contact_inbox',
            'data_keys' => ['contact_request_id', 'profile_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'contact_request_rejected' => [
            'notification' => \App\Notifications\ContactRequestRejectedNotification::class,
            'group' => 'contact',
            'target' => 'contact_inbox',
            'data_keys' => ['contact_request_id', 'profile_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => false,
        ],
        'contact_request_expired' => [
            'notification' => \App\Notifications\ContactRequestExpiredNotification::class,
            'group' => 'contact',
            'target' => 'contact_inbox',
            'data_keys' => ['contact_request_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => false,
        ],
        'contact_grant_revoked' => [
            'notification' => \App\Notifications\ContactGrantRevokedNotification::class,
            'group' => 'contact',
            'target' => 'contact_inbox',
            'data_keys' => ['contact_grant_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => false,
        ],
        'mediation_request_received' => [
            'notification' => \App\Notifications\MediationRequestReceivedNotification::class,
            'group' => 'contact',
            'target' => 'suchak_requests',
            'data_keys' => ['mediation_request_id', 'profile_id'],
            'apps' => [DeviceToken::APP_MEMBER, DeviceToken::APP_SUCHAK],
            'default_push' => true,
        ],
        'mediation_request_response' => [
            'notification' => \App\Notifications\MediationRequestResponseNotification::class,
            'group' => 'contact',
            'target' => 'suchak_requests',
            'data_keys' => ['mediation_request_id', 'profile_id'],
            'apps' => [DeviceToken::APP_MEMBER, DeviceToken::APP_SUCHAK],
            'default_push' => true,
        ],
        'photo_approved' => [
            'notification' => \App\Notifications\ImageApprovedNotification::class,
            'group' => 'profile',
            'target' => 'my_photos',
            'data_keys' => ['photo_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'photo_rejected' => [
            'notification' => \App\Notifications\ImageRejectedNotification::class,
            'group' => 'profile',
            'target' => 'my_photos',
            'data_keys' => ['photo_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'profile_viewed' => [
            'notification' => \App\Notifications\ProfileViewedNotification::class,
            'group' => 'engagement',
            'target' => 'who_viewed_me',
            'data_keys' => ['profile_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => false,
        ],
        'new_matches' => [
            'notification' => \App\Notifications\NewMatchesAvailableNotification::class,
            'group' => 'engagement',
            'target' => 'matches',
            'data_keys' => ['match_count'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'inactive_reminder' => [
            'notification' => \App\Notifications\InactiveUserReminderNotification::class,
            'group' => 'engagement',
            'target' => 'home',
            'data_keys' => [],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'plan_expiring' => [
            'notification' => \App\Notifications\PlanExpiringSoonNotification::class,
            'group' => 'account',
            'target' => 'plans',
            'data_keys' => ['subscription_id', 'days_left'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'profile_suspended' => [
            'notification' => \App\Notifications\ProfileSuspendedNotification::class,
            'group' => 'account',
            'target' => 'my_profile',
            'data_keys' => [],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'profile_unsuspended' => [
            'notification' => \App\Notifications\ProfileUnsuspendedNotification::class,
            'group' => 'account',
            'target' => 'my_profile',
            'data_keys' => [],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'profile_soft_deleted' => [
            'notification' => \App\Notifications\ProfileSoftDeletedNotification::class,
            'group' => 'account',
            'target' => 'my_profile',
            'data_keys' => [],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'referral_activity' => [
            'notification' => \App\Notifications\ReferralActivityNotification::class,
            'group' => 'account',
            'target' => 'referrals',
            'data_keys' => [],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => false,
        ],
        'referral_reward' => [
            'notification' => \App\Notifications\ReferralRewardGrantedNotification::class,
            'group' => 'account',
            'target' => 'referrals',
            'data_keys' => ['bonus_days'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
    ];

    /**
     * push key => row, in declaration order.
     *
     * @return array<string, array{notification: string, group: string, target: string, data_keys: list<string>, apps: list<string>, default_push: bool}>
     */
    public function all(): array
    {
        return self::TYPES;
    }

    /**
     * Only the rows a given app's devices can receive.
     *
     * @return array<string, array{notification: string, group: string, target: string, data_keys: list<string>, apps: list<string>, default_push: bool}>
     */
    public function forApp(string $app): array
    {
        return array_filter(
            self::TYPES,
            static fn (array $row): bool => in_array($app, $row['apps'], true),
        );
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(self::TYPES);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, self::TYPES);
    }

    /**
     * @return array{notification: string, group: string, target: string, data_keys: list<string>, apps: list<string>, default_push: bool}|null
     */
    public function get(string $key): ?array
    {
        return self::TYPES[$key] ?? null;
    }

    /**
     * Resolve the push key from what `notifications.type` actually stores.
     *
     * Returns null for any class with no row here — the caller must treat that
     * as "do not push", never as an error.
     */
    public function keyForNotificationType(string $notificationType): ?string
    {
        foreach (self::TYPES as $key => $row) {
            if ($row['notification'] === $notificationType) {
                return $key;
            }
        }

        return null;
    }

    public function defaultPushEnabled(string $key): bool
    {
        return (bool) (self::TYPES[$key]['default_push'] ?? false);
    }

    /**
     * Localized, user-facing label for one push key. Resolved through the normal
     * translator, so `SetApiLocale` (Accept-Language beats saved preference) and
     * the web locale middleware both apply with no second resolution path.
     */
    public function label(string $key): string
    {
        return (string) __('push.types.'.$key.'.label');
    }

    public function description(string $key): string
    {
        return (string) __('push.types.'.$key.'.description');
    }

    public function groupLabel(string $group): string
    {
        return (string) __('push.groups.'.$group);
    }
}
