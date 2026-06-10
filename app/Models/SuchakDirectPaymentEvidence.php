<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakDirectPaymentEvidence extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const TYPE_PAYMENT_REQUEST_MESSAGE = 'payment_request_message';
    public const TYPE_UPI_OR_BANK_DETAIL = 'upi_or_bank_detail';
    public const TYPE_CALL_LOG = 'call_log';
    public const TYPE_SCREENSHOT_REFERENCE = 'screenshot_reference';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_PAYMENT_REQUEST_MESSAGE,
        self::TYPE_UPI_OR_BANK_DETAIL,
        self::TYPE_CALL_LOG,
        self::TYPE_SCREENSHOT_REFERENCE,
        self::TYPE_OTHER,
    ];

    protected $table = 'suchak_direct_payment_evidence';

    protected $fillable = [
        'suchak_dispute_id',
        'suchak_account_id',
        'customer_context_id',
        'payment_context_id',
        'submitted_by_user_id',
        'evidence_type',
        'evidence_reference',
        'evidence_note',
        'submitted_at',
        'created_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(SuchakDispute::class, 'suchak_dispute_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function paymentContext(): BelongsTo
    {
        return $this->belongsTo(SuchakPaymentContext::class, 'payment_context_id');
    }

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak direct payment evidence records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak direct payment evidence records cannot be deleted.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('Suchak direct payment evidence records are immutable and cannot be modified.');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('Suchak direct payment evidence records are immutable and cannot be modified.');
        }

        return parent::save($options);
    }
}
