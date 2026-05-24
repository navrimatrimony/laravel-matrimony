<?php

namespace App\Services\Chat;

use App\Models\AdminSetting;
use App\Services\WhoViewed\WhoViewedTeaserPolicy;

/**
 * Admin-tunable presentation policy for locked/chat teaser surfaces.
 * Stored as JSON in AdminSetting key self::SETTING_KEY.
 */
final class ChatTeaserPolicy
{
    public const SETTING_KEY = 'chat_teaser_policy_json';

    /** @var list<string> */
    public const MESSAGE_STYLES = ['anonymous', 'soft_context', 'upgrade_focused'];

    /** @var list<string> */
    public const PREVIEW_LINE_MODES = ['generic', 'relationship_safe', 'hidden'];

    /** @var list<string> */
    public const CTA_MODES = ['upgrade', 'request_contact', 'open_plans'];

    /**
     * @return array<string, mixed>
     */
    public static function normalized(): array
    {
        $raw = (string) AdminSetting::getValue(self::SETTING_KEY, '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $d = is_array($decoded) ? $decoded : [];

        return self::normalizeRow($d);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalizeForSave(array $input): array
    {
        return self::normalizeRow($input);
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    public static function lockedPreviewText(array $policy): string
    {
        return match ((string) ($policy['preview_line_mode'] ?? 'generic')) {
            'relationship_safe' => (string) __('chat_ui.read_locked_connected_message'),
            'hidden' => '',
            default => (string) __('chat_ui.read_locked_new_message'),
        };
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    public static function lockedSubline(array $policy): string
    {
        return match ((string) ($policy['locked_message_style'] ?? 'anonymous')) {
            'soft_context' => (string) __('chat_ui.read_locked_subline_soft'),
            'upgrade_focused' => (string) __('chat_ui.read_locked_subline_upgrade'),
            default => (string) __('chat_ui.read_locked_subline'),
        };
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    public static function lockedCtaLabel(array $policy): string
    {
        return match ((string) ($policy['locked_chat_cta'] ?? 'upgrade')) {
            'request_contact' => (string) __('chat_ui.read_locked_request_access'),
            'open_plans' => (string) __('chat_ui.read_locked_compare_plans'),
            default => (string) __('chat_ui.read_locked_upgrade_now'),
        };
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    public static function blurClass(array $policy): string
    {
        return match ((string) ($policy['teaser_blur_strength'] ?? 'medium')) {
            'light' => 'blur-[1px]',
            'soft' => 'blur-[2px]',
            'gentle' => 'blur-[3px]',
            'strong' => 'blur-[6px]',
            default => 'blur-[4px]',
        };
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    public static function senderHint(?string $name, array $policy): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return 'From a profile';
        }

        return 'From '.$name;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $d): array
    {
        $style = strtolower(trim((string) ($d['locked_message_style'] ?? 'anonymous')));
        if (! in_array($style, self::MESSAGE_STYLES, true)) {
            $style = 'anonymous';
        }

        $preview = strtolower(trim((string) ($d['preview_line_mode'] ?? 'generic')));
        if (! in_array($preview, self::PREVIEW_LINE_MODES, true)) {
            $preview = 'generic';
        }

        $cta = strtolower(trim((string) ($d['locked_chat_cta'] ?? 'upgrade')));
        if (! in_array($cta, self::CTA_MODES, true)) {
            $cta = 'upgrade';
        }

        $timeMode = strtolower(trim((string) ($d['locked_message_time'] ?? 'human')));
        if (! in_array($timeMode, WhoViewedTeaserPolicy::TEASER_VIEWED_TIME_MODES, true)) {
            $timeMode = 'human';
        }

        $blurStrength = strtolower(trim((string) ($d['teaser_blur_strength'] ?? 'medium')));
        if (! in_array($blurStrength, WhoViewedTeaserPolicy::TEASER_BLUR_STRENGTHS, true)) {
            $blurStrength = 'medium';
        }

        $threads = (int) ($d['max_locked_threads'] ?? 10);
        if ($threads < 1) {
            $threads = 1;
        }
        if ($threads > 50) {
            $threads = 50;
        }

        return [
            'locked_message_teaser_enabled' => filter_var($d['locked_message_teaser_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'locked_message_style' => $style,
            'show_sender_hint' => true,
            'mask_sender_name' => false,
            'preview_line_mode' => $preview,
            'locked_message_time' => $timeMode,
            'show_unread_count' => filter_var($d['show_unread_count'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'teaser_blur_strength' => $blurStrength,
            'locked_chat_cta' => $cta,
            'max_locked_threads' => $threads,
        ];
    }
}
