<?php

namespace App\Services;

use App\Models\AdminSetting;

/**
 * Platform notification toggles: admin DB overrides with .env / config fallback.
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
     * Values for admin app-settings form (effective = DB or fallback).
     *
     * @return array<string, mixed>
     */
    public function formDefaults(): array
    {
        return [
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
