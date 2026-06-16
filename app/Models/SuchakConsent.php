<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakConsent extends Model
{
    use HasFactory;

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_LINK_OPENED = 'link_opened';
    public const STATUS_OTP_SENT = 'otp_sent';
    public const STATUS_OTP_VERIFIED = 'otp_verified';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_LINK_OPENED,
        self::STATUS_OTP_SENT,
        self::STATUS_OTP_VERIFIED,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
        self::STATUS_REVOKED,
    ];

    public const OPEN_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_LINK_OPENED,
        self::STATUS_OTP_SENT,
        self::STATUS_OTP_VERIFIED,
        self::STATUS_ACCEPTED,
    ];

    public const PENDING_ACTION_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_LINK_OPENED,
        self::STATUS_OTP_SENT,
        self::STATUS_OTP_VERIFIED,
    ];

    public const TYPE_ONE_YEAR = 'one_year';
    public const TYPE_TWO_YEAR = 'two_year';
    public const TYPE_UNTIL_REVOKED = 'until_revoked';

    public const TYPES = [
        self::TYPE_ONE_YEAR,
        self::TYPE_TWO_YEAR,
        self::TYPE_UNTIL_REVOKED,
    ];

    public const CHANNEL_WHATSAPP_DEEP_LINK = 'whatsapp_deep_link';
    public const CHANNEL_SMS_OTP = 'sms_otp';
    public const CHANNEL_VOICE_OTP = 'voice_otp';
    public const CHANNEL_OFFLINE_PROOF = 'offline_proof';
    public const CHANNEL_ADMIN_ASSISTED = 'admin_assisted';
    public const CHANNEL_SUCHAK_RELAYED_LINK = 'suchak_relayed_link';
    public const CHANNEL_OFFLINE_SIGNED_PROOF = 'offline_signed_proof';
    public const CHANNEL_PLATFORM_ASSISTED_LINK = 'platform_assisted_link';

    public const CHANNELS = [
        self::CHANNEL_WHATSAPP_DEEP_LINK,
        self::CHANNEL_SMS_OTP,
        self::CHANNEL_VOICE_OTP,
        self::CHANNEL_OFFLINE_PROOF,
        self::CHANNEL_ADMIN_ASSISTED,
        self::CHANNEL_SUCHAK_RELAYED_LINK,
        self::CHANNEL_OFFLINE_SIGNED_PROOF,
        self::CHANNEL_PLATFORM_ASSISTED_LINK,
    ];

    public const METHOD_SUCHAK_RELAYED_LINK = self::CHANNEL_SUCHAK_RELAYED_LINK;
    public const METHOD_OFFLINE_SIGNED_PROOF = self::CHANNEL_OFFLINE_SIGNED_PROOF;
    public const METHOD_PLATFORM_ASSISTED_LINK = self::CHANNEL_PLATFORM_ASSISTED_LINK;

    public const METHODS = [
        self::METHOD_SUCHAK_RELAYED_LINK,
        self::METHOD_OFFLINE_SIGNED_PROOF,
        self::METHOD_PLATFORM_ASSISTED_LINK,
    ];

    public const LINK_METHODS = [
        self::METHOD_SUCHAK_RELAYED_LINK,
        self::METHOD_PLATFORM_ASSISTED_LINK,
    ];

    public const TEMPLATE_VERSION_V1 = 'v1';
    public const CONSENT_TEXT_VERSION_V1 = 'suchak_consent_v1';

    public const DEFAULT_TOKEN_EXPIRY_DAYS = 7;
    public const MAX_OTP_ATTEMPTS = 5;

    protected $table = 'suchak_consents';

    protected $fillable = [
        'suchak_account_id',
        'matrimony_profile_id',
        'representation_id',
        'consent_status',
        'consent_type',
        'consent_text_snapshot',
        'consent_text_snapshot_mr',
        'consent_template_version',
        'consent_text_version',
        'consent_given_by_name',
        'relationship_to_candidate',
        'consent_giver_relation',
        'consent_mobile_number',
        'intended_mobile',
        'submitted_mobile',
        'mobile_match',
        'token_hash',
        'token_expires_at',
        'expires_at',
        'otp_hash',
        'otp_attempts',
        'last_otp_sent_at',
        'accepted_at',
        'rejected_at',
        'revoked_at',
        'used_at',
        'public_token_used_at',
        'decided_at',
        'otp_verified_at',
        'consent_channel',
        'consent_method',
        'valid_from',
        'valid_until',
        'revocation_reason',
        'revocation_reason_mr',
        'ip_address',
        'user_agent',
        'proof_file_path',
        'proof_original_name',
        'proof_uploaded_at',
        'delivery_status',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_otp_sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'revoked_at' => 'datetime',
        'used_at' => 'datetime',
        'public_token_used_at' => 'datetime',
        'decided_at' => 'datetime',
        'otp_verified_at' => 'datetime',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'proof_uploaded_at' => 'datetime',
        'otp_attempts' => 'integer',
        'mobile_match' => 'boolean',
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

    public function events(): HasMany
    {
        return $this->hasMany(SuchakConsentEvent::class, 'consent_id');
    }

    public function customerContexts(): HasMany
    {
        return $this->hasMany(SuchakCustomerContext::class, 'consent_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->consent_status, self::OPEN_STATUSES, true);
    }

    public function isAcceptedAndValid(): bool
    {
        if ($this->consent_status !== self::STATUS_ACCEPTED || $this->revoked_at !== null) {
            return false;
        }

        if ($this->valid_until !== null && $this->valid_until->isPast()) {
            return false;
        }

        return true;
    }

    public function isTokenExpired(): bool
    {
        $expiresAt = $this->expires_at ?? $this->token_expires_at;

        return $expiresAt !== null && $expiresAt->isPast();
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak consent records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak consent records cannot be deleted.');
    }
}
