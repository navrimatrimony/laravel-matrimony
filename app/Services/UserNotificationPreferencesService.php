<?php

namespace App\Services;

use App\Models\SuchakAccount;
use App\Models\User;
use App\Services\Push\PushTypeRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-person notification opt-in/out (in-app alerts always remain on).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ONE engine, BOTH actors
 * ─────────────────────────────────────────────────────────────────────────────
 * A Suchak authenticates as a `SuchakAccount`, which is a different identity from
 * `User` — but `suchak_accounts.user_id` is NOT NULL and UNIQUE (see
 * 2026_06_09_120000_create_suchak_account_foundation_tables), so every Suchak has
 * exactly one User and every User has at most one SuchakAccount. The preference
 * therefore has exactly one home — `users.notification_preferences` — and this
 * service serves both actors through {@see self::resolveUser()}. There is no
 * Suchak-side copy of this engine and there must never be one.
 *
 * Consequence worth knowing: a person who is BOTH a member and a Suchak has ONE
 * preference set, shared by both apps. That is intended — it is one human making
 * one choice about being interrupted. The two apps still show DIFFERENT category
 * lists, because the list is filtered by app in PushTypeRegistry::forApp().
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Precedence with the admin switchboard is fixed: admin OFF beats user ON.
 * See {@see NotificationPlatformSettingsService}.
 */
class UserNotificationPreferencesService
{
    public const KEY_EMAIL_ALERTS = 'email_alerts';

    public const KEY_ENGAGEMENT_INACTIVE = 'engagement_inactive_reminder';

    public const KEY_ENGAGEMENT_MATCHES_DIGEST = 'engagement_new_matches_digest';

    /*
    |--------------------------------------------------------------------------
    | Two axes, never collapsed: EVENT and CHANNEL
    |--------------------------------------------------------------------------
    |
    | EVENT — "do I want new-matches digests at all?" One flat key per event, and
    | that key is CANONICAL. Events that existed before push keep the key they
    | already had (KEY_ENGAGEMENT_INACTIVE, KEY_ENGAGEMENT_MATCHES_DIGEST); events
    | introduced with push use their PushTypeRegistry key. There is exactly one
    | key per fact, so no two screens can disagree and nothing needs syncing.
    |
    | CHANNEL — "…but not as a phone push." A nested map under KEY_CHANNELS, only
    | written when the member's answer for one channel DIVERGES from the event
    | switch. Adding WhatsApp or SMS later adds entries inside this map, never a
    | new key per event-channel pair.
    |
    |   notification_preferences = [
    |       'engagement_new_matches_digest' => true,          // EVENT
    |       'channels' => [
    |           'engagement_new_matches_digest' => ['push' => false],   // CHANNEL
    |       ],
    |   ]
    |
    | Effective push = event switch AND channel override.
    |
    */
    public const KEY_CHANNELS = 'channels';

    public const CHANNEL_PUSH = 'push';

    /** Per-user quiet-hours opt-in. On by default so nobody is woken at night. */
    public const KEY_PUSH_QUIET_HOURS = 'push_quiet_hours';

    /**
     * A brand-new category defaults to ON for the user: people notice a MISSING
     * notification far more than an extra one, and the admin switch plus the
     * registry default already gate whether it can fire at all.
     */
    private const NEW_CATEGORY_USER_DEFAULT = true;

    /**
     * @return array<string, bool>
     */
    public function defaults(): array
    {
        $defaults = [
            self::KEY_EMAIL_ALERTS => true,
            self::KEY_ENGAGEMENT_INACTIVE => true,
            self::KEY_ENGAGEMENT_MATCHES_DIGEST => true,
            self::KEY_PUSH_QUIET_HOURS => true,
        ];

        // Event keys implied by the push registry. The two engagement events
        // above are already present and are NOT re-added under a second name —
        // PushTypeRegistry::preferenceKey() maps them onto these exact keys.
        foreach (app(PushTypeRegistry::class)->preferenceKeys() as $eventKey) {
            $defaults[$eventKey] ??= self::NEW_CATEGORY_USER_DEFAULT;
        }

        return $defaults;
    }

    /**
     * Effective preferences for a User.
     *
     * Keys are always the full known set — a stored blob written before a
     * category existed still yields that category's default rather than null.
     *
     * @return array<string, bool>
     */
    public function forUser(User $user): array
    {
        $stored = is_array($user->notification_preferences) ? $user->notification_preferences : [];

        $out = [];
        foreach ($this->defaults() as $key => $default) {
            $out[$key] = array_key_exists($key, $stored) ? (bool) $stored[$key] : $default;
        }

        return $out;
    }

    /**
     * Same as {@see self::forUser()} but accepts either actor.
     *
     * @return array<string, bool>
     */
    public function forOwner(Model $owner): array
    {
        $user = $this->resolveUser($owner);

        return $user instanceof User ? $this->forUser($user) : $this->defaults();
    }

    /**
     * Map any supported actor onto the single User row that owns the preference.
     *
     * Returns null only if a SuchakAccount's user row is missing, which the
     * schema forbids but a partially-deleted fixture could still produce.
     */
    public function resolveUser(?Model $owner): ?User
    {
        if ($owner instanceof User) {
            return $owner;
        }

        if ($owner instanceof SuchakAccount) {
            return $owner->user;
        }

        return null;
    }

