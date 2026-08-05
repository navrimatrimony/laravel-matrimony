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
 *   preference_key  OPTIONAL. The canonical per-user preference key for the EVENT
 *                 this type represents. Omit it and the push key is used, which is
 *                 right for an event that has no other home. Set it when the event
 *                 ALREADY has a preference key — `inactive_reminder` and
 *                 `new_matches` both do, from the engagement engine — so the fact
 *                 "does this member want new-matches digests?" keeps exactly one
 *                 storage key and one screen. See UserNotificationPreferencesService.
 *   group         UI grouping for the settings screens (see self::GROUPS).
 *   target        Stable deep-link target the Flutter apps switch on. Never a URL
 *                 or a route name — the apps own their own navigation. This is a
 *                 CONTRACT: both apps switch on it, so never rename or repurpose
 *                 one, and never invent a target with no producer here.
 *   data_keys     Keys copied from the notification's stored `data` array into the
 *                 FCM data block, so the app can open the exact row. Keep it small.
 *
 *                 EVERY id here MUST be a key the notification's own toArray()
 *                 actually writes, and its name MUST identify the model it belongs
 *                 to. Bit us 2026-07-27: `mediation_request_id` looked like a
 *                 `SuchakProfileRequest` id and was wired as one, but
 *                 `MediationRequest extends ContactRequest` — it is a
 *                 `contact_requests` row, a different id space entirely. A deep
 *                 link on it opens the wrong record or 404s, silently, only for
 *                 ids that happen to collide. Name the id after its table.
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
            // The notification stores no interest id — only who sent it.
            'data_keys' => ['sender_profile_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'interest_accepted' => [
            'notification' => \App\Notifications\InterestAcceptedNotification::class,
            'group' => 'interest',
            'target' => 'interests_sent',
            'data_keys' => ['accepter_profile_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'interest_rejected' => [
            'notification' => \App\Notifications\InterestRejectedNotification::class,
            'group' => 'interest',
            'target' => 'interests_sent',
            'data_keys' => ['rejecter_profile_id'],
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
            // This notification stores no conversation id, so there is nothing to
            // deep-link to beyond the plans screen.
            'data_keys' => [],
            // Member-only: the plan gate on chat is a member monetization concept,
            // and the Suchak app has no equivalent screen for this target.
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => false,
        ],
        'contact_request_received' => [
            'notification' => \App\Notifications\ContactRequestReceivedNotification::class,
            'group' => 'contact',
            'target' => 'contact_inbox',
            'data_keys' => ['contact_request_id', 'sender_profile_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'contact_request_accepted' => [
            'notification' => \App\Notifications\ContactRequestAcceptedNotification::class,
            'group' => 'contact',
            'target' => 'contact_inbox',
            'data_keys' => ['contact_request_id', 'receiver_profile_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'contact_request_rejected' => [
            'notification' => \App\Notifications\ContactRequestRejectedNotification::class,
            'group' => 'contact',
            'target' => 'contact_inbox',
            'data_keys' => ['contact_request_id', 'receiver_profile_id'],
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
            'data_keys' => ['contact_grant_id', 'contact_request_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => false,
        ],
        /*
        | Mediation: `MediationRequest extends ContactRequest`, so both of these
        | carry a `contact_requests` row id — NOT a SuchakProfileRequest id, and
        | not an id of any "mediation_requests" table (there is none). Both are
        | sent to MEMBERS (MediationRequestService notifies $receiver and
        | $mediation->sender), so they are member-app only and land in the
        | contact inbox, which is the screen that lists contact_requests.
        |
        | The payload deliberately emits ONLY `contact_request_id`. The
        | notifications also store a `mediation_request_id` alias of the same
        | value; forwarding it would re-create the ambiguity that made the Suchak
        | app deep-link into the wrong model.
        */
        'mediation_request_received' => [
            'notification' => \App\Notifications\MediationRequestReceivedNotification::class,
            'group' => 'contact',
            'target' => 'contact_inbox',
            'data_keys' => ['contact_request_id', 'sender_profile_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'mediation_request_response' => [
            'notification' => \App\Notifications\MediationRequestResponseNotification::class,
            'group' => 'contact',
            'target' => 'contact_inbox',
            'data_keys' => ['contact_request_id'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'photo_approved' => [
            'notification' => \App\Notifications\ImageApprovedNotification::class,
            'group' => 'profile',
            'target' => 'my_photos',
            // Photo moderation notifications store only a reason, no photo id.
            'data_keys' => [],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'photo_rejected' => [
            'notification' => \App\Notifications\ImageRejectedNotification::class,
            'group' => 'profile',
            'target' => 'my_photos',
            'data_keys' => [],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        'profile_viewed' => [
            'notification' => \App\Notifications\ProfileViewedNotification::class,
            'group' => 'engagement',
            'target' => 'who_viewed_me',
            'data_keys' => ['viewer_profile_id'],
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
            // Binds to the EXISTING engagement preference. Inventing a second key
            // here would orphan every choice members have already made and let the
            // two disagree silently.
            'preference_key' => \App\Services\UserNotificationPreferencesService::KEY_ENGAGEMENT_MATCHES_DIGEST,
        ],
        'inactive_reminder' => [
            'notification' => \App\Notifications\InactiveUserReminderNotification::class,
            'group' => 'engagement',
            'target' => 'home',
            'data_keys' => [],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
            'preference_key' => \App\Services\UserNotificationPreferencesService::KEY_ENGAGEMENT_INACTIVE,
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
        /*
        | Security alert, not a product update. It fires on the member's OWN
        | device too, seconds after they changed the password there — that echo
        | is accepted, because the device this actually matters on is the OTHER
        | one: the phone whose session was just revoked. Suppressing the echo
        | would mean excluding the originating device, and PushDispatchService
        | resolves tokens from the notifiable, not from the request.
        |
        | `target` is `notifications` — the app has no security screen, and the
        | notification list is where the full sentence (and the "contact us"
        | line) is readable. Both apps already handle that target.
        |
        | Member app only, like every other `account` row: the Suchak app has its
        | own registration/password routes and does not surface this event.
        */
        'password_changed' => [
            'notification' => \App\Notifications\PasswordChangedNotification::class,
            'group' => 'account',
            'target' => 'notifications',
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
        /*
        | Suchak-only marketplace account alerts (U2). Database row is audit
        | (MRT-03); push is best-effort. Target `notifications` is already
        | handled by both apps; Suchak has no dedicated inbox.
        */
        'suchak_customer_deletion_requested' => [
            'notification' => \App\Notifications\SuchakCustomerDeletionRequestedNotification::class,
            'group' => 'account',
            'target' => 'notifications',
            'data_keys' => ['customer_full_name', 'event_date'],
            'apps' => [DeviceToken::APP_SUCHAK],
            'default_push' => true,
        ],
        'suchak_customer_deletion_cancelled' => [
            'notification' => \App\Notifications\SuchakCustomerDeletionCancelledNotification::class,
            'group' => 'account',
            'target' => 'notifications',
            'data_keys' => ['customer_full_name', 'event_date'],
            'apps' => [DeviceToken::APP_SUCHAK],
            'default_push' => true,
        ],
        /*
        | Admin alert when a dispute party requests deletion (U3). NOTIFY_ONLY —
        | dispute lifecycle is untouched. Member-app devices for admin users.
        */
        'dispute_party_deletion_requested' => [
            'notification' => \App\Notifications\DisputePartyDeletionRequestedNotification::class,
            'group' => 'account',
            'target' => 'notifications',
            'data_keys' => ['customer_full_name', 'event_date', 'open_dispute_count'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        /*
        | Member alert when a Suchak marks a meeting complete (U8). Without this,
        | U9–U11 meetings list/actions stay undiscoverable.
        */
        'suchak_meeting_completion_marked' => [
            'notification' => \App\Notifications\SuchakMeetingCompletionMarkedNotification::class,
            'group' => 'account',
            'target' => 'notifications',
            'data_keys' => ['visit_id', 'scheduled_date'],
            'apps' => [DeviceToken::APP_MEMBER],
            'default_push' => true,
        ],
        /*
        | Suchak publisher alert when a helper proposes on their challenge (U12).
        */
        'marketplace_proposal_received' => [
            'notification' => \App\Notifications\MarketplaceProposalReceivedNotification::class,
            'group' => 'account',
            'target' => 'notifications',
            'data_keys' => ['challenge_id', 'proposer_suchak_name'],
            'apps' => [DeviceToken::APP_SUCHAK],
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
     * The canonical per-user preference key for the EVENT behind a push type.
     *
     * Usually the push key itself. Where the event already had a preference key
     * before push existed, that older key wins — one fact, one destination.
     */
    public function preferenceKey(string $pushKey): string
    {
        return (string) (self::TYPES[$pushKey]['preference_key'] ?? $pushKey);
    }

    /**
     * Every canonical event preference key this registry implies, deduplicated.
     *
     * @return list<string>
     */
    public function preferenceKeys(): array
    {
        return array_values(array_unique(array_map(
            fn (string $key): string => $this->preferenceKey($key),
            $this->keys(),
        )));
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
