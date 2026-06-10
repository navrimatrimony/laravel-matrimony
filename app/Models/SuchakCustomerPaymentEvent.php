<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakCustomerPaymentEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const EVENT_PAYMENT_RECORDED = 'customer_payment_recorded';
    public const EVENT_PAYMENT_DOCUMENT_ISSUED = 'customer_payment_document_issued';

    public const EVENTS = [
        self::EVENT_PAYMENT_RECORDED,
        self::EVENT_PAYMENT_DOCUMENT_ISSUED,
    ];

    protected $table = 'suchak_customer_payment_events';

    protected $fillable = [
        'customer_payment_id',
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

    public function customerPayment(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerPayment::class, 'customer_payment_id');
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
        throw new RuntimeException('Suchak customer payment events are immutable and cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak customer payment events are immutable and cannot be deleted.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('Suchak customer payment events are immutable and cannot be modified.');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('Suchak customer payment events are immutable and cannot be modified.');
        }

        return parent::save($options);
    }
}
