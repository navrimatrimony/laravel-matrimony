<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakCustomerPayment extends Model
{
    use HasFactory;

    public const CHANNEL_SUCHAK_DIRECT = 'suchak_direct';

    public const MODE_UPI = 'upi';
    public const MODE_CASH = 'cash';
    public const MODE_BANK_TRANSFER = 'bank_transfer';
    public const MODE_CHEQUE = 'cheque';

    public const PAYMENT_MODES = [
        self::MODE_UPI,
        self::MODE_CASH,
        self::MODE_BANK_TRANSFER,
        self::MODE_CHEQUE,
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    public const PAYMENT_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PARTIALLY_PAID,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
        self::STATUS_FAILED,
    ];

    public const PROOF_NOT_REQUIRED = 'not_required';
    public const PROOF_REQUIRED = 'required';
    public const PROOF_SUBMITTED = 'submitted';
    public const PROOF_VERIFIED = 'verified';
    public const PROOF_REJECTED = 'rejected';

    public const PROOF_STATUSES = [
        self::PROOF_NOT_REQUIRED,
        self::PROOF_REQUIRED,
        self::PROOF_SUBMITTED,
        self::PROOF_VERIFIED,
        self::PROOF_REJECTED,
    ];

    protected $table = 'suchak_customer_payments';

    protected $fillable = [
        'suchak_account_id',
        'customer_context_id',
        'service_package_id',
        'customer_agreement_id',
        'payment_context_id',
        'payment_request_id',
        'ledger_entry_id',
        'recorded_by_user_id',
        'collection_channel',
        'payment_mode',
        'payment_status',
        'amount_due',
        'amount_received',
        'balance_amount',
        'currency',
        'payment_received_at',
        'payment_reference',
        'proof_status',
        'proof_document_path',
        'proof_note',
        'proof_verified_by_user_id',
        'proof_verified_at',
        'proof_rejection_reason',
        'collection_note',
    ];

    protected $casts = [
        'amount_due' => 'decimal:2',
        'amount_received' => 'decimal:2',
        'balance_amount' => 'decimal:2',
        'payment_received_at' => 'datetime',
        'proof_verified_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(SuchakServicePackage::class, 'service_package_id');
    }

    public function customerAgreement(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerAgreement::class, 'customer_agreement_id');
    }

    public function paymentContext(): BelongsTo
    {
        return $this->belongsTo(SuchakPaymentContext::class, 'payment_context_id');
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(SuchakPaymentRequest::class, 'payment_request_id');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(SuchakLedgerEntry::class, 'ledger_entry_id');
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function proofVerifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proof_verified_by_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SuchakCustomerPaymentDocument::class, 'customer_payment_id')
            ->orderBy('document_type')
            ->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SuchakCustomerPaymentEvent::class, 'customer_payment_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(SuchakCustomerPaymentCorrection::class, 'customer_payment_id')
            ->orderByDesc('id');
    }

    public function overdueServiceActions(): HasMany
    {
        return $this->hasMany(SuchakCustomerOverdueServiceAction::class, 'customer_payment_id')
            ->orderByDesc('id');
    }

    public function invoiceDocument(): ?SuchakCustomerPaymentDocument
    {
        return $this->documents
            ->firstWhere('document_type', SuchakCustomerPaymentDocument::TYPE_INVOICE);
    }

    public function receiptDocument(): ?SuchakCustomerPaymentDocument
    {
        return $this->documents
            ->firstWhere('document_type', SuchakCustomerPaymentDocument::TYPE_RECEIPT);
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak customer payment records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak customer payment records cannot be deleted.');
    }
}
