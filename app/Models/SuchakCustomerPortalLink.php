<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakCustomerPortalLink extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_CLAIMED,
        self::STATUS_REVOKED,
        self::STATUS_EXPIRED,
    ];

    public const RECIPIENT_CANDIDATE = 'candidate';
    public const RECIPIENT_PAYER = 'payer';
    public const RECIPIENT_FAMILY = 'family_member';

    public const RECIPIENT_ROLES = [
        self::RECIPIENT_CANDIDATE,
        self::RECIPIENT_PAYER,
        self::RECIPIENT_FAMILY,
    ];

    protected $table = 'suchak_customer_portal_links';

    protected $fillable = [
        'suchak_account_id',
        'customer_context_id',
        'payment_request_id',
        'customer_family_member_id',
        'issued_by_user_id',
        'token_hash',
        'portal_status',
        'recipient_role',
        'recipient_label',
        'expires_at',
        'opened_at',
        'claimed_at',
        'claimed_name',
        'claimed_relationship_to_candidate',
        'revoked_by_user_id',
        'revoked_at',
        'revoke_reason',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'opened_at' => 'datetime',
        'claimed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(SuchakPaymentRequest::class, 'payment_request_id');
    }

    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerFamilyMember::class, 'customer_family_member_id');
    }

    public function issuedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SuchakCustomerPortalEvent::class, 'customer_portal_link_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak customer portal links cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak customer portal links cannot be deleted.');
    }
}
