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
        // Messaging (chat)
        'allow_messaging',
        'messaging_mode',
        'max_consecutive_messages_without_reply',
        'reply_gate_cooling_hours',
        'max_messages_per_day_per_sender',
        'max_messages_per_week_per_sender',
        'max_messages_per_month_per_sender',
        'max_new_conversations_per_day',
        'allow_image_messages',
        'image_messages_audience',

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

        // Messaging
        $allowMessaging = AdminSetting::getValue(self::KEY_PREFIX . 'allow_messaging', null);
        if ($allowMessaging !== null && $allowMessaging !== '') {
            $config['allow_messaging'] = filter_var($allowMessaging, FILTER_VALIDATE_BOOLEAN);
        }

        $messagingMode = AdminSetting::getValue(self::KEY_PREFIX . 'messaging_mode', null);
        if ($messagingMode !== null && in_array($messagingMode, ['free_chat_with_reply_gate', 'contact_request_required'], true)) {
            $config['messaging_mode'] = $messagingMode;
        }

        $consecutive = AdminSetting::getValue(self::KEY_PREFIX . 'max_consecutive_messages_without_reply', null);
        if ($consecutive !== null && $consecutive !== '') {
            $v = (int) $consecutive;
            if ($v >= 1 && $v <= 20) {
                $config['max_consecutive_messages_without_reply'] = $v;
            }
        }

        $coolingHours = AdminSetting::getValue(self::KEY_PREFIX . 'reply_gate_cooling_hours', null);
        if ($coolingHours !== null && $coolingHours !== '') {
            $v = (int) $coolingHours;
            if ($v >= 1 && $v <= 720) {
                $config['reply_gate_cooling_hours'] = $v;
            }
        }

        $dayLimit = AdminSetting::getValue(self::KEY_PREFIX . 'max_messages_per_day_per_sender', null);
        if ($dayLimit !== null && $dayLimit !== '') {
            $v = (int) $dayLimit;
            if ($v >= 1 && $v <= 500) {
                $config['max_messages_per_day_per_sender'] = $v;
            }
        }

        $weekLimit = AdminSetting::getValue(self::KEY_PREFIX . 'max_messages_per_week_per_sender', null);
        if ($weekLimit !== null && $weekLimit !== '') {
            $v = (int) $weekLimit;
            if ($v >= 1 && $v <= 5000) {
                $config['max_messages_per_week_per_sender'] = $v;
            }
        }

        $monthLimit = AdminSetting::getValue(self::KEY_PREFIX . 'max_messages_per_month_per_sender', null);
        if ($monthLimit !== null && $monthLimit !== '') {
            $v = (int) $monthLimit;
            if ($v >= 1 && $v <= 20000) {
                $config['max_messages_per_month_per_sender'] = $v;
            }
        }

        $newConvos = AdminSetting::getValue(self::KEY_PREFIX . 'max_new_conversations_per_day', null);
        if ($newConvos !== null && $newConvos !== '') {
            $v = (int) $newConvos;
            if ($v >= 1 && $v <= 500) {
                $config['max_new_conversations_per_day'] = $v;
            }
        }

        $allowImages = AdminSetting::getValue(self::KEY_PREFIX . 'allow_image_messages', null);
        if ($allowImages !== null && $allowImages !== '') {
            $config['allow_image_messages'] = filter_var($allowImages, FILTER_VALIDATE_BOOLEAN);
        }

        $aud = AdminSetting::getValue(self::KEY_PREFIX . 'image_messages_audience', null);
        if ($aud !== null && in_array($aud, ['all', 'paid_only'], true)) {
            $config['image_messages_audience'] = $aud;
        }

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
            // Messaging
            'allow_messaging' => AdminSetting::getBool(self::KEY_PREFIX . 'allow_messaging', (bool) ($config['allow_messaging'] ?? true)),
            'messaging_mode' => AdminSetting::getValue(self::KEY_PREFIX . 'messaging_mode', (string) ($config['messaging_mode'] ?? 'free_chat_with_reply_gate')),
            'max_consecutive_messages_without_reply' => AdminSetting::getValue(self::KEY_PREFIX . 'max_consecutive_messages_without_reply', (string) ($config['max_consecutive_messages_without_reply'] ?? 2)),
            'reply_gate_cooling_hours' => AdminSetting::getValue(self::KEY_PREFIX . 'reply_gate_cooling_hours', (string) ($config['reply_gate_cooling_hours'] ?? 24)),
            'max_messages_per_day_per_sender' => AdminSetting::getValue(self::KEY_PREFIX . 'max_messages_per_day_per_sender', (string) ($config['max_messages_per_day_per_sender'] ?? 20)),
            'max_messages_per_week_per_sender' => AdminSetting::getValue(self::KEY_PREFIX . 'max_messages_per_week_per_sender', (string) ($config['max_messages_per_week_per_sender'] ?? 100)),
            'max_messages_per_month_per_sender' => AdminSetting::getValue(self::KEY_PREFIX . 'max_messages_per_month_per_sender', (string) ($config['max_messages_per_month_per_sender'] ?? 300)),
            'max_new_conversations_per_day' => AdminSetting::getValue(self::KEY_PREFIX . 'max_new_conversations_per_day', (string) ($config['max_new_conversations_per_day'] ?? 10)),
            'allow_image_messages' => AdminSetting::getBool(self::KEY_PREFIX . 'allow_image_messages', (bool) ($config['allow_image_messages'] ?? true)),
            'image_messages_audience' => AdminSetting::getValue(self::KEY_PREFIX . 'image_messages_audience', (string) ($config['image_messages_audience'] ?? 'paid_only')),

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
