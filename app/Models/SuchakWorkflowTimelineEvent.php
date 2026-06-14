<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakWorkflowTimelineEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const EVENT_REMINDER_GENERATED = 'reminder_generated';

    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_SUCHAK = 'suchak';
    public const ACTOR_ADMIN = 'admin';

    protected $table = 'suchak_workflow_timeline_events';

    protected $fillable = [
        'suchak_account_id',
        'workflow_reminder_id',
        'customer_context_id',
        'matrimony_profile_id',
        'event_type',
        'source_type',
        'source_id',
        'actor_type',
        'actor_user_id',
        'event_title',
        'event_title_mr',
        'event_summary',
        'event_summary_mr',
        'metadata_json',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function workflowReminder(): BelongsTo
    {
        return $this->belongsTo(SuchakWorkflowReminder::class, 'workflow_reminder_id');
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function matrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak workflow timeline events are immutable and cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak workflow timeline events are immutable and cannot be deleted.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('Suchak workflow timeline events are immutable and cannot be modified.');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('Suchak workflow timeline events are immutable and cannot be modified.');
        }

        return parent::save($options);
    }
}
