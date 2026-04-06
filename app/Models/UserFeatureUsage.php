<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user feature consumption buckets (contact credits, mediator limits, interest sends, etc.).
 *
 * Table `user_feature_usages`. Bucket identity is
 * (user_id, feature_key, period, period_start); {@see UserFeatureUsageService} sets the period type and window dates.
 */
class UserFeatureUsage extends Model
{
    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_DAILY = 'daily';

    /** Entitlement-window buckets from {@see FeatureUsageService::consume} (non calendar daily/monthly). */
    public const PERIOD_ENTITLEMENT = 'entitlement';

    protected $table = 'user_feature_usages';

    protected $fillable = [
        'user_id',
        'feature_key',
        'period',
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
