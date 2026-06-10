<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakDispute extends Model
{
    use HasFactory;

    public const TYPE_REPRESENTATION_CLAIM = 'representation_claim';
    public const TYPE_CONSENT_CONFLICT = 'consent_conflict';
    public const TYPE_PAYMENT_LEDGER = 'payment_ledger';
    public const TYPE_ABUSE_REPORT = 'abuse_report';
    public const TYPE_DIRECT_PAYMENT_REQUEST = 'direct_payment_request';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_REPRESENTATION_CLAIM,
        self::TYPE_CONSENT_CONFLICT,
        self::TYPE_PAYMENT_LEDGER,
        self::TYPE_ABUSE_REPORT,
        self::TYPE_DIRECT_PAYMENT_REQUEST,
        self::TYPE_OTHER,
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_RESOLVED,
        self::STATUS_REJECTED,
        self::STATUS_CLOSED,
    ];

    public const CLOSING_STATUSES = [
        self::STATUS_RESOLVED,
        self::STATUS_REJECTED,
        self::STATUS_CLOSED,
    ];

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_NORMAL,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    public const RISK_SOURCE_ADMIN_CASE = 'admin_case';
    public const RISK_SOURCE_CUSTOMER_DIRECT_PAYMENT_REPORT = 'customer_direct_payment_report';

    public const RISK_SOURCES = [
        self::RISK_SOURCE_ADMIN_CASE,
        self::RISK_SOURCE_CUSTOMER_DIRECT_PAYMENT_REPORT,
    ];

    protected $table = 'suchak_disputes';

    protected $fillable = [
        'suchak_account_id',
        'matrimony_profile_id',
        'representation_id',
        'customer_context_id',
        'payment_context_id',
        'opened_by_user_id',
        'assigned_admin_user_id',
        'dispute_type',
        'status',
        'priority',
        'risk_source',
        'summary',
        'evidence_summary',
        'resolution_note',
        'opened_at',
        'resolved_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function matrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class);
    }

    public function representation(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRepresentation::class, 'representation_id');
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function paymentContext(): BelongsTo
    {
        return $this->belongsTo(SuchakPaymentContext::class, 'payment_context_id');
    }

    public function openedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function assignedAdminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_user_id');
    }

    public function directPaymentEvidence(): HasMany
    {
        return $this->hasMany(SuchakDirectPaymentEvidence::class, 'suchak_dispute_id')
            ->orderBy('submitted_at')
            ->orderBy('id');
    }

    public function paymentFeatureFreezes(): HasMany
    {
        return $this->hasMany(SuchakPaymentFeatureFreeze::class, 'suchak_dispute_id')
            ->orderByDesc('id');
    }

    public function payoutHolds(): HasMany
    {
        return $this->hasMany(SuchakPayoutHold::class, 'suchak_dispute_id')
            ->orderByDesc('id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak dispute records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak dispute records cannot be deleted.');
    }
}
