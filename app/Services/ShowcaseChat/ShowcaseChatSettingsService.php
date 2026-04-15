<?php

namespace App\Services\ShowcaseChat;

use App\Models\MatrimonyProfile;
use App\Models\ShowcaseChatSetting;

class ShowcaseChatSettingsService
{
    public const PERSONALITY_WARM = 'warm';

    public const PERSONALITY_BALANCED = 'balanced';

    public const PERSONALITY_SELECTIVE = 'selective';

    public const PERSONALITY_RESERVED = 'reserved';

    /** @var list<string> */
    public const PERSONALITY_PRESETS = [
        self::PERSONALITY_WARM,
        self::PERSONALITY_BALANCED,
        self::PERSONALITY_SELECTIVE,
        self::PERSONALITY_RESERVED,
    ];

    public static function normalizePersonalityPreset(string $value): string
    {
        $v = strtolower(trim($value));

        return in_array($v, self::PERSONALITY_PRESETS, true) ? $v : self::PERSONALITY_BALANCED;
    }

    /**
     * Light reply-probability tilt (applied after fatigue rules in the scheduler).
     */
    public static function personalityReplyProbabilityModifier(string $preset): int
    {
        return match (self::normalizePersonalityPreset($preset)) {
            self::PERSONALITY_WARM => 5,
            self::PERSONALITY_BALANCED => 0,
            self::PERSONALITY_SELECTIVE => -10,
            self::PERSONALITY_RESERVED => -15,
            default => 0,
        };
    }

    public function getOrCreateForProfile(MatrimonyProfile $profile): ShowcaseChatSetting
    {
        return ShowcaseChatSetting::firstOrCreate(
            ['matrimony_profile_id' => $profile->id],
            [
                // Defaults are set at migration-level, keep minimal here.
                'enabled' => false,
                'ai_assisted_replies_enabled' => false,
            ]
        );
    }

    public function isShowcaseChatEnabled(MatrimonyProfile $profile): bool
    {
        if (! $profile->isShowcaseProfile()) {
            return false;
        }

        $s = ShowcaseChatSetting::query()
            ->where('matrimony_profile_id', $profile->id)
            ->first();

        if (!$s) {
            return false;
        }

        return (bool) ($s->enabled && !$s->is_paused);
    }

    public function validateTimingPairs(array $data): array
    {
        $pairs = [
            ['online_session_min_minutes', 'online_session_max_minutes'],
            ['offline_gap_min_minutes', 'offline_gap_max_minutes'],
            ['online_before_read_min_seconds', 'online_before_read_max_seconds'],
            ['online_linger_after_reply_min_seconds', 'online_linger_after_reply_max_seconds'],
            ['read_delay_min_minutes', 'read_delay_max_minutes'],
            ['reply_delay_min_minutes', 'reply_delay_max_minutes'],
            ['reply_after_read_min_minutes', 'reply_after_read_max_minutes'],
            ['typing_duration_min_seconds', 'typing_duration_max_seconds'],
            ['batch_read_window_min_minutes', 'batch_read_window_max_minutes'],
            ['cooldown_after_last_outgoing_min_minutes', 'cooldown_after_last_outgoing_max_minutes'],
        ];

        foreach ($pairs as [$minKey, $maxKey]) {
            if (!array_key_exists($minKey, $data) || !array_key_exists($maxKey, $data)) {
                continue;
            }
            $min = $data[$minKey];
            $max = $data[$maxKey];
            if ($min === null || $max === null) {
                continue;
            }
            if ((int) $min > (int) $max) {
                throw new \InvalidArgumentException("Invalid timing range: {$minKey} must be <= {$maxKey}.");
            }
        }

        if (isset($data['reply_probability_percent']) && ((int) $data['reply_probability_percent'] < 0 || (int) $data['reply_probability_percent'] > 100)) {
            throw new \InvalidArgumentException('reply_probability_percent must be between 0 and 100.');
        }
        if (isset($data['initiate_probability_percent']) && ((int) $data['initiate_probability_percent'] < 0 || (int) $data['initiate_probability_percent'] > 100)) {
            throw new \InvalidArgumentException('initiate_probability_percent must be between 0 and 100.');
        }

        $this->validatePersonalityAndReplyLength($data);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function validatePersonalityAndReplyLength(array $data): void
    {
        if (array_key_exists('personality_preset', $data) && $data['personality_preset'] !== null && $data['personality_preset'] !== '') {
            $p = strtolower((string) $data['personality_preset']);
            if (! in_array($p, self::PERSONALITY_PRESETS, true)) {
                throw new \InvalidArgumentException('personality_preset must be one of: '.implode(', ', self::PERSONALITY_PRESETS).'.');
            }
        }

        $minKey = 'reply_length_min_words';
        $maxKey = 'reply_length_max_words';
        if (! array_key_exists($minKey, $data) && ! array_key_exists($maxKey, $data)) {
            return;
        }

        $min = array_key_exists($minKey, $data) && $data[$minKey] !== null && $data[$minKey] !== ''
            ? (int) $data[$minKey]
            : null;
        $max = array_key_exists($maxKey, $data) && $data[$maxKey] !== null && $data[$maxKey] !== ''
            ? (int) $data[$maxKey]
            : null;

        if ($min !== null && ($min < 1 || $min > 200)) {
            throw new \InvalidArgumentException('reply_length_min_words must be between 1 and 200.');
        }
        if ($max !== null && ($max < 1 || $max > 200)) {
            throw new \InvalidArgumentException('reply_length_max_words must be between 1 and 200.');
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new \InvalidArgumentException('reply_length_min_words must be <= reply_length_max_words.');
        }
    }
}

