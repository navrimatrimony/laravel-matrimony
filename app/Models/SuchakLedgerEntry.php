<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakLedgerEntry extends Model
{
    use HasFactory;

    public const TYPE_REGISTRATION_FEE_EXPECTED = 'registration_fee_expected';
    public const TYPE_SUCCESS_FEE_EXPECTED = 'success_fee_expected';
    public const TYPE_PAYMENT_REMINDER = 'payment_reminder';
    public const TYPE_CONVERTED_MATCH = 'converted_match';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_CUSTOMER_PAYMENT_RECORDED = 'customer_payment_recorded';

    public const TYPES = [
        self::TYPE_REGISTRATION_FEE_EXPECTED,
        self::TYPE_SUCCESS_FEE_EXPECTED,
        self::TYPE_PAYMENT_REMINDER,
        self::TYPE_CONVERTED_MATCH,
        self::TYPE_ADJUSTMENT,
        self::TYPE_CUSTOMER_PAYMENT_RECORDED,
    ];

    public const STATUS_EXPECTED = 'expected';
    public const STATUS_DUE = 'due';
    public const STATUS_PAID = 'paid';
    public const STATUS_WAIVED = 'waived';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_EXPECTED,
        self::STATUS_DUE,
        self::STATUS_PAID,
        self::STATUS_WAIVED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'suchak_ledger_entries';

    protected $fillable = [
        'suchak_account_id',
        'matrimony_profile_id',
        'pipeline_id',
        'collaboration_request_id',
        'payment_context_id',
        'entry_type',
        'amount',
        'currency',
        'status',
        'due_date',
        'paid_at',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date',
        'paid_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function matrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(SuchakPipeline::class, 'pipeline_id');
    }

    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(SuchakCollaborationRequest::class, 'collaboration_request_id');
    }

    public function paymentContext(): BelongsTo
    {
        return $this->belongsTo(SuchakPaymentContext::class, 'payment_context_id');
    }

    public function customerPayments(): HasMany
    {
        return $this->hasMany(SuchakCustomerPayment::class, 'ledger_entry_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak ledger entries cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak ledger entries cannot be deleted.');
    }
}
