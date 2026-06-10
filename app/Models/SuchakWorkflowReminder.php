<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakWorkflowReminder extends Model
{
    use HasFactory;

    public const TYPE_FOLLOW_UP = 'follow_up';
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_CONSENT = 'consent';
    public const TYPE_MEETING = 'meeting';

    public const TYPES = [
        self::TYPE_FOLLOW_UP,
        self::TYPE_PAYMENT,
        self::TYPE_CONSENT,
        self::TYPE_MEETING,
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_SKIPPED,
    ];

    public const CHANNEL_WHATSAPP_COPY = 'whatsapp_copy';

    public const PROVIDER_PENDING_CREDENTIALS = 'pending_credentials';

    protected $table = 'suchak_workflow_reminders';

    protected $fillable = [
        'suchak_account_id',
        'customer_context_id',
        'matrimony_profile_id',
        'source_type',
        'source_id',
        'reminder_type',
        'reminder_key',
        'template_key',
        'channel',
        'provider_status',
        'reminder_status',
        'due_at',
        'generated_for_date',
        'last_generated_at',
        'acknowledged_at',
        'skipped_at',
        'message_copy',
        'metadata_json',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'generated_for_date' => 'date',
        'last_generated_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'skipped_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function matrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class);
    }

    public function timelineEvents(): HasMany
    {
        return $this->hasMany(SuchakWorkflowTimelineEvent::class, 'workflow_reminder_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak workflow reminders cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak workflow reminders cannot be deleted.');
    }
}