    /**
     * Partial update — only keys present in $input are touched.
     *
     * @param  array<string, bool>  $input
     */
    public function saveForUser(User $user, array $input): void
    {
        // Start from the RAW stored blob, not from forUser(): that helper returns
        // only the flat boolean keys, so rebuilding from it would silently drop
        // the nested channel-override map on every unrelated save.
        $stored = is_array($user->notification_preferences) ? $user->notification_preferences : [];

        $prefs = array_merge($stored, $this->forUser($user));

        foreach (array_keys($this->defaults()) as $key) {
            if (array_key_exists($key, $input)) {
                $prefs[$key] = (bool) $input[$key];
            }
        }

        $user->forceFill(['notification_preferences' => $prefs])->saveQuietly();
    }

    /**
     * @param  array<string, bool>  $input
     */
    public function saveForOwner(Model $owner, array $input): bool
    {
        $user = $this->resolveUser($owner);

        if (! $user instanceof User) {
            return false;
        }

        $this->saveForUser($user, $input);

        return true;
    }

    public function emailAlertsEnabled(User $user): bool
    {
        if (! app(NotificationPlatformSettingsService::class)->mailEnabled()) {
            return false;
        }

        if (trim((string) ($user->email ?? '')) === '') {
            return false;
        }

        return $this->forUser($user)[self::KEY_EMAIL_ALERTS];
    }

    public function inactiveReminderEnabled(User $user): bool
    {
        if (! app(NotificationPlatformSettingsService::class)->inactiveReminderEnabled()) {
            return false;
        }

        return $this->forUser($user)[self::KEY_ENGAGEMENT_INACTIVE];
    }

    public function newMatchesDigestEnabled(User $user): bool
    {
        if (! app(NotificationPlatformSettingsService::class)->newMatchesDigestEnabled()) {
            return false;
        }

        return $this->forUser($user)[self::KEY_ENGAGEMENT_MATCHES_DIGEST];
    }

    /**
     * Raw channel-override map for an actor.
     *
     * @return array<string, array<string, bool>>
     */
    public function channelOverrides(?Model $owner): array
    {
        $user = $this->resolveUser($owner);
        if (! $user instanceof User) {
            return [];
        }

        $stored = is_array($user->notification_preferences) ? $user->notification_preferences : [];
        $channels = $stored[self::KEY_CHANNELS] ?? [];

        return is_array($channels) ? $channels : [];
    }

    /**
     * Has this actor silenced one channel for one event?
     *
     * Absent means "follow the event switch", which is why the default is true —
     * a member who never opened the push screen still gets pushes for events
     * they have not turned off.
     */
    public function channelEnabled(?Model $owner, string $eventKey, string $channel = self::CHANNEL_PUSH): bool
    {
        $overrides = $this->channelOverrides($owner);

        return (bool) ($overrides[$eventKey][$channel] ?? true);
    }

    /**
     * Partial update of the channel map. Only named event/channel pairs move.
     *
     * @param  array<string, array<string, bool>>  $input
     */
    public function saveChannelOverrides(Model $owner, array $input): bool
    {
        $user = $this->resolveUser($owner);
        if (! $user instanceof User) {
            return false;
        }

        $stored = is_array($user->notification_preferences) ? $user->notification_preferences : [];
        $channels = is_array($stored[self::KEY_CHANNELS] ?? null) ? $stored[self::KEY_CHANNELS] : [];

        foreach ($input as $eventKey => $flags) {
            foreach ((array) $flags as $channel => $value) {
                $channels[$eventKey][$channel] = (bool) $value;
            }
        }

        $stored[self::KEY_CHANNELS] = $channels;
        $user->forceFill(['notification_preferences' => $stored])->saveQuietly();

        return true;
    }

    /**
     * Does this actor want a PUSH for this push type?
     *
     * Both axes: the event must be wanted at all, and the push channel must not
     * be silenced for it. Admin state is NOT consulted here — PushDispatchService
     * checks that first, so precedence lives in one readable place.
     */
    public function pushTypeEnabled(?Model $owner, string $pushKey): bool
    {
        $eventKey = app(PushTypeRegistry::class)->preferenceKey($pushKey);
        $prefs = $owner instanceof Model ? $this->forOwner($owner) : $this->defaults();

        $eventWanted = (bool) ($prefs[$eventKey] ?? self::NEW_CATEGORY_USER_DEFAULT);

        return $eventWanted && $this->channelEnabled($owner, $eventKey, self::CHANNEL_PUSH);
    }

    /**
     * The event switch alone, ignoring channels. Used by the settings payload so
     * an app can explain a toggle that is blocked upstream instead of lying.
     */
    public function eventEnabled(?Model $owner, string $eventKey): bool
    {
        $prefs = $owner instanceof Model ? $this->forOwner($owner) : $this->defaults();

        return (bool) ($prefs[$eventKey] ?? self::NEW_CATEGORY_USER_DEFAULT);
    }

    public function quietHoursEnabled(?Model $owner): bool
    {
        $prefs = $owner instanceof Model ? $this->forOwner($owner) : $this->defaults();

        return (bool) ($prefs[self::KEY_PUSH_QUIET_HOURS] ?? true);
    }
}
