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
