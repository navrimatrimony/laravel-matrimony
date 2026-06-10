<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakGrowthRewardRule extends Model
{
    use HasFactory;

    public const TRIGGER_PLATFORM_PAYMENT_CONFIRMED = 'platform_payment_confirmed';

    public const TRIGGERS = [
        self::TRIGGER_PLATFORM_PAYMENT_CONFIRMED,
    ];

    public const TYPE_CASH = 'cash';
    public const TYPE_CREDIT = 'credit';
    public const TYPE_ADMIN_ACTION = 'admin_action';

    public const TYPES = [
        self::TYPE_CASH,
        self::TYPE_CREDIT,
        self::TYPE_ADMIN_ACTION,
    ];

    protected $table = 'suchak_growth_reward_rules';

    protected $fillable = [
        'rule_key',
        'reward_trigger',
        'reward_type',
        'attribution_policy',
        'reward_amount',
        'reward_currency',
        'credit_value',
        'admin_action_key',
        'is_active',
        'starts_at',
        'ends_at',
        'created_by_admin_user_id',
    ];

    protected $casts = [
        'reward_amount' => 'decimal:2',
        'credit_value' => 'decimal:2',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_user_id');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(SuchakGrowthReward::class, 'reward_rule_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak growth reward rules cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak growth reward rules cannot be deleted.');
    }
}
