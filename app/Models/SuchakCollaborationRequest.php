<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class SuchakCollaborationRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_ADMIN_REVIEW = 'admin_review';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
        self::STATUS_ADMIN_REVIEW,
    ];

    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_ADMIN_REVIEW,
    ];

    /**
     * Which of the two existing account columns holds the CUSTOMER-OWNING Suchak (blueprint 6.1).
     * The pair is stored by direction; in the marketplace the responder is the requester, so
     * direction no longer implies role. This is a side label — never a second copy of an account id.
     */
    public const SIDE_REQUESTING = 'requesting';
    public const SIDE_TARGET = 'target';

    /** @var list<string> */
    public const SIDES = [
        self::SIDE_REQUESTING,
        self::SIDE_TARGET,
    ];

    protected $table = 'suchak_collaboration_requests';

    protected $fillable = [
        'requesting_suchak_account_id',
        'target_suchak_account_id',
        'requesting_matrimony_profile_id',
        'target_matrimony_profile_id',
        'requesting_representation_id',
        'target_representation_id',
        'marketplace_challenge_id',
        'customer_owner_side',
        'status',
        'marketplace_stage',
        'message',
        'requested_at',
        'responded_at',
        'expires_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function requestingSuchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class, 'requesting_suchak_account_id');
    }

    public function targetSuchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class, 'target_suchak_account_id');
    }

    public function requestingMatrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'requesting_matrimony_profile_id');
    }

    public function targetMatrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'target_matrimony_profile_id');
    }

    public function requestingRepresentation(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRepresentation::class, 'requesting_representation_id');
    }

    public function targetRepresentation(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRepresentation::class, 'target_representation_id');
    }

    public function commissionAgreement(): HasOne
    {
        return $this->hasOne(SuchakCommissionAgreement::class, 'collaboration_request_id');
    }

    /**
     * The challenge this engagement answers, or null for a direct cross-Suchak collaboration
     * (blueprint D7). Written once, by the proposal that created the engagement.
     *
     * Read under a row lock by SuchakCollaborationService::lockedChallengeAnswered(), which is the
     * one place acceptance both refuses a second accepted proposal (M1) and closes the challenge it
     * answers (STATUS_FULFILLED).
     */
    public function marketplaceChallenge(): BelongsTo
    {
        return $this->belongsTo(SuchakMarketplaceChallenge::class, 'marketplace_challenge_id');
    }

    /**
     * True when this engagement was formed by accepting a marketplace challenge.
     *
     * The predicate three separate rules read, kept in one place: the share is the CHALLENGE's and
     * is not negotiable (D4, enforced in updateCommissionTerms), acceptance closes the challenge it
     * answers (STATUS_FULFILLED), and the requester is the HELPER rather than the customer's own
     * Suchak — the direction reversal that makes all of it necessary.
     */
    public function isMarketplaceProposal(): bool
    {
        return $this->marketplace_challenge_id !== null;
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SuchakLedgerEntry::class, 'collaboration_request_id');
    }

    public function stageEvents(): HasMany
    {
        return $this->hasMany(SuchakCollaborationStageEvent::class, 'collaboration_request_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * The Suchak who holds the customer relationship, the customer agreement and the collection.
     */
    public function customerOwnerSuchakAccountId(): int
    {
        return $this->customer_owner_side === self::SIDE_REQUESTING
            ? (int) $this->requesting_suchak_account_id
            : (int) $this->target_suchak_account_id;
    }

    /**
     * The Suchak who is helping — the other side of the same pair.
     */
    public function helpingSuchakAccountId(): int
    {
        return $this->customer_owner_side === self::SIDE_REQUESTING
            ? (int) $this->target_suchak_account_id
            : (int) $this->requesting_suchak_account_id;
    }

    public function isCustomerOwner(int $suchakAccountId): bool
    {
        return $this->customerOwnerSuchakAccountId() === $suchakAccountId;
    }

    public function isHelpingSuchak(int $suchakAccountId): bool
    {
        return $this->helpingSuchakAccountId() === $suchakAccountId;
    }

    /**
     * Which directional slot a participating account sits in, or null when it is not a participant.
     */
    public function sideForAccount(int $suchakAccountId): ?string
    {
        if ((int) $this->requesting_suchak_account_id === $suchakAccountId) {
            return self::SIDE_REQUESTING;
        }

        if ((int) $this->target_suchak_account_id === $suchakAccountId) {
            return self::SIDE_TARGET;
        }

        return null;
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak collaboration request records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak collaboration request records cannot be deleted.');
    }
}
