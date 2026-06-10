<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakGrowthReward extends Model
{
    use HasFactory;

    public const SOURCE_PLATFORM_CONFIRMED_PAYMENT = 'platform_confirmed_payment';

    public const QUALIFICATION_SOURCES = [
        self::SOURCE_PLATFORM_CONFIRMED_PAYMENT,
    ];

    public const STATUS_QUALIFIED = 'qualified';
    public const STATUS_REVIEW_REQUIRED = 'review_required';
    public const STATUS_PAYOUT_QUALIFIED = 'payout_qualified';
    public const STATUS_CREDITED = 'credited';
    public const STATUS_ADMIN_ACTION_PENDING = 'admin_action_pending';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_QUALIFIED,
        self::STATUS_REVIEW_REQUIRED,
        self::STATUS_PAYOUT_QUALIFIED,
        self::STATUS_CREDITED,
        self::STATUS_ADMIN_ACTION_PENDING,
        self::STATUS_REVERSED,
        self::STATUS_REJECTED,
    ];

    protected $table = 'suchak_growth_rewards';

    protected $fillable = [
        'growth_attribution_id',
        'reward_rule_id',
        'suchak_account_id',
        'customer_context_id',
        'payment_context_id',
        'matrimony_profile_id',
        'platform_payout_id',
        'platform_event_key',
        'reward_trigger',
        'reward_type',
        'reward_status',
        'reward_amount',
        'reward_currency',
        'credit_value',
        'admin_action_key',
        'qualification_source',
        'fraud_status',
        'fraud_flags',
        'qualified_by_admin_user_id',
        'qualified_at',
        'reversed_by_admin_user_id',
        'reversed_at',
        'reversal_reason',
    ];

    protected $casts = [
        'reward_amount' => 'decimal:2',
        'credit_value' => 'decimal:2',
        'fraud_flags' => 'array',
        'qualified_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function attribution(): BelongsTo
    {
        return $this->belongsTo(SuchakGrowthAttribution::class, 'growth_attribution_id');
    }

    public function rewardRule(): BelongsTo
    {
        return $this->belongsTo(SuchakGrowthRewardRule::class, 'reward_rule_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function paymentContext(): BelongsTo
    {
        return $this->belongsTo(SuchakPaymentContext::class, 'payment_context_id');
    }

    public function matrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class);
    }

    public function platformPayout(): BelongsTo
    {
        return $this->belongsTo(SuchakPlatformPayout::class, 'platform_payout_id');
    }

    public function qualifiedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qualified_by_admin_user_id');
    }

    public function reversedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by_admin_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SuchakGrowthRewardEvent::class, 'growth_reward_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak growth reward records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak growth reward records cannot be deleted.');
    }
}
