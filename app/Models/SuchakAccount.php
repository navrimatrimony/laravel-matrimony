<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SuchakAccount extends Model
{
    use HasFactory;

    public const BUSINESS_TYPE_INDIVIDUAL = 'individual';
    public const BUSINESS_TYPE_BUREAU = 'bureau';
    public const BUSINESS_TYPE_ORGANIZATION = 'organization';

    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_VERIFIED = 'verified';
    public const VERIFICATION_REJECTED = 'rejected';
    public const VERIFICATION_SUSPENDED = 'suspended';
    public const VERIFICATION_ARCHIVED = 'archived';

    public const PUBLIC_HIDDEN = 'hidden';
    public const PUBLIC_ACTIVE = 'active';
    public const PUBLIC_INACTIVE = 'inactive';

    protected $table = 'suchak_accounts';

    protected $fillable = [
        'user_id',
        'suchak_name',
        'office_name',
        'business_type',
        'mobile_number',
        'whatsapp_number',
        'email',
        'address_line',
        'city_id',
        'taluka_id',
        'district_id',
        'state_id',
        'verification_status',
        'public_status',
        'verified_at',
        'rejected_at',
        'suspended_at',
        'archived_at',
        'suspension_reason',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'rejected_at' => 'datetime',
        'suspended_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verificationRecords(): HasMany
    {
        return $this->hasMany(SuchakVerificationRecord::class);
    }

    public function profileRepresentations(): HasMany
    {
        return $this->hasMany(SuchakProfileRepresentation::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(SuchakConsent::class);
    }

    public function biodataExports(): HasMany
    {
        return $this->hasMany(SuchakBiodataExport::class);
    }

    public function qrTokens(): HasMany
    {
        return $this->hasMany(SuchakQrToken::class);
    }

    public function profileRequests(): HasMany
    {
        return $this->hasMany(SuchakProfileRequest::class, 'selected_suchak_account_id');
    }

    public function requestedCollaborations(): HasMany
    {
        return $this->hasMany(SuchakCollaborationRequest::class, 'requesting_suchak_account_id');
    }

    public function targetedCollaborations(): HasMany
    {
        return $this->hasMany(SuchakCollaborationRequest::class, 'target_suchak_account_id');
    }

    public function pipelines(): HasMany
    {
        return $this->hasMany(SuchakPipeline::class, 'selected_suchak_account_id');
    }

    public function profileNotes(): HasMany
    {
        return $this->hasMany(SuchakProfileNote::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SuchakLedgerEntry::class);
    }

    public function paymentContexts(): HasMany
    {
        return $this->hasMany(SuchakPaymentContext::class);
    }

    public function customerContexts(): HasMany
    {
        return $this->hasMany(SuchakCustomerContext::class);
    }

    public function profileUpdateSuggestions(): HasMany
    {
        return $this->hasMany(SuchakProfileUpdateSuggestion::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(SuchakDispute::class);
    }

    public function suchakSubscriptions(): HasMany
    {
        return $this->hasMany(SuchakSubscription::class);
    }

    public function planPayments(): HasMany
    {
        return $this->hasMany(SuchakPlanPayment::class);
    }

    public function servicePackages(): HasMany
    {
        return $this->hasMany(SuchakServicePackage::class);
    }

    public function customerAgreements(): HasMany
    {
        return $this->hasMany(SuchakCustomerAgreement::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(SuchakPaymentRequest::class);
    }

    public function isVerified(): bool
    {
        return $this->verification_status === self::VERIFICATION_VERIFIED;
    }

    public function isPubliclyVisible(): bool
    {
        return $this->isVerified()
            && $this->public_status === self::PUBLIC_ACTIVE;
    }
}
