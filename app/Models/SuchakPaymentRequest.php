<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakPaymentRequest extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_OPENED = 'opened';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_FAILED = 'failed';

    public const PAYMENT_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_OPENED,
        self::STATUS_PENDING,
        self::STATUS_PARTIALLY_PAID,
        self::STATUS_PAID,
        self::STATUS_OVERDUE,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
        self::STATUS_FAILED,
    ];

    public const VISIBILITY_TERMS_SATISFIED_ONLY = 'terms_satisfied_only';
    public const VISIBILITY_COLLECTOR_DISCLOSURE_ONLY = 'collector_disclosure_only';
    public const VISIBILITY_NEVER_PUBLIC = 'never_public';

    public const VISIBILITY_POLICIES = [
        self::VISIBILITY_TERMS_SATISFIED_ONLY,
        self::VISIBILITY_COLLECTOR_DISCLOSURE_ONLY,
        self::VISIBILITY_NEVER_PUBLIC,
    ];

    protected $table = 'suchak_payment_requests';

    protected $fillable = [
        'suchak_account_id',
        'customer_context_id',
        'service_package_id',
        'customer_agreement_id',
        'payment_context_id',
        'requested_by_user_id',
        'request_token_hash',
        'payment_status',
        'payment_detail_visibility_policy',
        'request_title',
        'request_note',
        'amount_due',
        'currency',
        'collector_disclosure',
        'sent_at',
        'opened_at',
        'expires_at',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancellation_reason',
        'expired_at',
    ];

    protected $casts = [
        'amount_due' => 'decimal:2',
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expired_at' => 'datetime',
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

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SuchakPaymentRequestEvent::class, 'payment_request_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function customerPayments(): HasMany
    {
        return $this->hasMany(SuchakCustomerPayment::class, 'payment_request_id')
            ->orderByDesc('id');
    }

    public function customerPaymentCorrections(): HasMany
    {
        return $this->hasMany(SuchakCustomerPaymentCorrection::class, 'payment_request_id')
            ->orderByDesc('id');
    }

    public function overdueServiceActions(): HasMany
    {
        return $this->hasMany(SuchakCustomerOverdueServiceAction::class, 'payment_request_id')
            ->orderByDesc('id');
    }

    public function customerPortalLinks(): HasMany
    {
        return $this->hasMany(SuchakCustomerPortalLink::class, 'payment_request_id')
            ->orderByDesc('id');
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isOpenable(): bool
    {
        return in_array($this->payment_status, [
            self::STATUS_SENT,
            self::STATUS_OPENED,
        ], true);
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak payment requests cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak payment requests cannot be deleted.');
    }
}
