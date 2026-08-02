<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * "SUCHAK A OWES SUCHAK B" — blueprint §7 M2, M3 and §9a A7.
 *
 * Before this row nothing in the schema could express it. Every money object named exactly ONE
 * Suchak account and had no payer: `suchak_platform_payouts` is platform → one Suchak,
 * `suchak_customer_payments` is customer → one Suchak, `suchak_ledger_entries` has the entire
 * receivable vocabulary but one `suchak_account_id` and a NOT NULL `matrimony_profile_id`, and
 * `suchak_commission_agreements` is acceptance-only with no due/paid/settled at all. See the
 * migration docblock for the line-by-line verdicts and for why a payer column on the ledger entry
 * was rejected.
 *
 * ── M2 IS THE WHOLE SCOPE, AND IT IS NARROW ON PURPOSE ───────────────────────────────────────
 *
 * *"The only cross-Suchak obligation is the share the declarer declared in advance."* The only
 * object in this codebase that declares in advance is `suchak_marketplace_challenges` (D4), so
 * `marketplace_challenge_id` is NOT NULL and a DIRECT (non-marketplace) collaboration raises
 * nothing — D5, *"a Suchak who declared nothing owes nothing, even if their customer married
 * through the marketplace"*. `suchak_commission_agreements.groom_side_share` / `bride_side_share`
 * are deliberately NOT read as a declaration: they are a two-way credit split that must sum to 100
 * and name both sides by account id, accepted after the fact rather than declared before it.
 *
 * ── M3 IN TWO HALVES, NEITHER OF WHICH IS A STORED COLUMN ────────────────────────────────────
 *
 * *"A share falls due when the customer has PAID — or a fixed number of days after a RECORDED
 * MARRIAGE, whichever is earlier. Suppressing the record must ACCELERATE the obligation, never
 * kill it."*
 *
 *   half A   {@see customerPaidAt()} — `suchak_success_fee_tranches.settled_at`, the only object in
 *            this schema that can say a customer payment WAS the success fee (it carries
 *            `customer_payment_id` beside it). A `fee_type` column on `suchak_customer_payments`
 *            would be a second home for that same fact and is deliberately not added.
 *   half B   {@see marriageClockDueAt()} — `SuchakMarriageOutcome::shareFallsDueAt()`, arithmetic
 *            over the WEDDING DAY.
 *
 * ACCELERATION, concretely: `married_on` is the day of the wedding, never the day it was reported,
 * so a marriage suppressed for six months and recorded late produces an obligation ALREADY past its
 * deadline on the day it is raised. And the `marriage` rung is `CLAIMANT_EITHER_SUCHAK`, so the
 * payee can record the wedding himself — the payer cannot withhold the fact that starts the clock.
 *
 * WHERE HALF A CANNOT ANSWER: an agreement with no installment plan has no tranche row, therefore
 * no per-fee payment pointer, therefore no answer to "has the customer paid the success fee". M3's
 * "whichever is earlier" then degenerates to half B alone. That is reported honestly by
 * {@see customerPaymentIsAnswerable()} rather than papered over with a guess, and the obligation
 * still exists and still falls due — which is the guarantee M3 actually demands.
 */
class SuchakCrossSuchakObligation extends Model
{
    use HasFactory;

    /**
     * The ladder rung that closes the loop when every obligation on an engagement is settled —
     * A7's *"share-settled stage, markable only by the helper"*. Read from the ladder, never
     * re-typed: `SuchakCollaborationStageEvent` owns the vocabulary and its claimant rule
     * (`STAGE_SHARE_SETTLED => CLAIMANT_HELPER`, which is this row's payee).
     */
    public const SETTLEMENT_STAGE = SuchakCollaborationStageEvent::STAGE_SHARE_SETTLED;

    protected $table = 'suchak_cross_suchak_obligations';

    protected $fillable = [
        'payer_suchak_account_id',
        'payee_suchak_account_id',
        'collaboration_request_id',
        'marriage_outcome_id',
        'marketplace_challenge_id',
        'success_fee_tranche_id',
        'amount',
        'currency',
        'settled_at',
        'settled_by_user_id',
        'settlement_reference',
        'settlement_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'settled_at' => 'datetime',
    ];

    /**
     * The invariants of the ROW, on `saving`, so they hold for every writer that ever exists —
     * including a second door added later that forgets the service. Same discipline as
     * `SuchakMarriageOutcome::assertMatchesItsEngagement()` and
     * `SuchakMarketplaceChallenge::assertDeclaredShare()`: Laravel's schema builder has no portable
     * CHECK verb, production is MySQL and the suite is SQLite.
     */
    protected static function booted(): void
    {
        static::saving(function (self $obligation): void {
            $obligation->assertMatchesItsOrigin();
        });
    }

