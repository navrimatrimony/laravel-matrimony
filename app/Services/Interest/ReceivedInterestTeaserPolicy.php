<?php

namespace App\Services\Interest;

use App\Models\AdminSetting;
use App\Services\WhoViewed\WhoViewedTeaserPolicy;

/**
 * Admin privacy for locked received-interest cards (reveal quota exhausted).
 * Stored separately from who-viewed teasers: {@see self::SETTING_KEY}.
 */
final class ReceivedInterestTeaserPolicy
{
    public const SETTING_KEY = 'received_interest_teaser_policy_json';

    /** @var list<string> */
    public const CARD_LAYOUTS = ['horizontal', 'vertical', 'two_column', 'photo_overlay'];

    /**
     * Received inbox ordering (member interests page).
     *
     * {@code priority_then_recent}: higher {@see Interest::$priority_score} first, then newest (default query order).
     * {@code newest_first}: newest interest first (ignores priority score for ordering).
     * {@code unlocked_first_recent}: revealed rows first (newest within that group), then locked (newest).
     */
    public const RECEIVED_INBOX_ROW_ORDERS = ['priority_then_recent', 'newest_first', 'unlocked_first_recent'];

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
     * Rich blurred card + courtesy lines, or minimal silhouette row when {@code rich_teaser_enabled} is off.
     *
     * @param  array<string, mixed>  $normalized  {@see self::normalized()}
     * @return array<string, mixed>
     */
    public static function forLockedPresentation(array $normalized): array
    {
        if (! empty($normalized['rich_teaser_enabled'])) {
            return $normalized;
        }

        return array_merge($normalized, [
            'name_display' => 'hidden',
            'teaser_avatar_style' => 'silhouette',
            'show_occupation' => false,
            'show_education' => false,
            'show_marital_status' => false,
            'show_repeat_view_teaser' => false,
            'show_match_teaser' => false,
        ]);
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
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $d): array
    {
        $loc = strtolower(trim((string) ($d['location_granularity'] ?? 'district_and_above')));
        if (! in_array($loc, WhoViewedTeaserPolicy::LOCATION_GRANULARITIES, true)) {
            $loc = 'district_and_above';
        }

        $age = strtolower(trim((string) ($d['show_age_mode'] ?? 'exact')));
        if (! in_array($age, WhoViewedTeaserPolicy::AGE_MODES, true)) {
            $age = 'exact';
        }

        $name = strtolower(trim((string) ($d['name_display'] ?? 'masked')));
        if (! in_array($name, WhoViewedTeaserPolicy::NAME_DISPLAYS, true)) {
            $name = 'masked';
        }

        $avatar = strtolower(trim((string) ($d['teaser_avatar_style'] ?? 'blur')));
        if (! in_array($avatar, WhoViewedTeaserPolicy::TEASER_AVATAR_STYLES, true)) {
            $avatar = 'blur';
        }

        $timeMode = strtolower(trim((string) ($d['teaser_viewed_time'] ?? 'human')));
        if (! in_array($timeMode, WhoViewedTeaserPolicy::TEASER_VIEWED_TIME_MODES, true)) {
            $timeMode = 'human';
        }

        $blurStrength = strtolower(trim((string) ($d['teaser_blur_strength'] ?? 'medium')));
        if (! in_array($blurStrength, WhoViewedTeaserPolicy::TEASER_BLUR_STRENGTHS, true)) {
            $blurStrength = 'medium';
        }

        $dots = (int) ($d['masked_name_dots'] ?? 5);
        if ($dots < 3) {
            $dots = 3;
        }
        if ($dots > 10) {
            $dots = 10;
        }

        $minMatch = (int) ($d['match_teaser_min_score'] ?? 75);
        if ($minMatch < 50) {
            $minMatch = 50;
        }
        if ($minMatch > 95) {
            $minMatch = 95;
        }

        $cardLayout = strtolower(trim((string) ($d['card_layout'] ?? 'horizontal')));
        if (! in_array($cardLayout, self::CARD_LAYOUTS, true)) {
            $cardLayout = 'horizontal';
        }

        $rowOrder = strtolower(trim((string) ($d['received_inbox_row_order'] ?? 'priority_then_recent')));
        if (! in_array($rowOrder, self::RECEIVED_INBOX_ROW_ORDERS, true)) {
            $rowOrder = 'priority_then_recent';
        }

        $perPage = (int) ($d['received_inbox_per_page'] ?? 15);
        if ($perPage < 5) {
            $perPage = 5;
        }
        if ($perPage > 50) {
            $perPage = 50;
        }

        return [
            'rich_teaser_enabled' => filter_var($d['rich_teaser_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'card_layout' => $cardLayout,
            'location_granularity' => $loc,
            'show_age_mode' => $age,
            'show_occupation' => filter_var($d['show_occupation'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'show_education' => filter_var($d['show_education'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'show_marital_status' => filter_var($d['show_marital_status'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'name_display' => $name,
            'teaser_avatar_style' => $avatar,
            'teaser_blur_strength' => $blurStrength,
            'teaser_viewed_time' => $timeMode,
            'masked_name_dots' => $dots,
            'show_repeat_view_teaser' => false,
            'show_match_teaser' => filter_var($d['show_match_teaser'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'match_teaser_min_score' => $minMatch,
            'received_inbox_row_order' => $rowOrder,
            'received_inbox_per_page' => $perPage,
        ];
    }
}
