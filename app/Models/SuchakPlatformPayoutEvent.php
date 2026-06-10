<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakPlatformPayoutEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const EVENT_QUALIFIED = 'qualified';
    public const EVENT_DETAILS_UPDATED = 'details_updated';
    public const EVENT_STATUS_HELD = 'status_held';
    public const EVENT_APPROVED = 'approved';
    public const EVENT_PAID = 'paid';
    public const EVENT_REVERSED = 'reversed';
    public const EVENT_CANCELLED = 'cancelled';
    public const EVENT_SETTLEMENT_REGENERATED = 'settlement_regenerated';

    public const EVENTS = [
        self::EVENT_QUALIFIED,
        self::EVENT_DETAILS_UPDATED,
        self::EVENT_STATUS_HELD,
        self::EVENT_APPROVED,
        self::EVENT_PAID,
        self::EVENT_REVERSED,
        self::EVENT_CANCELLED,
        self::EVENT_SETTLEMENT_REGENERATED,
    ];

    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_SYSTEM = 'system';

    protected $table = 'suchak_platform_payout_events';

    protected $fillable = [
        'platform_payout_id',
        'settlement_statement_id',
        'suchak_account_id',
        'event_type',
        'actor_type',
        'actor_user_id',
        'from_status',
        'to_status',
        'event_note',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function platformPayout(): BelongsTo
    {
        return $this->belongsTo(SuchakPlatformPayout::class, 'platform_payout_id');
    }

    public function settlementStatement(): BelongsTo
    {
        return $this->belongsTo(SuchakPlatformPayoutSettlement::class, 'settlement_statement_id');
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
        throw new RuntimeException('Suchak platform payout events are immutable and cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak platform payout events are immutable and cannot be deleted.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('Suchak platform payout events are immutable and cannot be modified.');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('Suchak platform payout events are immutable and cannot be modified.');
        }

        return parent::save($options);
    }
}
