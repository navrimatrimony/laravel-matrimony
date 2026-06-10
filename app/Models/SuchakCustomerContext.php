<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakCustomerContext extends Model
{
    use HasFactory;

    public const SERVICE_PROFILE_REPRESENTATION = 'profile_representation';
    public const SERVICE_MATCH_INTRODUCTION = 'match_introduction';
    public const SERVICE_COLLABORATION = 'collaboration';
    public const SERVICE_PACKAGE_LEAD = 'package_lead';

    public const SERVICE_CONTEXTS = [
        self::SERVICE_PROFILE_REPRESENTATION,
        self::SERVICE_MATCH_INTRODUCTION,
        self::SERVICE_COLLABORATION,
        self::SERVICE_PACKAGE_LEAD,
    ];

    public const SOURCE_OWNER_PLATFORM = 'platform';
    public const SOURCE_OWNER_SUCHAK = 'suchak';
    public const SOURCE_OWNER_COLLABORATION = 'collaboration';

    public const SOURCE_OWNERS = [
        self::SOURCE_OWNER_PLATFORM,
        self::SOURCE_OWNER_SUCHAK,
        self::SOURCE_OWNER_COLLABORATION,
    ];

    public const SOURCE_TYPE_INTAKE_UPLOAD = 'intake_upload';
    public const SOURCE_TYPE_EXISTING_PROFILE_MATCH = 'existing_profile_match';
    public const SOURCE_TYPE_PLATFORM_REQUEST = 'platform_request';
    public const SOURCE_TYPE_COLLABORATION = 'collaboration';
    public const SOURCE_TYPE_CANDIDATE_INVITED = 'candidate_invited';
    public const SOURCE_TYPE_ADMIN_ASSIGNED = 'admin_assigned';
    public const SOURCE_TYPE_MANUAL = 'manual';

    public const SOURCE_TYPES = [
        self::SOURCE_TYPE_INTAKE_UPLOAD,
        self::SOURCE_TYPE_EXISTING_PROFILE_MATCH,
        self::SOURCE_TYPE_PLATFORM_REQUEST,
        self::SOURCE_TYPE_COLLABORATION,
        self::SOURCE_TYPE_CANDIDATE_INVITED,
        self::SOURCE_TYPE_ADMIN_ASSIGNED,
        self::SOURCE_TYPE_MANUAL,
    ];

    public const STATUS_LEAD = 'lead';
    public const STATUS_CANDIDATE_IDENTIFIED = 'candidate_identified';
    public const STATUS_CONSENT_PENDING = 'consent_pending';
    public const STATUS_ACTIVE_SERVICE = 'active_service';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_CLOSED = 'closed';

    public const LIFECYCLE_STATUSES = [
        self::STATUS_LEAD,
        self::STATUS_CANDIDATE_IDENTIFIED,
        self::STATUS_CONSENT_PENDING,
        self::STATUS_ACTIVE_SERVICE,
        self::STATUS_PAUSED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_CLOSED,
    ];

    protected $table = 'suchak_customer_contexts';

    protected $fillable = [
        'suchak_account_id',
        'candidate_matrimony_profile_id',
        'source_link_id',
        'representation_id',
        'payer_user_id',
        'payer_name',
        'payer_relationship_to_candidate',
        'consent_id',
        'consent_giver_user_id',
        'consent_giver_name',
        'consent_giver_relationship_to_candidate',
        'service_context',
        'source_owner',
        'source_type',
        'customer_lifecycle_status',
        'created_by_user_id',
        'classified_by_user_id',
        'classified_at',
        'opened_at',
        'closed_at',
    ];

    protected $casts = [
        'classified_at' => 'datetime',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'candidate_matrimony_profile_id');
    }

    public function sourceLink(): BelongsTo
    {
        return $this->belongsTo(SuchakBiodataIntakeLink::class, 'source_link_id');
    }

    public function representation(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRepresentation::class, 'representation_id');
    }

    public function payerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }

    public function consent(): BelongsTo
    {
        return $this->belongsTo(SuchakConsent::class, 'consent_id');
    }

    public function consentGiverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consent_giver_user_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function classifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'classified_by_user_id');
    }

    public function timelineEvents(): HasMany
    {
        return $this->hasMany(SuchakCustomerTimelineEvent::class, 'customer_context_id');
    }

    public function paymentContexts(): HasMany
    {
        return $this->hasMany(SuchakPaymentContext::class, 'customer_context_id');
    }

    public function servicePackages(): HasMany
    {
        return $this->hasMany(SuchakServicePackage::class, 'customer_context_id');
    }

    public function customerAgreements(): HasMany
    {
        return $this->hasMany(SuchakCustomerAgreement::class, 'customer_context_id');
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(SuchakPaymentRequest::class, 'customer_context_id');
    }

    public function customerPayments(): HasMany
    {
        return $this->hasMany(SuchakCustomerPayment::class, 'customer_context_id');
    }

    public function customerPaymentCorrections(): HasMany
    {
        return $this->hasMany(SuchakCustomerPaymentCorrection::class, 'customer_context_id');
    }

    public function overdueServiceActions(): HasMany
    {
        return $this->hasMany(SuchakCustomerOverdueServiceAction::class, 'customer_context_id');
    }

    public function familyMembers(): HasMany
    {
        return $this->hasMany(SuchakCustomerFamilyMember::class, 'customer_context_id')
            ->orderBy('member_role')
            ->orderBy('id');
    }

    public function portalLinks(): HasMany
    {
        return $this->hasMany(SuchakCustomerPortalLink::class, 'customer_context_id')
            ->orderByDesc('id');
    }

    public function directPaymentEvidence(): HasMany
    {
        return $this->hasMany(SuchakDirectPaymentEvidence::class, 'customer_context_id')
            ->orderByDesc('id');
    }

    public function paymentFeatureFreezes(): HasMany
    {
        return $this->hasMany(SuchakPaymentFeatureFreeze::class, 'customer_context_id')
            ->orderByDesc('id');
    }

    public function payoutHolds(): HasMany
    {
        return $this->hasMany(SuchakPayoutHold::class, 'customer_context_id')
            ->orderByDesc('id');
    }

    public function platformPayouts(): HasMany
    {
        return $this->hasMany(SuchakPlatformPayout::class, 'customer_context_id')
            ->orderByDesc('id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak customer contexts cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak customer contexts cannot be deleted.');
    }
}
