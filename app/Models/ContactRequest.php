<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Day-32: Contact request (sender requests receiver's contact).
 * type=contact: Day-32 grant flow. type=mediator: assisted matchmaking (see {@see MediationRequest} scoped model).
 */
class ContactRequest extends Model
{
    protected $table = 'contact_requests';

    public const TYPE_CONTACT = 'contact';

    public const TYPE_MEDIATOR = 'mediator';

    /** Reason value for mediator rows (legacy reason column). */
    public const REASON_MEDIATOR = 'mediator';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_CANCELLED = 'cancelled';

    /** Mediator flow: receiver opted in. */
    public const STATUS_INTERESTED = 'interested';

    /** Mediator flow: receiver declined. */
    public const STATUS_NOT_INTERESTED = 'not_interested';

    /** Assisted matchmaking: receiver asked for more information (does not unlock contact). */
    public const STATUS_NEED_MORE_INFO = 'need_more_info';

    /**
     * Default attribute values for new instances (mirrors DB default).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => self::TYPE_CONTACT,
    ];

    protected $fillable = [
        'type',
        'sender_id',
        'receiver_id',
        'sender_profile_id',
        'receiver_profile_id',
        'subject_profile_id',
        'reason',
        'other_reason_text',
        'requested_scopes',
        'meta',
        'response_feedback',
        'status',
        'cooldown_ends_at',
        'expires_at',
        'responded_at',
        'admin_notified_at',
    ];

    protected $casts = [
        'requested_scopes' => 'array',
        'meta' => 'array',
        'cooldown_ends_at' => 'datetime',
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
        'admin_notified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (ContactRequest $model) {
            if ($model->type === null || $model->type === '') {
                $model->type = self::TYPE_CONTACT;
            }
        });
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function subjectProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'subject_profile_id');
    }

    public function senderProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'sender_profile_id');
    }

    public function receiverProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'receiver_profile_id');
    }

    public function grant(): HasOne
    {
        return $this->hasOne(ContactGrant::class);
    }

    public function isContactType(): bool
    {
        return ($this->type ?? self::TYPE_CONTACT) === self::TYPE_CONTACT;
    }

    public function isMediatorType(): bool
    {
        return ($this->type ?? '') === self::TYPE_MEDIATOR;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    /** Cooldown still active for this sender→receiver pair? */
    public function isInCooldown(): bool
    {
        return $this->cooldown_ends_at && $this->cooldown_ends_at->isFuture();
    }
}
