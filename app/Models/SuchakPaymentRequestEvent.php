<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakPaymentRequestEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const EVENT_CREATED = 'payment_request_created';
    public const EVENT_SENT = 'payment_request_sent';
    public const EVENT_OPENED = 'payment_request_opened';
    public const EVENT_CANCELLED = 'payment_request_cancelled';
    public const EVENT_EXPIRED = 'payment_request_expired';

    public const EVENTS = [
        self::EVENT_CREATED,
        self::EVENT_SENT,
        self::EVENT_OPENED,
        self::EVENT_CANCELLED,
        self::EVENT_EXPIRED,
    ];

    protected $table = 'suchak_payment_request_events';

    protected $fillable = [
        'payment_request_id',
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

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(SuchakPaymentRequest::class, 'payment_request_id');
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
        throw new RuntimeException('Suchak payment request events are immutable and cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak payment request events are immutable and cannot be deleted.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('Suchak payment request events are immutable and cannot be modified.');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('Suchak payment request events are immutable and cannot be modified.');
        }

        return parent::save($options);
    }
}
