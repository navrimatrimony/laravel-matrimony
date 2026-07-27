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

    /**
     * Per-push-type keys are `push_<push key>`, where the push key comes from
     * PushTypeRegistry. Stored in the same `notification_preferences` JSON column
     * the three keys above already use — no second column, no second table.
     */
    public const KEY_PUSH_PREFIX = 'push_';

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

        foreach (app(PushTypeRegistry::class)->keys() as $pushKey) {
            $defaults[self::KEY_PUSH_PREFIX.$pushKey] = self::NEW_CATEGORY_USER_DEFAULT;
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
        $prefs = $this->forUser($user);

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
     * Does this actor want a push for this push type?
     *
     * Admin state is NOT consulted here — PushDispatchService checks it first, so
     * the two levels stay independently testable and the precedence lives in one
     * readable place.
     */
    public function pushTypeEnabled(?Model $owner, string $pushKey): bool
    {
        $prefs = $owner instanceof Model ? $this->forOwner($owner) : $this->defaults();

        return (bool) ($prefs[self::KEY_PUSH_PREFIX.$pushKey] ?? self::NEW_CATEGORY_USER_DEFAULT);
    }

    public function quietHoursEnabled(?Model $owner): bool
    {
        $prefs = $owner instanceof Model ? $this->forOwner($owner) : $this->defaults();

        return (bool) ($prefs[self::KEY_PUSH_QUIET_HOURS] ?? true);
    }
}
