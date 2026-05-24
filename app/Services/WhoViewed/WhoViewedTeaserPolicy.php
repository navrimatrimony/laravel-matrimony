<?php

namespace App\Services\WhoViewed;

use App\Models\AdminSetting;

/**
 * Admin-tunable privacy for locked <strong>who viewed me</strong> teaser cards only.
 * Stored as JSON in {@see AdminSetting} key {@see WhoViewedTeaserPolicy::SETTING_KEY} — no new DB columns.
 */
final class WhoViewedTeaserPolicy
{
    public const SETTING_KEY = 'who_viewed_teaser_policy_json';

    /**
     * When the member has a partial plan (FIFO reveal slots), how to order rows in the list.
     * {@code fifo_unlocked_first}: FIFO full rows first (oldest “first view” in window), then locked teasers by most recent view (current default).
     * {@code recent_activity_first}: everyone sorted by most recent view; full vs teaser still follows FIFO slots by viewer id.
     */
    public const PARTIAL_PLAN_LIST_ORDERS = ['fifo_unlocked_first', 'recent_activity_first'];

    /** @var list<string> */
    public const LOCATION_GRANULARITIES = ['state_only', 'district_and_above', 'taluka_and_above'];

    /** @var list<string> */
    public const AGE_MODES = ['off', 'decade', 'exact'];

    /** @var list<string> */
    public const NAME_DISPLAYS = ['hidden', 'masked', 'courtesy_from_place', 'first_only', 'full'];

    /** Teaser card left column: icon only, or blurred approved photo (still anonymous). */
    public const TEASER_AVATAR_STYLES = ['silhouette', 'blur'];

    /** When avatar is blur, Tailwind blur strength (admin-tunable). */
    public const TEASER_BLUR_STRENGTHS = ['light', 'soft', 'gentle', 'medium', 'strong'];

    /** "Viewed …" line: coarse buckets vs relative time. */
    public const TEASER_VIEWED_TIME_MODES = ['bucket', 'human'];

    /**
     * @return array{
     *   location_granularity: string,
     *   show_age_mode: string,
     *   show_occupation: bool,
     *   show_education: bool,
     *   show_marital_status: bool,
     *   name_display: string,
     *   locked_teaser_rows: int,
     *   teaser_avatar_style: string,
     *   teaser_blur_strength: string,
     *   teaser_viewed_time: string,
     *   masked_name_dots: int,
     *   show_repeat_view_teaser: bool,
     *   show_match_teaser: bool,
     *   match_teaser_min_score: int,
     *   apply_who_viewed_locked: bool,
     * }
     */
    public static function normalized(): array
    {
        $raw = (string) AdminSetting::getValue(self::SETTING_KEY, '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $d = is_array($decoded) ? $decoded : [];

        return self::normalizeRow($d);
    }

    /**
     * Locked who-viewed teaser rows: use full admin policy, or a minimal anonymous row when
     * {@code apply_who_viewed_locked} is off.
     *
     * @param  array<string, mixed>  $normalized  {@see self::normalized()}
     * @return array<string, mixed>
     */
    public static function forWhoViewedLockedTeasers(array $normalized): array
    {
        if (! empty($normalized['apply_who_viewed_locked'])) {
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
     * Persistable JSON row (same shape as {@see self::normalized()}).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalizeForSave(array $input): array
    {
        return self::normalizeRow($input);
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{
     *   location_granularity: string,
     *   show_age_mode: string,
     *   show_occupation: bool,
     *   show_education: bool,
     *   show_marital_status: bool,
     *   name_display: string,
     *   locked_teaser_rows: int,
     *   teaser_avatar_style: string,
     *   teaser_blur_strength: string,
     *   teaser_viewed_time: string,
     *   masked_name_dots: int,
     *   show_repeat_view_teaser: bool,
     *   show_match_teaser: bool,
     *   match_teaser_min_score: int,
     *   apply_who_viewed_locked: bool,
     * }
     */
    private static function normalizeRow(array $d): array
    {
        $loc = strtolower(trim((string) ($d['location_granularity'] ?? 'district_and_above')));
        if (! in_array($loc, self::LOCATION_GRANULARITIES, true)) {
            $loc = 'district_and_above';
        }

        $age = strtolower(trim((string) ($d['show_age_mode'] ?? 'exact')));
        if (! in_array($age, self::AGE_MODES, true)) {
            $age = 'exact';
        }

        $name = strtolower(trim((string) ($d['name_display'] ?? 'masked')));
        if (! in_array($name, self::NAME_DISPLAYS, true)) {
            $name = 'masked';
        }

        $rows = (int) ($d['locked_teaser_rows'] ?? 40);
        if ($rows < 1) {
            $rows = 1;
        }
        if ($rows > 60) {
            $rows = 60;
        }

        $avatar = strtolower(trim((string) ($d['teaser_avatar_style'] ?? 'blur')));
        if (! in_array($avatar, self::TEASER_AVATAR_STYLES, true)) {
            $avatar = 'blur';
        }

        $timeMode = strtolower(trim((string) ($d['teaser_viewed_time'] ?? 'human')));
        if (! in_array($timeMode, self::TEASER_VIEWED_TIME_MODES, true)) {
            $timeMode = 'human';
        }

        $blurStrength = strtolower(trim((string) ($d['teaser_blur_strength'] ?? 'medium')));
        if (! in_array($blurStrength, self::TEASER_BLUR_STRENGTHS, true)) {
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

        $listOrder = strtolower(trim((string) ($d['partial_plan_list_order'] ?? 'fifo_unlocked_first')));
        if (! in_array($listOrder, self::PARTIAL_PLAN_LIST_ORDERS, true)) {
            $listOrder = 'fifo_unlocked_first';
        }

        $perPage = (int) ($d['who_viewed_per_page'] ?? 15);
        if ($perPage < 5) {
            $perPage = 5;
        }
        if ($perPage > 50) {
            $perPage = 50;
        }

        return [
            'location_granularity' => $loc,
            'show_age_mode' => $age,
            'show_occupation' => filter_var($d['show_occupation'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'show_education' => filter_var($d['show_education'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'show_marital_status' => filter_var($d['show_marital_status'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'name_display' => $name,
            'locked_teaser_rows' => $rows,
            'teaser_avatar_style' => $avatar,
            'teaser_blur_strength' => $blurStrength,
            'teaser_viewed_time' => $timeMode,
            'masked_name_dots' => $dots,
            'show_repeat_view_teaser' => filter_var($d['show_repeat_view_teaser'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'show_match_teaser' => filter_var($d['show_match_teaser'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'match_teaser_min_score' => $minMatch,
            'apply_who_viewed_locked' => filter_var($d['apply_who_viewed_locked'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'partial_plan_list_order' => $listOrder,
            'who_viewed_per_page' => $perPage,
        ];
    }
}
