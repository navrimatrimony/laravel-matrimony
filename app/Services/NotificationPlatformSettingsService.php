<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Services\Push\PushTypeRegistry;

/**
 * Platform notification toggles: admin DB overrides with .env / config fallback.
 *
 * This is the ADMIN half of the two-level notification switchboard. The per-user
 * half is {@see UserNotificationPreferencesService}. Precedence is fixed and
 * one-directional: admin OFF wins over any user preference, so a type the
 * platform has disabled cannot be re-enabled by a member.
 */
class NotificationPlatformSettingsService
{
    public const KEY_MAIL_ENABLED = 'notification_mail_enabled';

    public const KEY_INACTIVE_REMINDER_ENABLED = 'notification_inactive_reminder_enabled';

    public const KEY_INACTIVE_WHATSAPP_ENABLED = 'notification_inactive_whatsapp_enabled';

    public const KEY_INACTIVE_AFTER_DAYS = 'notification_inactive_after_days';

    public const KEY_INACTIVE_COOLDOWN_DAYS = 'notification_inactive_cooldown_days';

    public const KEY_NEW_MATCHES_DIGEST_ENABLED = 'notification_new_matches_digest_enabled';

    public const KEY_PLAN_EXPIRY_NOTIFY_DAYS = 'notification_plan_expiry_notify_days';

    public const KEY_RETENTION_DAYS = 'notification_retention_days';

    /*
    |--------------------------------------------------------------------------
    | Push (FCM) — the runtime switchboard
    |--------------------------------------------------------------------------
    |
    | The product owner must be able to turn any notification type's push on or
    | off without a code change or a new APK, so the per-type switches live here
    | (AdminSetting rows) rather than in config or in a hardcoded list.
    |
    | Per-type keys are built as `notification_push_type_<push key>`, where the
    | push key comes from App\Services\Push\PushTypeRegistry. A type with no row
    | in admin_settings falls back to that registry's `default_push`, so a newly
    | added type is never an error and never a surprise blast.
    |
    */
    public const KEY_PUSH_ENABLED = 'notification_push_enabled';

    public const KEY_PUSH_TYPE_PREFIX = 'notification_push_type_';

    public const KEY_PUSH_QUIET_HOURS_ENABLED = 'notification_push_quiet_hours_enabled';

    public const KEY_PUSH_QUIET_HOURS_START = 'notification_push_quiet_hours_start';

    public const KEY_PUSH_QUIET_HOURS_END = 'notification_push_quiet_hours_end';

    public function mailEnabled(): bool
    {
        return $this->boolFromDbOrConfig(
            self::KEY_MAIL_ENABLED,
            (bool) config('notifications.mail.enabled', true),
        );
    }

    public function inactiveReminderEnabled(): bool
    {
        return $this->boolFromDbOrConfig(
            self::KEY_INACTIVE_REMINDER_ENABLED,
            (bool) config('engagement.inactive_reminder.enabled', true),
        );
    }

    public function inactiveWhatsappEnabled(): bool
    {
        return $this->boolFromDbOrConfig(
            self::KEY_INACTIVE_WHATSAPP_ENABLED,
            (bool) config('engagement.inactive_reminder.whatsapp.enabled', false),
        );
    }

    public function inactiveAfterDays(): int
    {
        return max(1, $this->intFromDbOrConfig(
            self::KEY_INACTIVE_AFTER_DAYS,
            (int) config('engagement.inactive_reminder.after_days', 3),
        ));
    }

    public function inactiveCooldownDays(): int
    {
        return max(1, $this->intFromDbOrConfig(
            self::KEY_INACTIVE_COOLDOWN_DAYS,
            (int) config('engagement.inactive_reminder.cooldown_days', 7),
        ));
    }

    public function newMatchesDigestEnabled(): bool
    {
        return $this->boolFromDbOrConfig(
            self::KEY_NEW_MATCHES_DIGEST_ENABLED,
            (bool) config('engagement.new_matches_digest.enabled', true),
        );
    }

