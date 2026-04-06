<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user feature consumption buckets (contact credits, mediator limits, interest sends, etc.).
 *
 * Table `user_feature_usages`. Bucket identity is
 * (user_id, feature_key, period_start, period_end); {@see UserFeatureUsageService} derives dates from {@see PERIOD_MONTHLY} / {@see PERIOD_DAILY}.
 */
class UserFeatureUsage extends Model
{
    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_DAILY = 'daily';

    protected $table = 'user_feature_usages';

    protected $fillable = [
        'user_id',
        'feature_key',
        'used_count',
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
