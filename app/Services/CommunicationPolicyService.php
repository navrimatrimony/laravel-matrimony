<?php

namespace App\Services;

use App\Models\AdminSetting;

/**
 * Day-32 Step 8: Effective communication policy (AdminSetting overrides config).
 * Used by ContactRequestService so admin panel changes take effect without deploy.
 */
class CommunicationPolicyService
{
    public const KEY_PREFIX = 'communication_';

    public const KEYS = [
        'contact_request_mode',
        'reject_cooldown_days',
        'pending_expiry_days',
        'max_requests_per_day_per_sender',
        'grant_approve_once',
        'grant_approve_7_days',
        'grant_approve_30_days',
        'scope_email',
        'scope_phone',
        'scope_whatsapp',
    ];

    /**
     * Get full config array (AdminSetting overrides config/communication.php).
     */
    public static function getConfig(): array
    {
        $config = config('communication', []);

        $mode = AdminSetting::getValue(self::KEY_PREFIX . 'contact_request_mode', null);
        if ($mode !== null && in_array($mode, ['mutual_only', 'direct_allowed', 'disabled'], true)) {
            $config['contact_request_mode'] = $mode;
        }

        $cooldown = AdminSetting::getValue(self::KEY_PREFIX . 'reject_cooldown_days', null);
        if ($cooldown !== null && $cooldown !== '') {
            $v = (int) $cooldown;
            if ($v >= 7 && $v <= 365) {
                $config['reject_cooldown_days'] = $v;
            }
        }

        $expiry = AdminSetting::getValue(self::KEY_PREFIX . 'pending_expiry_days', null);
        if ($expiry !== null && $expiry !== '') {
            $v = (int) $expiry;
            if ($v >= 1 && $v <= 30) {
                $config['pending_expiry_days'] = $v;
            }
        }

        $maxPerDay = AdminSetting::getValue(self::KEY_PREFIX . 'max_requests_per_day_per_sender', null);
        if ($maxPerDay !== null && $maxPerDay !== '') {
            $config['max_requests_per_day_per_sender'] = $maxPerDay === '' || $maxPerDay === '0' ? null : (int) $maxPerDay;
        }

        $config['grant_duration_options'] = [
            'approve_once' => AdminSetting::getBool(self::KEY_PREFIX . 'grant_approve_once', $config['grant_duration_options']['approve_once'] ?? true),
            'approve_7_days' => AdminSetting::getBool(self::KEY_PREFIX . 'grant_approve_7_days', $config['grant_duration_options']['approve_7_days'] ?? true),
            'approve_30_days' => AdminSetting::getBool(self::KEY_PREFIX . 'grant_approve_30_days', $config['grant_duration_options']['approve_30_days'] ?? true),
        ];

        $config['allowed_contact_scopes'] = [
            'email' => AdminSetting::getBool(self::KEY_PREFIX . 'scope_email', $config['allowed_contact_scopes']['email'] ?? true),
            'phone' => AdminSetting::getBool(self::KEY_PREFIX . 'scope_phone', $config['allowed_contact_scopes']['phone'] ?? true),
            'whatsapp' => AdminSetting::getBool(self::KEY_PREFIX . 'scope_whatsapp', $config['allowed_contact_scopes']['whatsapp'] ?? true),
        ];

        return $config;
    }

    /**
     * Get current effective values for admin form (same shape as config).
     */
    public static function getCurrentForAdmin(): array
    {
        $config = config('communication', []);
        return [
            'contact_request_mode' => AdminSetting::getValue(self::KEY_PREFIX . 'contact_request_mode', $config['contact_request_mode'] ?? 'mutual_only'),
            'reject_cooldown_days' => AdminSetting::getValue(self::KEY_PREFIX . 'reject_cooldown_days', (string) ($config['reject_cooldown_days'] ?? 90)),
            'pending_expiry_days' => AdminSetting::getValue(self::KEY_PREFIX . 'pending_expiry_days', (string) ($config['pending_expiry_days'] ?? 7)),
            'max_requests_per_day_per_sender' => AdminSetting::getValue(self::KEY_PREFIX . 'max_requests_per_day_per_sender', (string) ($config['max_requests_per_day_per_sender'] ?? '')),
            'grant_approve_once' => AdminSetting::getBool(self::KEY_PREFIX . 'grant_approve_once', $config['grant_duration_options']['approve_once'] ?? true),
            'grant_approve_7_days' => AdminSetting::getBool(self::KEY_PREFIX . 'grant_approve_7_days', $config['grant_duration_options']['approve_7_days'] ?? true),
            'grant_approve_30_days' => AdminSetting::getBool(self::KEY_PREFIX . 'grant_approve_30_days', $config['grant_duration_options']['approve_30_days'] ?? true),
            'scope_email' => AdminSetting::getBool(self::KEY_PREFIX . 'scope_email', $config['allowed_contact_scopes']['email'] ?? true),
            'scope_phone' => AdminSetting::getBool(self::KEY_PREFIX . 'scope_phone', $config['allowed_contact_scopes']['phone'] ?? true),
            'scope_whatsapp' => AdminSetting::getBool(self::KEY_PREFIX . 'scope_whatsapp', $config['allowed_contact_scopes']['whatsapp'] ?? true),
        ];
    }
}
