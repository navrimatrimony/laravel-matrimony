<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user feature consumption buckets (e.g. {@see \App\Support\UserFeatureUsageKeys::CONTACT_VIEW}, {@see \App\Support\UserFeatureUsageKeys::MEDIATOR_REQUEST}).
 *
 * Table name is `user_feature_usage` (singular, existing SSOT). `period_start` / `period_end` bound the bucket (monthly: first–last day of month).
 */
class UserFeatureUsage extends Model
{
    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_DAILY = 'daily';

    protected $table = 'user_feature_usage';

    protected $fillable = [
        'user_id',
        'feature_key',
        'used_count',
        'period',
        'period_start',
        'period_end',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'used_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
