<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakCustomerPaymentDocument extends Model
{
    use HasFactory;

    public const TYPE_INVOICE = 'invoice';
    public const TYPE_RECEIPT = 'receipt';

    public const TYPES = [
        self::TYPE_INVOICE,
        self::TYPE_RECEIPT,
    ];

    protected $table = 'suchak_customer_payment_documents';

    protected $fillable = [
        'customer_payment_id',
        'suchak_account_id',
        'customer_context_id',
        'document_type',
        'document_number',
        'fy_label',
        'sequence_no',
        'verification_code',
        'issued_by_user_id',
        'issued_at',
    ];

    protected $casts = [
        'sequence_no' => 'integer',
        'issued_at' => 'datetime',
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

    public function issuedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak customer payment documents cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak customer payment documents cannot be deleted.');
    }
}