    /**
     * Six things must agree, and each of them is a way the largest cross-Suchak figure in the system
     * could otherwise be pointed at the wrong account.
     */
    public function assertMatchesItsOrigin(): void
    {
        if ((float) $this->amount <= 0.0) {
            throw new InvalidArgumentException('A cross-Suchak obligation must carry an amount above zero.');
        }

        $currency = strtoupper(trim((string) $this->currency));
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException('A cross-Suchak obligation must carry a three-letter currency.');
        }
        $this->currency = $currency;

        /** @var SuchakCollaborationRequest|null $collaboration */
        $collaboration = SuchakCollaborationRequest::query()->find($this->collaboration_request_id);
        if ($collaboration === null) {
            throw new InvalidArgumentException('A cross-Suchak obligation must name the engagement it arose on.');
        }

        // 1. The parties are the engagement's two ROLES — never its two DIRECTIONS. On a challenge
        //    answered by proposing, the responder is the REQUESTER, so reading
        //    `requesting_suchak_account_id` as "the customer's Suchak" points the debt backwards
        //    exactly when the money is largest.
        if ((int) $this->payer_suchak_account_id !== $collaboration->customerOwnerSuchakAccountId()
            || (int) $this->payee_suchak_account_id !== $collaboration->helpingSuchakAccountId()) {
            throw new InvalidArgumentException(
                'A cross-Suchak obligation is owed BY the customer-owning Suchak TO the helping Suchak of its own engagement.'
            );
        }

        // 2. M2/D5: the only source is a declared share, and it must be the declaration THIS
        //    engagement was formed under. A8 — the share sticks to the challenge that was live when
        //    the candidate was suggested, and a republished challenge at a new rate cannot reprice it.
        if ($collaboration->marketplace_challenge_id === null
            || (int) $collaboration->marketplace_challenge_id !== (int) $this->marketplace_challenge_id) {
            throw new InvalidArgumentException(
                'A cross-Suchak obligation must name the marketplace challenge its own engagement answered.'
            );
        }

        /** @var SuchakMarriageOutcome|null $outcome */
        $outcome = SuchakMarriageOutcome::query()->find($this->marriage_outcome_id);

        // 3. The marriage is THIS engagement's. Attributing one engagement's wedding to another's
        //    obligation is the §6.2 failure, one layer further down the money.
        if ($outcome === null || (int) $outcome->collaboration_request_id !== (int) $collaboration->id) {
            throw new InvalidArgumentException(
                'A cross-Suchak obligation must rest on the marriage recorded for its own engagement.'
            );
        }

        if ($this->success_fee_tranche_id === null) {
            return;
        }

        /** @var SuchakSuccessFeeTranche|null $tranche */
        $tranche = SuchakSuccessFeeTranche::query()->find($this->success_fee_tranche_id);

        if ($tranche === null) {
            throw new InvalidArgumentException(
                'A cross-Suchak obligation must name a real success-fee tranche or none at all.'
            );
        }

        // 4. The tranche belongs to the AGREEMENT CHAIN the marriage was attributed under — one
        //    customer, one package, every revision of it. A tranche of another customer's agreement
        //    would make this a share of somebody else's fee.
        //
        //    THE CHAIN AND NOT THE REVISION, and that is a correction rather than a loosening. The
        //    revision the §6.2 row is BOUND to (`assertMatchesItsEngagement`) is not where the money
        //    moves: `SuchakSuccessFeeTrancheService::ledgerAgreementFor()` releases and settles on
        //    the LATEST revision of the same `service_package_id`. Pinning this row to the bound
        //    revision pointed every obligation at tranches that would never release and never
        //    settle. Pinning it to the LIVE revision instead cannot work either — a revision
        //    published after this row exists would make an already-correct row unsaveable, so
        //    `settle()` would start throwing on rows raised yesterday. The chain is the invariant
        //    that is true on the day the row is written and still true after any number of
        //    revisions, and it is exactly the chain `ledgerAgreementFor()` itself walks.
        $trancheAgreement = SuchakCustomerAgreement::query()->find($tranche->customer_agreement_id);
        $outcomeAgreement = SuchakCustomerAgreement::query()->find($outcome->customer_agreement_id);

        if ($trancheAgreement === null || $outcomeAgreement === null
            || (int) $trancheAgreement->service_package_id !== (int) $outcomeAgreement->service_package_id) {
            throw new InvalidArgumentException(
                'A cross-Suchak obligation must name a tranche of the agreement chain its marriage was attributed under.'
            );
        }

