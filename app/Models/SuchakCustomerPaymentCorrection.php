<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakCustomerPaymentCorrection extends Model
{
    use HasFactory;

    public const TYPE_REFUND = 'refund';
    public const TYPE_WAIVER = 'waiver';
    public const TYPE_CREDIT_NOTE = 'credit_note';
    public const TYPE_REVERSAL = 'reversal';

    public const TYPES = [
        self::TYPE_REFUND,
        self::TYPE_WAIVER,
        self::TYPE_CREDIT_NOTE,
        self::TYPE_REVERSAL,
    ];

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';
    public const STATUS_POSTED = 'posted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_PAID,
        self::STATUS_POSTED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'suchak_customer_payment_corrections';

    protected $fillable = [
        'customer_payment_id',
        'suchak_account_id',
        'customer_context_id',
        'payment_request_id',
        'ledger_entry_id',
        'correction_type',
        'correction_status',
        'amount',
        'currency',
        'reason',
        'document_number',
        'fy_label',
        'sequence_no',
        'requested_by_user_id',
        'requested_at',
        'approved_by_user_id',
        'approved_at',
        'paid_by_user_id',
        'paid_at',
        'posted_by_user_id',
        'posted_at',
        'cancelled_by_user_id',
        'cancelled_at',
        'status_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'sequence_no' => 'integer',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function customerPayment(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerPayment::class, 'customer_payment_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(SuchakPaymentRequest::class, 'payment_request_id');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(SuchakLedgerEntry::class, 'ledger_entry_id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SuchakCustomerPaymentCorrectionEvent::class, 'payment_correction_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak customer payment corrections cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak customer payment corrections cannot be deleted.');
    }
}
