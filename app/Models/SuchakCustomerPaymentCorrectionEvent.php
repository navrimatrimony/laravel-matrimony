<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakCustomerPaymentCorrectionEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const EVENT_REFUND_REQUESTED = 'refund_requested';
    public const EVENT_REFUND_APPROVED = 'refund_approved';
    public const EVENT_REFUND_PAID = 'refund_paid';
    public const EVENT_WAIVER_POSTED = 'waiver_posted';
    public const EVENT_CREDIT_NOTE_ISSUED = 'credit_note_issued';
    public const EVENT_REVERSAL_POSTED = 'reversal_posted';

    public const EVENTS = [
        self::EVENT_REFUND_REQUESTED,
        self::EVENT_REFUND_APPROVED,
        self::EVENT_REFUND_PAID,
        self::EVENT_WAIVER_POSTED,
        self::EVENT_CREDIT_NOTE_ISSUED,
        self::EVENT_REVERSAL_POSTED,
    ];

    protected $table = 'suchak_customer_payment_correction_events';

    protected $fillable = [
        'payment_correction_id',
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

    public function paymentCorrection(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerPaymentCorrection::class, 'payment_correction_id');
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
        throw new RuntimeException('Suchak customer payment correction events are immutable and cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak customer payment correction events are immutable and cannot be deleted.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('Suchak customer payment correction events are immutable and cannot be modified.');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('Suchak customer payment correction events are immutable and cannot be modified.');
        }

        return parent::save($options);
    }
}
