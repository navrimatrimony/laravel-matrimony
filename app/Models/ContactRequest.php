<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Day-32: Contact request (sender requests receiver's contact).
 * State machine: pending → accepted|rejected|expired|cancelled; accepted → revoked|expired via grant.
 */
class ContactRequest extends Model
{
    protected $table = 'contact_requests';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'sender_id',
        'receiver_id',
        'reason',
        'other_reason_text',
        'requested_scopes',
        'status',
        'cooldown_ends_at',
        'expires_at',
    ];

    protected $casts = [
        'requested_scopes' => 'array',
        'cooldown_ends_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function grant(): HasOne
    {
        return $this->hasOne(ContactGrant::class);
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
