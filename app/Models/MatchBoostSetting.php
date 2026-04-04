<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchBoostSetting extends Model
{
    protected $table = 'match_boost_settings';

    protected $fillable = [
        'use_ai',
        'ai_provider',
        'ai_model',
        'boost_active_weight',
        'boost_premium_weight',
        'boost_similarity_weight',
        'max_boost_limit',
        'boost_gold_extra',
        'boost_silver_extra',
        'active_within_days',
    ];

    protected $casts = [
        'use_ai' => 'boolean',
        'boost_active_weight' => 'integer',
        'boost_premium_weight' => 'integer',
        'boost_similarity_weight' => 'integer',
        'max_boost_limit' => 'integer',
        'boost_gold_extra' => 'integer',
        'boost_silver_extra' => 'integer',
        'active_within_days' => 'integer',
    ];

    /**
     * Singleton row for admin-configured match boost (creates defaults if missing).
     */
    public static function current(): self
    {
        $row = static::query()->orderBy('id')->first();
        if ($row !== null) {
            return $row;
        }

        return static::query()->create([
            'use_ai' => false,
            'ai_provider' => null,
            'ai_model' => 'sarvam-105b',
            'boost_active_weight' => 3,
            'boost_premium_weight' => 2,
            'boost_similarity_weight' => 3,
            'max_boost_limit' => 20,
            'boost_gold_extra' => 10,
            'boost_silver_extra' => 5,
            'active_within_days' => 7,
        ]);
    }
}