    /**
     * @return list<int>
     */
    public function planExpiryNotifyDaysBeforeList(): array
    {
        $stored = AdminSetting::where('key', self::KEY_PLAN_EXPIRY_NOTIFY_DAYS)->value('value');
        if (is_string($stored) && trim($stored) !== '') {
            return $this->parseCommaIntList($stored);
        }

        return array_values(array_filter(
            array_map('intval', config('monetization.plan_expiry_notify_days_before_list', [7, 2, 1])),
            static fn (int $d): bool => $d > 0,
        ));
    }

    public function retentionDays(): int
    {
        return max(7, min(3650, $this->intFromDbOrConfig(
            self::KEY_RETENTION_DAYS,
            90,
        )));
    }

    /**
     * Master switch for the whole push channel.
     *
     * Admin row wins; otherwise the .env kill switch (ENGAGEMENT_PUSH_ENABLED).
     */
    public function pushEnabled(): bool
    {
        return $this->boolFromDbOrConfig(
            self::KEY_PUSH_ENABLED,
            (bool) config('engagement.push.enabled', false),
        );
    }

    /**
     * May this notification type push, platform-wide?
     *
     * Unknown key → false. A type nobody has described is never blasted out.
     */
    public function pushTypeEnabled(string $pushKey): bool
    {
        $registry = app(PushTypeRegistry::class);

        if (! $registry->has($pushKey)) {
            return false;
        }

        return $this->boolFromDbOrConfig(
            self::KEY_PUSH_TYPE_PREFIX.$pushKey,
            $registry->defaultPushEnabled($pushKey),
        );
    }

    /**
     * Effective admin state of every registered push type.
     *
     * @return array<string, bool>
     */
    public function pushTypeMatrix(): array
    {
        $out = [];

        foreach (app(PushTypeRegistry::class)->keys() as $key) {
            $out[$key] = $this->pushTypeEnabled($key);
        }

        return $out;
    }

    public function pushQuietHoursEnabled(): bool
    {
        return $this->boolFromDbOrConfig(
            self::KEY_PUSH_QUIET_HOURS_ENABLED,
            (bool) config('engagement.push.quiet_hours.enabled', true),
        );
    }

    /** Hour of day (0–23) the quiet window opens. */
    public function pushQuietHoursStart(): int
    {
        return $this->clampHour($this->intFromDbOrConfig(
            self::KEY_PUSH_QUIET_HOURS_START,
            (int) config('engagement.push.quiet_hours.start_hour', 22),
        ));
    }

    /** Hour of day (0–23) the quiet window closes. */
    public function pushQuietHoursEnd(): int
    {
        return $this->clampHour($this->intFromDbOrConfig(
            self::KEY_PUSH_QUIET_HOURS_END,
            (int) config('engagement.push.quiet_hours.end_hour', 8),
        ));
    }