        // A package is a Suchak's own price list and nothing stops two of his customers agreeing to
        // the same one, so the package alone does not identify the family. Where both revisions name
        // a customer context, they must name the SAME one.
        if ($trancheAgreement->customer_context_id !== null
            && $outcomeAgreement->customer_context_id !== null
            && (int) $trancheAgreement->customer_context_id !== (int) $outcomeAgreement->customer_context_id) {
            throw new InvalidArgumentException(
                'A cross-Suchak obligation must name a tranche of its own customer, never another customer of the same package.'
            );
        }
    }

    /**
     * M3 half A — WHEN THE CUSTOMER PAID this installment of the success fee, or null.
     *
     * Read from `suchak_success_fee_tranches.settled_at`, which sits beside `customer_payment_id`
     * and is the ONE place this schema can say a customer payment was the success fee. Never a copy:
     * a settled tranche is M9's own predicate and must have one home.
     */
    public function customerPaidAt(): ?Carbon
    {
        return $this->successFeeTranche?->settled_at;
    }

    /**
     * Whether half A can be answered at all for this row.
     *
     * False when the agreement carries no installment plan: with no tranche row there is no
     * per-fee payment pointer, and `suchak_customer_payments` cannot say which fee it paid. Then
     * M3's "whichever is earlier" is half B alone. Reported, not guessed.
     */
    public function customerPaymentIsAnswerable(): bool
    {
        return $this->success_fee_tranche_id !== null;
    }

    /**
     * M3 half B — the wedding day plus the fixed window, by arithmetic on the §6.2 row.
     *
     * `SuchakMarriageOutcome::SHARE_DUE_DAYS_AFTER_MARRIAGE` is the only home for that figure and is
     * deliberately not re-typed here.
     */
    public function marriageClockDueAt(): ?Carbon
    {
        return $this->marriageOutcome?->shareFallsDueAt();
    }

    /**
     * M3 in one value: *"whichever is earlier"*. Null only if the §6.2 row has gone missing, which
     * the `saving` guard makes impossible for a stored row.
     */
    public function fallsDueAt(): ?Carbon
    {
        $paidAt = $this->customerPaidAt();
        $clockAt = $this->marriageClockDueAt();

        if ($paidAt === null) {
            return $clockAt;
        }

        if ($clockAt === null) {
            return $paidAt;
        }

        return $paidAt->lessThan($clockAt) ? $paidAt : $clockAt;
    }

    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    /**
     * Due means PAYABLE NOW, whether or not it has been paid. Settlement is a separate fact — an
     * obligation that is both due and unsettled is exactly what A7's ratio and §7.3's exposure read.
     */
    public function isDue(?Carbon $at = null): bool
    {
        $dueAt = $this->fallsDueAt();

        return $dueAt !== null && $dueAt->lessThanOrEqualTo($at ?? now());
    }

    /** Due, and still not paid. */
    public function isOverdue(?Carbon $at = null): bool
    {
        return ! $this->isSettled() && $this->isDue($at);
    }

    /**
     * Whole days since it fell due, or null when it has not. §7.2's own preference: *"a raw count
     * from the first event beats a ratio that needs volume to move"*.
     */
    public function overdueDays(?Carbon $at = null): ?int
    {
        $at ??= now();
        $dueAt = $this->fallsDueAt();

        if ($dueAt === null || $dueAt->greaterThan($at)) {
            return null;
        }

        return (int) $dueAt->diffInDays($at);
    }

    /**
     * Which half of M3 actually put this row past its deadline. A read for the screen and for a
     * dispute a year later — never a stored column, because it is a comparison of two timestamps
     * that both already have owners.
     */
    public function dueReason(?Carbon $at = null): ?string
    {
        if (! $this->isDue($at)) {
            return null;
        }

        $paidAt = $this->customerPaidAt();
        $clockAt = $this->marriageClockDueAt();

        if ($paidAt !== null && ($clockAt === null || $paidAt->lessThan($clockAt))) {
            return 'customer_paid';
        }

        return 'days_after_recorded_marriage';
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOwedBy(Builder $query, int $suchakAccountId): Builder
    {
        return $query->where('payer_suchak_account_id', $suchakAccountId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOwedTo(Builder $query, int $suchakAccountId): Builder
    {
        return $query->where('payee_suchak_account_id', $suchakAccountId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnsettled(Builder $query): Builder
    {
        return $query->whereNull('settled_at');
    }

    public function payerSuchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class, 'payer_suchak_account_id');
    }

    public function payeeSuchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class, 'payee_suchak_account_id');
    }

    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(SuchakCollaborationRequest::class, 'collaboration_request_id');
    }

    public function marriageOutcome(): BelongsTo
    {
        return $this->belongsTo(SuchakMarriageOutcome::class, 'marriage_outcome_id');
    }

    public function marketplaceChallenge(): BelongsTo
    {
        return $this->belongsTo(SuchakMarketplaceChallenge::class, 'marketplace_challenge_id');
    }

    public function successFeeTranche(): BelongsTo
    {
        return $this->belongsTo(SuchakSuccessFeeTranche::class, 'success_fee_tranche_id');
    }

    public function settledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by_user_id');
    }

    /**
     * Undeletable, like every other evidentiary row in this domain. A7's realized-vs-declared ratio
     * is only worth publishing if its denominator cannot be pruned by the party it judges — a payer
     * who could delete an unpaid obligation would own his own reputation number.
     */
    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak cross-Suchak obligation records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak cross-Suchak obligation records cannot be deleted.');
    }
}
