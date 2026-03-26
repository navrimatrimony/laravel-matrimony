<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShowcaseChatSetting extends Model
{
    protected $table = 'showcase_chat_settings';

    protected $fillable = [
        'matrimony_profile_id',
        'enabled',
        'ai_assisted_replies_enabled',
        'admin_takeover_enabled',
        'business_hours_enabled',
        'business_days_json',
        'business_hours_start',
        'business_hours_end',
        'off_hours_online_allowed',
        'off_hours_read_allowed',
        'off_hours_reply_allowed',
        'online_session_min_minutes',
        'online_session_max_minutes',
        'offline_gap_min_minutes',
        'offline_gap_max_minutes',
        'online_before_read_min_seconds',
        'online_before_read_max_seconds',
        'online_linger_after_reply_min_seconds',
        'online_linger_after_reply_max_seconds',
        'read_delay_min_minutes',
        'read_delay_max_minutes',
        'read_only_when_online',
        'force_read_by_max_hours',
        'batch_read_enabled',
        'batch_read_window_min_minutes',
        'batch_read_window_max_minutes',
        'reply_delay_min_minutes',
        'reply_delay_max_minutes',
        'reply_after_read_min_minutes',
        'reply_after_read_max_minutes',
        'max_replies_per_day',
        'max_replies_per_conversation_per_day',
        'cooldown_after_last_outgoing_min_minutes',
        'cooldown_after_last_outgoing_max_minutes',
        'typing_enabled',
        'typing_duration_min_seconds',
        'typing_duration_max_seconds',
        'reply_probability_percent',
        'initiate_probability_percent',
        'no_reply_after_unanswered_count',
        'pause_on_sensitive_keywords',
        'is_paused',
        'personality_preset',
        'reply_length_min_words',
        'reply_length_max_words',
        'style_variation_enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'ai_assisted_replies_enabled' => 'boolean',
        'admin_takeover_enabled' => 'boolean',
        'business_hours_enabled' => 'boolean',
        'business_days_json' => 'array',
        'off_hours_online_allowed' => 'boolean',
        'off_hours_read_allowed' => 'boolean',
        'off_hours_reply_allowed' => 'boolean',
        'read_only_when_online' => 'boolean',
        'batch_read_enabled' => 'boolean',
        'typing_enabled' => 'boolean',
        'pause_on_sensitive_keywords' => 'boolean',
        'is_paused' => 'boolean',
        'style_variation_enabled' => 'boolean',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'matrimony_profile_id');
    }
}

