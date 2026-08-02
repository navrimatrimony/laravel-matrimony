<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakPayoutHold extends Model
{
    use HasFactory;

    public const SCOPE_DIRECT_PAYMENT_RISK = 'direct_payment_risk';
    public const SCOPE_VISIT_CONFIRMATION_DISPUTE = 'visit_confirmation_dispute';

    public const SCOPES = [
        self::SCOPE_DIRECT_PAYMENT_RISK,
        self::SCOPE_VISIT_CONFIRMATION_DISPUTE,
    ];

    /**
     * An ACTIVE hold blocks every subsequent platform payout for this
     * Suchak/context ({@see \App\Modules\Suchak\Services\SuchakPlatformPayoutService::hasActiveHold()}),
     * which is the platform's real leverage (§7.3) — and until 2026-08-03
     * nothing in app/ wrote either terminal status, so the leverage could only
     * be applied, never lifted.
     *
     * The two terminal values are NOT synonyms:
     * - `released`  — the reason for the hold was examined and did not stand.
     *                 The withheld money is freed on a finding.
     * - `cancelled` — the hold no longer applies, but nothing was found for the
     *                 Suchak (the case was withdrawn, lapsed, or closed with the
     *                 complaint upheld). The block on the Suchak's OTHER payouts
     *                 goes, and nothing is asserted about the disputed item —
     *                 that answer lives on the item's own row.
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_RELEASED,
        self::STATUS_CANCELLED,
    ];

    /**
     * The statuses an admin may move an ACTIVE hold to. `active` is not one of
     * them: a hold is never re-armed, a new one is opened instead, so the
     * history of what was held and why can never be overwritten.
     */
    public const RELEASE_STATUSES = [
        self::STATUS_RELEASED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Dispute closing status → what happens to the holds that dispute opened.
     *
     * `resolved` (complaint stood) and `closed` (nobody adjudicated) both
     * CANCEL: neither is a finding for the Suchak, so neither may read as
     * "the money was cleared" — but neither may keep freezing every unrelated
     * payout for a case that is over either.
     *
     * @var array<string, string>
     */
    public const DISPUTE_CLOSE_HOLD_OUTCOME = [
        SuchakDispute::STATUS_RESOLVED => self::STATUS_CANCELLED,
        SuchakDispute::STATUS_REJECTED => self::STATUS_RELEASED,
        SuchakDispute::STATUS_CLOSED => self::STATUS_CANCELLED,
    ];

    protected $table = 'suchak_payout_holds';

    protected $fillable = [
        'suchak_dispute_id',
        'suchak_account_id',
        'customer_context_id',
        'payment_context_id',
        'hold_scope',
        'hold_status',
        'hold_reason',
        'created_by_user_id',
        'released_by_user_id',
        'released_at',
        'release_reason',
    ];

    protected $casts = [
        'released_at' => 'datetime',
    ];

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(SuchakDispute::class, 'suchak_dispute_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function paymentContext(): BelongsTo
    {
        return $this->belongsTo(SuchakPaymentContext::class, 'payment_context_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function releasedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak payout hold records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak payout hold records cannot be deleted.');
    }
}
