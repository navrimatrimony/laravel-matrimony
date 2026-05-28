<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserReferral extends Model
{
    public const STATUS_PENDING_CLAIM = 'pending_claim';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_CAP_SKIPPED = 'cap_skipped';

    public const STATUS_ADMIN_CANCELLED = 'admin_cancelled';

    public const STATUS_PENDING_EXPIRED = 'pending_expired';

    public const STATUS_QUALITY_PENDING = 'quality_pending';

    public const STATUS_REWARD_REVOKED = 'reward_revoked';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_PENDING = 'pending_review';

    public const REVIEW_REJECTED = 'rejected';

    protected $fillable = [
        'referrer_id',
        'referred_user_id',
        'reward_applied',
        'review_status',
        'fraud_flags',
        'fraud_notes',
        'registration_ip',
        'reviewed_at',
        'reviewed_by_admin_id',
        'reward_status',
        'pending_plan_id',
        'pending_reward',
        'pending_claim_at',
        'referred_checkout_bonus_used_at',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'renewal_micro_bonus_applied_at',
    ];

    protected $casts = [
        'reward_applied' => 'boolean',
        'fraud_flags' => 'array',
        'pending_reward' => 'array',
        'reviewed_at' => 'datetime',
        'pending_claim_at' => 'datetime',
        'referred_checkout_bonus_used_at' => 'datetime',
        'renewal_micro_bonus_applied_at' => 'datetime',
    ];

    public function isReferrerRewardEligible(): bool
    {
        return ! in_array((string) $this->review_status, [self::REVIEW_PENDING, self::REVIEW_REJECTED], true);
    }

    public function isReferredBuyerBenefitEligible(): bool
    {
        return (string) $this->review_status !== self::REVIEW_REJECTED;
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }
}
