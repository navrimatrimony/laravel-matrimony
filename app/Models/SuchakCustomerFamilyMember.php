<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakCustomerFamilyMember extends Model
{
    use HasFactory;

    public const ROLE_CANDIDATE = 'candidate';
    public const ROLE_PAYER = 'payer';
    public const ROLE_GUARDIAN = 'guardian';
    public const ROLE_FAMILY_MEMBER = 'family_member';

    public const ROLES = [
        self::ROLE_CANDIDATE,
        self::ROLE_PAYER,
        self::ROLE_GUARDIAN,
        self::ROLE_FAMILY_MEMBER,
    ];

    public const PAYER_NONE = 'none';
    public const PAYER_PRIMARY = 'primary';
    public const PAYER_SHARED = 'shared';

    public const PAYER_ROLES = [
        self::PAYER_NONE,
        self::PAYER_PRIMARY,
        self::PAYER_SHARED,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_REVOKED,
    ];

    protected $table = 'suchak_customer_family_members';

    protected $fillable = [
        'suchak_account_id',
        'customer_context_id',
        'linked_user_id',
        'linked_matrimony_profile_id',
        'member_role',
        'payer_role',
        'relationship_to_candidate',
        'display_name',
        'access_status',
        'added_by_user_id',
        'revoked_by_user_id',
        'revoked_at',
        'revocation_reason',
    ];

    protected $casts = [
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

    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    public function linkedProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'linked_matrimony_profile_id');
    }

    public function addedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function portalLinks(): HasMany
    {
        return $this->hasMany(SuchakCustomerPortalLink::class, 'customer_family_member_id')
            ->orderByDesc('id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak customer family members cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak customer family members cannot be deleted.');
    }
}
