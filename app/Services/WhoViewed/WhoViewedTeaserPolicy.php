<?php

namespace App\Services\WhoViewed;

use App\Models\AdminSetting;

/**
 * Admin-tunable privacy + copy for "who viewed me" locked teasers. Stored as JSON in {@see AdminSetting}
 * key {@see WhoViewedTeaserPolicy::SETTING_KEY} — no new DB columns.
 */
final class WhoViewedTeaserPolicy
{
    public const SETTING_KEY = 'who_viewed_teaser_policy_json';

    /** @var list<string> */
    public const LOCATION_GRANULARITIES = ['state_only', 'district_and_above', 'taluka_and_above'];

    /** @var list<string> */
    public const AGE_MODES = ['off', 'decade', 'exact'];

    /** @var list<string> */
    public const NAME_DISPLAYS = ['hidden', 'first_only', 'full'];

    /** Teaser card left column: icon only, or blurred approved photo (still anonymous). */
    public const TEASER_AVATAR_STYLES = ['silhouette', 'blur'];

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
     *   teaser_viewed_time: string,
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
     *   teaser_viewed_time: string,
     * }
     */
    private static function normalizeRow(array $d): array
    {
        $loc = strtolower(trim((string) ($d['location_granularity'] ?? 'district_and_above')));
        if (! in_array($loc, self::LOCATION_GRANULARITIES, true)) {
            $loc = 'district_and_above';
        }

        $age = strtolower(trim((string) ($d['show_age_mode'] ?? 'decade')));
        if (! in_array($age, self::AGE_MODES, true)) {
            $age = 'decade';
        }

        $name = strtolower(trim((string) ($d['name_display'] ?? 'hidden')));
        if (! in_array($name, self::NAME_DISPLAYS, true)) {
            $name = 'hidden';
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

        return [
            'location_granularity' => $loc,
            'show_age_mode' => $age,
            'show_occupation' => filter_var($d['show_occupation'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'show_education' => filter_var($d['show_education'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'show_marital_status' => filter_var($d['show_marital_status'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'name_display' => $name,
            'locked_teaser_rows' => $rows,
            'teaser_avatar_style' => $avatar,
            'teaser_viewed_time' => $timeMode,
        ];
    }
}
