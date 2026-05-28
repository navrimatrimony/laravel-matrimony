<?php

namespace App\Services;

use App\Models\User;

/**
 * Per-member notification opt-in/out (in-app alerts always remain on).
 */
class UserNotificationPreferencesService
{
    public const KEY_EMAIL_ALERTS = 'email_alerts';

    public const KEY_ENGAGEMENT_INACTIVE = 'engagement_inactive_reminder';

    public const KEY_ENGAGEMENT_MATCHES_DIGEST = 'engagement_new_matches_digest';

    /**
     * @return array<string, bool>
     */
    public function defaults(): array
    {
        return [
            self::KEY_EMAIL_ALERTS => true,
            self::KEY_ENGAGEMENT_INACTIVE => true,
            self::KEY_ENGAGEMENT_MATCHES_DIGEST => true,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function forUser(User $user): array
    {
        $stored = is_array($user->notification_preferences) ? $user->notification_preferences : [];

        $merged = array_merge($this->defaults(), $stored);

        return [
            self::KEY_EMAIL_ALERTS => (bool) ($merged[self::KEY_EMAIL_ALERTS] ?? true),
            self::KEY_ENGAGEMENT_INACTIVE => (bool) ($merged[self::KEY_ENGAGEMENT_INACTIVE] ?? true),
            self::KEY_ENGAGEMENT_MATCHES_DIGEST => (bool) ($merged[self::KEY_ENGAGEMENT_MATCHES_DIGEST] ?? true),
        ];
    }

    /**
     * @param  array<string, bool>  $input
     */
    public function saveForUser(User $user, array $input): void
    {
        $prefs = $this->forUser($user);

        foreach ([self::KEY_EMAIL_ALERTS, self::KEY_ENGAGEMENT_INACTIVE, self::KEY_ENGAGEMENT_MATCHES_DIGEST] as $key) {
            if (array_key_exists($key, $input)) {
                $prefs[$key] = (bool) $input[$key];
            }
        }

        $user->forceFill(['notification_preferences' => $prefs])->saveQuietly();
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
}