    /**
     * Values for admin app-settings form (effective = DB or fallback).
     *
     * @return array<string, mixed>
     */
    public function formDefaults(): array
    {
        return [
            'push_enabled' => $this->pushEnabled(),
            'push_types' => $this->pushTypeMatrix(),
            'push_quiet_hours_enabled' => $this->pushQuietHoursEnabled(),
            'push_quiet_hours_start' => $this->pushQuietHoursStart(),
            'push_quiet_hours_end' => $this->pushQuietHoursEnd(),
            'mail_enabled' => $this->mailEnabled(),
            'inactive_reminder_enabled' => $this->inactiveReminderEnabled(),
            'inactive_whatsapp_enabled' => $this->inactiveWhatsappEnabled(),
            'inactive_after_days' => $this->inactiveAfterDays(),
            'inactive_cooldown_days' => $this->inactiveCooldownDays(),
            'new_matches_digest_enabled' => $this->newMatchesDigestEnabled(),
            'plan_expiry_notify_days' => implode(',', $this->planExpiryNotifyDaysBeforeList()),
            'retention_days' => $this->retentionDays(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function persistFromAdminForm(array $validated): void
    {
        AdminSetting::setValue(self::KEY_MAIL_ENABLED, $validated['notification_mail_enabled'] ? '1' : '0');
        AdminSetting::setValue(self::KEY_INACTIVE_REMINDER_ENABLED, $validated['notification_inactive_reminder_enabled'] ? '1' : '0');
        AdminSetting::setValue(self::KEY_INACTIVE_WHATSAPP_ENABLED, $validated['notification_inactive_whatsapp_enabled'] ? '1' : '0');
        AdminSetting::setValue(self::KEY_INACTIVE_AFTER_DAYS, (string) max(1, (int) $validated['notification_inactive_after_days']));
        AdminSetting::setValue(self::KEY_INACTIVE_COOLDOWN_DAYS, (string) max(1, (int) $validated['notification_inactive_cooldown_days']));
        AdminSetting::setValue(self::KEY_NEW_MATCHES_DIGEST_ENABLED, $validated['notification_new_matches_digest_enabled'] ? '1' : '0');
        AdminSetting::setValue(self::KEY_PLAN_EXPIRY_NOTIFY_DAYS, (string) $validated['notification_plan_expiry_notify_days']);
        AdminSetting::setValue(self::KEY_RETENTION_DAYS, (string) max(7, min(3650, (int) $validated['notification_retention_days'])));

        $this->persistPushFromAdminForm($validated);
    }

    /**
     * Push half of the admin notifications tab.
     *
     * Only writes keys the form actually submitted, so a partial post (or an
     * older admin build) cannot silently reset the whole matrix.
     *
     * @param  array<string, mixed>  $validated
     */
    private function persistPushFromAdminForm(array $validated): void
    {
        if (array_key_exists('notification_push_enabled', $validated)) {
            AdminSetting::setValue(self::KEY_PUSH_ENABLED, $validated['notification_push_enabled'] ? '1' : '0');
        }

        if (array_key_exists('notification_push_quiet_hours_enabled', $validated)) {
            AdminSetting::setValue(
                self::KEY_PUSH_QUIET_HOURS_ENABLED,
                $validated['notification_push_quiet_hours_enabled'] ? '1' : '0'
            );
        }

        if (array_key_exists('notification_push_quiet_hours_start', $validated)) {
            AdminSetting::setValue(
                self::KEY_PUSH_QUIET_HOURS_START,
                (string) $this->clampHour((int) $validated['notification_push_quiet_hours_start'])
            );
        }

        if (array_key_exists('notification_push_quiet_hours_end', $validated)) {
            AdminSetting::setValue(
                self::KEY_PUSH_QUIET_HOURS_END,
                (string) $this->clampHour((int) $validated['notification_push_quiet_hours_end'])
            );
        }

        // The form posts the full checkbox set, so an absent key means "unchecked",
        // not "not submitted" — but only when the push_types array is present at all.
        if (! array_key_exists('notification_push_types', $validated) || ! is_array($validated['notification_push_types'])) {
            return;
        }

        $submitted = $validated['notification_push_types'];

        foreach (app(PushTypeRegistry::class)->keys() as $key) {
            AdminSetting::setValue(
                self::KEY_PUSH_TYPE_PREFIX.$key,
                ! empty($submitted[$key]) ? '1' : '0'
            );
        }
    }

    private function clampHour(int $hour): int
    {
        return max(0, min(23, $hour));
    }

    private function boolFromDbOrConfig(string $key, bool $configDefault): bool
    {
        $row = AdminSetting::query()->where('key', $key)->first();
        if ($row !== null) {
            return filter_var($row->value, FILTER_VALIDATE_BOOLEAN);
        }

        return $configDefault;
    }

    private function intFromDbOrConfig(string $key, int $configDefault): int
    {
        $row = AdminSetting::query()->where('key', $key)->first();
        if ($row !== null && is_numeric($row->value)) {
            return (int) $row->value;
        }

        return $configDefault;
    }

    /**
     * @return list<int>
     */
    private function parseCommaIntList(string $raw): array
    {
        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];

        return array_values(array_unique(array_filter(
            array_map(static fn (string $p): int => (int) $p, $parts),
            static fn (int $d): bool => $d > 0,
        )));
    }
}
