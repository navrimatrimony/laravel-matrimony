<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakGrowthAttribution extends Model
{
    use HasFactory;

    public const SOURCE_REFERRAL_CODE = 'referral_code';
    public const SOURCE_COUPON_CODE = 'coupon_code';
    public const SOURCE_ADMIN_OVERRIDE = 'admin_override';

    public const SOURCES = [
        self::SOURCE_REFERRAL_CODE,
        self::SOURCE_COUPON_CODE,
        self::SOURCE_ADMIN_OVERRIDE,
    ];

    public const POLICY_FIRST_TOUCH = 'first_touch';
    public const POLICY_LAST_TOUCH = 'last_touch';
    public const POLICY_COUPON_PRIORITY = 'coupon_priority';
    public const POLICY_ADMIN_OVERRIDE = 'admin_override';

    public const POLICIES = [
        self::POLICY_FIRST_TOUCH,
        self::POLICY_LAST_TOUCH,
        self::POLICY_COUPON_PRIORITY,
        self::POLICY_ADMIN_OVERRIDE,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVIEW_REQUIRED = 'review_required';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REWARDED = 'rewarded';
    public const STATUS_REVERSED = 'reversed';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_REVIEW_REQUIRED,
        self::STATUS_REJECTED,
        self::STATUS_REWARDED,
        self::STATUS_REVERSED,
    ];

    public const FRAUD_CLEAR = 'clear';
    public const FRAUD_REVIEW_REQUIRED = 'review_required';
    public const FRAUD_REJECTED = 'rejected';

    public const FRAUD_STATUSES = [
        self::FRAUD_CLEAR,
        self::FRAUD_REVIEW_REQUIRED,
        self::FRAUD_REJECTED,
    ];

    protected $table = 'suchak_growth_attributions';

    protected $fillable = [
        'suchak_account_id',
        'attributed_user_id',
        'matrimony_profile_id',
        'customer_context_id',
        'payment_context_id',
        'attribution_source',
        'attribution_policy',
        'attribution_key',
        'referral_code',
        'coupon_code',
        'attribution_status',
        'fraud_status',
        'fraud_flags',
        'attribution_note',
        'attributed_by_admin_user_id',
        'attributed_at',
    ];

    protected $casts = [
        'fraud_flags' => 'array',
        'attributed_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function attributedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attributed_user_id');
    }

    public function matrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class);
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function paymentContext(): BelongsTo
    {
        return $this->belongsTo(SuchakPaymentContext::class, 'payment_context_id');
    }

    public function attributedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attributed_by_admin_user_id');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(SuchakGrowthReward::class, 'growth_attribution_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SuchakGrowthRewardEvent::class, 'growth_attribution_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak growth attribution records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak growth attribution records cannot be deleted.');
    }
}
