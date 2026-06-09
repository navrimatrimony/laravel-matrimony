<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakConsentEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const EVENT_REQUESTED = 'requested';
    public const EVENT_WHATSAPP_LINK_OPENED = 'whatsapp_link_opened';
    public const EVENT_OTP_SENT = 'otp_sent';
    public const EVENT_OTP_VERIFIED = 'otp_verified';
    public const EVENT_CONSENT_ACCEPTED = 'consent_accepted';
    public const EVENT_CONSENT_REJECTED = 'consent_rejected';
    public const EVENT_CONSENT_EXPIRED = 'consent_expired';
    public const EVENT_CONSENT_REVOKED = 'consent_revoked';
    public const EVENT_FALLBACK_TRIGGERED = 'fallback_triggered';

    public const EVENTS = [
        self::EVENT_REQUESTED,
        self::EVENT_WHATSAPP_LINK_OPENED,
        self::EVENT_OTP_SENT,
        self::EVENT_OTP_VERIFIED,
        self::EVENT_CONSENT_ACCEPTED,
        self::EVENT_CONSENT_REJECTED,
        self::EVENT_CONSENT_EXPIRED,
        self::EVENT_CONSENT_REVOKED,
        self::EVENT_FALLBACK_TRIGGERED,
    ];

    public const ACTOR_SUCHAK = 'suchak';
    public const ACTOR_USER = 'user';
    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_CANDIDATE = 'candidate';
    public const ACTOR_SYSTEM = 'system';

    public const ACTORS = [
        self::ACTOR_SUCHAK,
        self::ACTOR_USER,
        self::ACTOR_ADMIN,
        self::ACTOR_CANDIDATE,
        self::ACTOR_SYSTEM,
    ];

    protected $table = 'suchak_consent_events';

    protected $fillable = [
        'consent_id',
        'event_type',
        'event_note',
        'actor_type',
        'actor_id',
        'created_at',
    ];

    public function consent(): BelongsTo
    {
        return $this->belongsTo(SuchakConsent::class, 'consent_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak consent events are immutable and cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak consent events are immutable and cannot be deleted.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('Suchak consent events are immutable and cannot be modified.');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('Suchak consent events are immutable and cannot be modified.');
        }

        return parent::save($options);
    }
}
