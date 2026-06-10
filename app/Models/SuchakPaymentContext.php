<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakPaymentContext extends Model
{
    use HasFactory;

    public const SOURCE_PLATFORM = 'platform';
    public const SOURCE_SUCHAK = 'suchak';
    public const SOURCE_COLLABORATION = 'collaboration';

    public const SOURCE_OWNERS = [
        self::SOURCE_PLATFORM,
        self::SOURCE_SUCHAK,
        self::SOURCE_COLLABORATION,
    ];

    public const COLLECTOR_PLATFORM = 'platform';
    public const COLLECTOR_SUCHAK = 'suchak';

    public const PAYMENT_COLLECTORS = [
        self::COLLECTOR_PLATFORM,
        self::COLLECTOR_SUCHAK,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUPERSEDED,
        self::STATUS_CANCELLED,
    ];

    public const PLATFORM_DIRECT_PAYMENT_BLOCK_MESSAGE = 'This is a platform customer. Do not request direct payment. Any eligible payout will be handled by Navri Mile Navryala.';
    public const DIRECT_PAYMENT_BLOCK_MESSAGE = 'This customer is assigned to platform collection. Do not request direct Suchak payment.';

    protected $table = 'suchak_payment_contexts';

    protected $fillable = [
        'suchak_account_id',
        'customer_context_id',
        'matrimony_profile_id',
        'pipeline_id',
        'collaboration_request_id',
        'source_owner',
        'payment_collector',
        'context_status',
        'resolved_by_user_id',
        'resolution_note',
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

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(SuchakPipeline::class, 'pipeline_id');
    }

    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(SuchakCollaborationRequest::class, 'collaboration_request_id');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SuchakLedgerEntry::class, 'payment_context_id');
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(SuchakPaymentRequest::class, 'payment_context_id');
    }

    public function customerPayments(): HasMany
    {
        return $this->hasMany(SuchakCustomerPayment::class, 'payment_context_id');
    }

    public function directPaymentEvidence(): HasMany
    {
        return $this->hasMany(SuchakDirectPaymentEvidence::class, 'payment_context_id');
    }

    public function paymentFeatureFreezes(): HasMany
    {
        return $this->hasMany(SuchakPaymentFeatureFreeze::class, 'payment_context_id');
    }

    public function payoutHolds(): HasMany
    {
        return $this->hasMany(SuchakPayoutHold::class, 'payment_context_id');
    }

    public function platformPayouts(): HasMany
    {
        return $this->hasMany(SuchakPlatformPayout::class, 'payment_context_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak payment contexts cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak payment contexts cannot be deleted.');
    }
}
