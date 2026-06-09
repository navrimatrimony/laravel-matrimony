<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakProfileRepresentation extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONSENT_PENDING = 'consent_pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANDIDATE_DEACTIVATED = 'candidate_deactivated';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONSENT_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_REVOKED,
        self::STATUS_EXPIRED,
        self::STATUS_REJECTED,
        self::STATUS_SUSPENDED,
        self::STATUS_CANDIDATE_DEACTIVATED,
    ];

    public const MODE_UPLOADED_BY_SUCHAK = 'uploaded_by_suchak';
    public const MODE_MATCHED_EXISTING_PROFILE = 'matched_existing_profile';
    public const MODE_CANDIDATE_INVITED_SUCHAK = 'candidate_invited_suchak';
    public const MODE_ADMIN_ASSIGNED = 'admin_assigned';
    public const MODE_PRIMARY_SUCHAK = 'primary_suchak';

    public const MODES = [
        self::MODE_UPLOADED_BY_SUCHAK,
        self::MODE_MATCHED_EXISTING_PROFILE,
        self::MODE_CANDIDATE_INVITED_SUCHAK,
        self::MODE_ADMIN_ASSIGNED,
        self::MODE_PRIMARY_SUCHAK,
    ];

    public const CONSENT_NOT_REQUESTED = 'not_requested';
    public const CONSENT_REQUESTED = 'requested';
    public const CONSENT_ACCEPTED = 'accepted';
    public const CONSENT_REJECTED = 'rejected';
    public const CONSENT_EXPIRED = 'expired';
    public const CONSENT_REVOKED = 'revoked';

    public const CONSENT_STATUSES = [
        self::CONSENT_NOT_REQUESTED,
        self::CONSENT_REQUESTED,
        self::CONSENT_ACCEPTED,
        self::CONSENT_REJECTED,
        self::CONSENT_EXPIRED,
        self::CONSENT_REVOKED,
    ];

    protected $table = 'suchak_profile_representations';

    protected $fillable = [
        'suchak_account_id',
        'matrimony_profile_id',
        'biodata_intake_id',
        'representation_status',
        'representation_mode',
        'consent_status',
        'first_uploaded_at',
        'first_identified_at',
        'first_verified_consent_at',
        'consent_verified_at',
        'consent_valid_until',
        'revoked_at',
        'candidate_deactivated_at',
    ];

    protected $casts = [
        'first_uploaded_at' => 'datetime',
        'first_identified_at' => 'datetime',
        'first_verified_consent_at' => 'datetime',
        'consent_verified_at' => 'datetime',
        'consent_valid_until' => 'datetime',
        'revoked_at' => 'datetime',
        'candidate_deactivated_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function matrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class);
    }

    public function biodataIntake(): BelongsTo
    {
        return $this->belongsTo(BiodataIntake::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(SuchakConsent::class, 'representation_id');
    }

    public function biodataExports(): HasMany
    {
        return $this->hasMany(SuchakBiodataExport::class, 'representation_id');
    }

    public function qrTokens(): HasMany
    {
        return $this->hasMany(SuchakQrToken::class, 'representation_id');
    }

    public function profileRequests(): HasMany
    {
        return $this->hasMany(SuchakProfileRequest::class, 'representation_id');
    }

    public function pipelines(): HasMany
    {
        return $this->hasMany(SuchakPipeline::class, 'representation_id');
    }

    public function requestedCollaborations(): HasMany
    {
        return $this->hasMany(SuchakCollaborationRequest::class, 'requesting_representation_id');
    }

    public function targetedCollaborations(): HasMany
    {
        return $this->hasMany(SuchakCollaborationRequest::class, 'target_representation_id');
    }

    public function hasValidConsent(): bool
    {
        if ($this->consent_status !== self::CONSENT_ACCEPTED) {
            return false;
        }

        if ($this->revoked_at !== null || $this->candidate_deactivated_at !== null) {
            return false;
        }

        if ($this->consent_valid_until !== null && $this->consent_valid_until->isPast()) {
            return false;
        }

        return true;
    }

    public function isPubliclyVisible(): bool
    {
        if ($this->representation_status !== self::STATUS_ACTIVE || ! $this->hasValidConsent()) {
            return false;
        }

        $this->loadMissing('suchakAccount');

        return $this->suchakAccount?->isPubliclyVisible() === true;
    }

    public function scopeWithValidConsent(Builder $query): Builder
    {
        return $query
            ->where('representation_status', self::STATUS_ACTIVE)
            ->where('consent_status', self::CONSENT_ACCEPTED)
            ->whereNull('revoked_at')
            ->whereNull('candidate_deactivated_at')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('consent_valid_until')
                    ->orWhere('consent_valid_until', '>=', now());
            });
    }

    public function scopePubliclyRoutable(Builder $query): Builder
    {
        return $query
            ->withValidConsent()
            ->whereHas('suchakAccount', function (Builder $query): void {
                $query
                    ->where('verification_status', SuchakAccount::VERIFICATION_VERIFIED)
                    ->where('public_status', SuchakAccount::PUBLIC_ACTIVE);
            });
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak profile representations cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak profile representations cannot be deleted.');
    }
}
