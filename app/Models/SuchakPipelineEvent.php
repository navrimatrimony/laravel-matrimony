<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakPipelineEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const EVENT_REQUEST_CREATED = 'request_created';
    public const EVENT_SUCHAK_VIEWED = 'suchak_viewed';
    public const EVENT_SUCHAK_ACCEPTED = 'suchak_accepted';
    public const EVENT_FORWARDED_TO_CANDIDATE = 'forwarded_to_candidate';
    public const EVENT_CANDIDATE_INTERESTED = 'candidate_interested';
    public const EVENT_CANDIDATE_NOT_INTERESTED = 'candidate_not_interested';
    public const EVENT_MEETING_SCHEDULED = 'meeting_scheduled';
    public const EVENT_MEETING_COMPLETED = 'meeting_completed';
    public const EVENT_CONVERTED = 'converted';
    public const EVENT_CLOSED = 'closed';
    public const EVENT_EXPIRED = 'expired';

    public const EVENTS = [
        self::EVENT_REQUEST_CREATED,
        self::EVENT_SUCHAK_VIEWED,
        self::EVENT_SUCHAK_ACCEPTED,
        self::EVENT_FORWARDED_TO_CANDIDATE,
        self::EVENT_CANDIDATE_INTERESTED,
        self::EVENT_CANDIDATE_NOT_INTERESTED,
        self::EVENT_MEETING_SCHEDULED,
        self::EVENT_MEETING_COMPLETED,
        self::EVENT_CONVERTED,
        self::EVENT_CLOSED,
        self::EVENT_EXPIRED,
    ];

    public const ACTOR_USER = 'user';
    public const ACTOR_SUCHAK = 'suchak';
    public const ACTOR_CANDIDATE = 'candidate';
    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_SYSTEM = 'system';

    protected $table = 'suchak_pipeline_events';

    protected $fillable = [
        'pipeline_id',
        'event_type',
        'actor_type',
        'actor_id',
        'event_note',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(SuchakPipeline::class, 'pipeline_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak pipeline events are immutable and cannot be modified or deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak pipeline events are immutable and cannot be modified or deleted.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('Suchak pipeline events are immutable and cannot be modified or deleted.');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('Suchak pipeline events are immutable and cannot be modified or deleted.');
        }

        return parent::save($options);
    }
}
