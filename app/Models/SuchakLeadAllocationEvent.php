<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakLeadAllocationEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const EVENT_LEAD_CREATED = 'lead_created';
    public const EVENT_PREFERENCE_RECORDED = 'preference_recorded';
    public const EVENT_ALLOCATED = 'allocated';
    public const EVENT_ACCEPTED = 'accepted';
    public const EVENT_DECLINED = 'declined';
    public const EVENT_EXPIRED = 'expired';
    public const EVENT_CANCELLED = 'cancelled';

    public const EVENTS = [
        self::EVENT_LEAD_CREATED,
        self::EVENT_PREFERENCE_RECORDED,
        self::EVENT_ALLOCATED,
        self::EVENT_ACCEPTED,
        self::EVENT_DECLINED,
        self::EVENT_EXPIRED,
        self::EVENT_CANCELLED,
    ];

    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_SUCHAK = 'suchak';
    public const ACTOR_SYSTEM = 'system';

    protected $table = 'suchak_lead_allocation_events';

    protected $fillable = [
        'platform_lead_id',
        'lead_allocation_id',
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

    public function platformLead(): BelongsTo
    {
        return $this->belongsTo(SuchakPlatformLead::class, 'platform_lead_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(SuchakPlatformLeadAllocation::class, 'lead_allocation_id');
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
        throw new RuntimeException('Suchak lead allocation events are immutable and cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak lead allocation events are immutable and cannot be deleted.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('Suchak lead allocation events are immutable and cannot be modified.');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('Suchak lead allocation events are immutable and cannot be modified.');
        }

        return parent::save($options);
    }
}
