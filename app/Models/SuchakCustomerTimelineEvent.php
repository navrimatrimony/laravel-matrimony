<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakCustomerTimelineEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const EVENT_CONTEXT_CREATED = 'customer_context_created';
    public const EVENT_SOURCE_CLASSIFIED = 'source_classified';
    public const EVENT_LIFECYCLE_STATUS_CHANGED = 'lifecycle_status_changed';
    public const EVENT_PAYER_LINKED = 'payer_linked';
    public const EVENT_CONSENT_GIVER_LINKED = 'consent_giver_linked';

    public const EVENTS = [
        self::EVENT_CONTEXT_CREATED,
        self::EVENT_SOURCE_CLASSIFIED,
        self::EVENT_LIFECYCLE_STATUS_CHANGED,
        self::EVENT_PAYER_LINKED,
        self::EVENT_CONSENT_GIVER_LINKED,
    ];

    protected $table = 'suchak_customer_timeline_events';

    protected $fillable = [
        'customer_context_id',
        'suchak_account_id',
        'candidate_matrimony_profile_id',
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

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'candidate_matrimony_profile_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak customer timeline events are immutable and cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak customer timeline events are immutable and cannot be deleted.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('Suchak customer timeline events are immutable and cannot be modified.');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('Suchak customer timeline events are immutable and cannot be modified.');
        }

        return parent::save($options);
    }
}
