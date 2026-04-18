<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Member-facing presence / last-active copy from {@see User::$last_seen_at}.
 * Threshold minutes are configurable in admin ({@see self::SETTING_KEY_ONLINE_THRESHOLD_MINUTES}).
 */
class MemberPresencePresentationService
{
    public const SETTING_KEY_ONLINE_THRESHOLD_MINUTES = 'member_presence_online_threshold_minutes';

    public function onlineThresholdMinutes(): int
    {
        $v = (int) AdminSetting::getValue(self::SETTING_KEY_ONLINE_THRESHOLD_MINUTES, '5');

        return max(1, min(24 * 60, $v));
    }

    /**
     * Search / listing card chip (truthful; null = no badge after 30+ days).
     *
     * @return array{is_online: bool, label: string}|null
     */
    public function buildListingSummary(?User $user): ?array
    {
        if ($user === null || $user->last_seen_at === null) {
            return null;
        }

        $ls = $user->last_seen_at;
        $now = now();
        $thresholdMin = $this->onlineThresholdMinutes();

        if ($ls->greaterThanOrEqualTo($now->copy()->subMinutes($thresholdMin))) {
            return ['is_online' => true, 'label' => __('presence.listing_online_now')];
        }

        $diffDays = $this->calendarDaysAgo($ls, $now);
        if ($diffDays === 0) {
            return ['is_online' => true, 'label' => __('presence.listing_active_today')];
        }

        if ($diffDays >= 30) {
            return null;
        }

        if ($diffDays === 1) {
            return ['is_online' => false, 'label' => __('presence.listing_last_active_yesterday')];
        }

        if ($diffDays >= 2 && $diffDays <= 6) {
            return ['is_online' => false, 'label' => __('presence.listing_days_ago', ['days' => $diffDays])];
        }

        if ($diffDays >= 7 && $diffDays <= 13) {
            return ['is_online' => false, 'label' => __('presence.listing_one_week_ago')];
        }

        if ($diffDays >= 14 && $diffDays <= 29) {
            return ['is_online' => false, 'label' => __('presence.listing_two_weeks_ago')];
        }

        return null;
    }

    /**
     * Profile hero line when viewing someone else's profile (null = hide row).
     *
     * @return array{text: string, tone: 'live'|'inactive'}|null
     */
    public function buildProfileHeroPresence(?User $profileOwner): ?array
    {
        if ($profileOwner === null || $profileOwner->last_seen_at === null) {
            return null;
        }

        $ls = $profileOwner->last_seen_at;
        $now = now();
        $thresholdMin = $this->onlineThresholdMinutes();

        if ($ls->greaterThanOrEqualTo($now->copy()->subMinutes($thresholdMin))) {
            return ['text' => __('presence.hero_active_now'), 'tone' => 'live'];
        }

        $diffDays = $this->calendarDaysAgo($ls, $now);
        if ($diffDays === 0) {
            return ['text' => __('presence.hero_active_viewed_recently'), 'tone' => 'live'];
        }

        if ($diffDays >= 30) {
            return null;
        }

        if ($diffDays === 1) {
            return ['text' => __('presence.hero_last_active_yesterday'), 'tone' => 'inactive'];
        }

        if ($diffDays >= 2 && $diffDays <= 6) {
            return ['text' => __('presence.hero_last_active_days_ago', ['days' => $diffDays]), 'tone' => 'inactive'];
        }

        if ($diffDays >= 7 && $diffDays <= 13) {
            return ['text' => __('presence.hero_last_active_one_week'), 'tone' => 'inactive'];
        }

        if ($diffDays >= 14 && $diffDays <= 29) {
            return ['text' => __('presence.hero_last_active_two_weeks'), 'tone' => 'inactive'];
        }

        return null;
    }

    private function calendarDaysAgo(CarbonInterface $lastSeen, CarbonInterface $now): int
    {
        $lastDay = $lastSeen->copy()->startOfDay();
        $today = $now->copy()->startOfDay();

        return (int) $lastDay->diffInDays($today);
    }
}
