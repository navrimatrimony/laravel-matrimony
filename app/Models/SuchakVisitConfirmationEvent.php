<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakVisitConfirmationEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const EVENT_SCHEDULED = 'scheduled';
    public const EVENT_SUCHAK_COMPLETED = 'suchak_completed';
    public const EVENT_USER_CONFIRMED = 'user_confirmed';
    public const EVENT_ADMIN_CONFIRMED = 'admin_confirmed';
    public const EVENT_DISPUTED = 'disputed';
    public const EVENT_PAYOUT_QUALIFIED = 'payout_qualified';

    public const EVENTS = [
        self::EVENT_SCHEDULED,
        self::EVENT_SUCHAK_COMPLETED,
        self::EVENT_USER_CONFIRMED,
        self::EVENT_ADMIN_CONFIRMED,
        self::EVENT_DISPUTED,
        self::EVENT_PAYOUT_QUALIFIED,
    ];

    public const ACTOR_SUCHAK = 'suchak';
    public const ACTOR_USER = 'user';
    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_SYSTEM = 'system';

    protected $table = 'suchak_visit_confirmation_events';

    protected $fillable = [
        'visit_confirmation_id',
        'pipeline_id',
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

    public function visitConfirmation(): BelongsTo
    {
        return $this->belongsTo(SuchakVisitConfirmation::class, 'visit_confirmation_id');
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(SuchakPipeline::class, 'pipeline_id');
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
        throw new RuntimeException('Suchak visit confirmation events are immutable and cannot be modified or deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak visit confirmation events are immutable and cannot be modified or deleted.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('Suchak visit confirmation events are immutable and cannot be modified or deleted.');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('Suchak visit confirmation events are immutable and cannot be modified or deleted.');
        }

        return parent::save($options);
    }
}
