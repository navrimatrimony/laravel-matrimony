<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakGrowthRewardEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const EVENT_ATTRIBUTION_RECORDED = 'attribution_recorded';
    public const EVENT_FRAUD_REVIEW_REQUIRED = 'fraud_review_required';
    public const EVENT_REWARD_RULE_CREATED = 'reward_rule_created';
    public const EVENT_REWARD_QUALIFIED = 'reward_qualified';
    public const EVENT_REWARD_REVERSED = 'reward_reversed';

    public const EVENTS = [
        self::EVENT_ATTRIBUTION_RECORDED,
        self::EVENT_FRAUD_REVIEW_REQUIRED,
        self::EVENT_REWARD_RULE_CREATED,
        self::EVENT_REWARD_QUALIFIED,
        self::EVENT_REWARD_REVERSED,
    ];

    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_SYSTEM = 'system';

    protected $table = 'suchak_growth_reward_events';

    protected $fillable = [
        'growth_reward_id',
        'growth_attribution_id',
        'suchak_account_id',
        'event_type',
        'actor_type',
        'actor_user_id',
        'from_status',
        'to_status',
        'event_note',
        'metadata_json',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function reward(): BelongsTo
    {
        return $this->belongsTo(SuchakGrowthReward::class, 'growth_reward_id');
    }

    public function attribution(): BelongsTo
    {
        return $this->belongsTo(SuchakGrowthAttribution::class, 'growth_attribution_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak growth reward events are immutable and cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak growth reward events are immutable and cannot be deleted.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('Suchak growth reward events are immutable and cannot be modified.');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('Suchak growth reward events are immutable and cannot be modified.');
        }

        return parent::save($options);
    }
}
