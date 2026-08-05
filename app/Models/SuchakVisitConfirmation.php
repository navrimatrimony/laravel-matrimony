<?php

namespace App\Models;

use App\Support\MoneyFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakVisitConfirmation extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_PAYOUT_QUALIFIED = 'payout_qualified';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_COMPLETED,
        self::STATUS_CONFIRMED,
        self::STATUS_DISPUTED,
        self::STATUS_PAYOUT_QUALIFIED,
        self::STATUS_CANCELLED,
    ];

    /**
     * A meeting still IN FLIGHT — the pair may not schedule the next one yet.
     *
     * `completed` here literally means completed-but-unconfirmed: the moment the
     * confirmation policy is satisfied, refreshVisitStatus() moves the row to
     * `confirmed`, so a row can only sit at `completed` while somebody's
     * confirmation is still outstanding. M4 — no fee falls due without the
     * customer's confirmation — is why that counts as open: a second meeting
     * must not be arranged on top of one the family has not yet acknowledged.
     * `disputed` is open for the same reason plus section 7.2's leverage.
     *
     * `confirmed` and `payout_qualified` are settled, and D24 says the pair may
     * meet again at the same rate. `cancelled` is over: it is written by
     * {@see \App\Modules\Suchak\Services\SuchakVisitConfirmationService::cancelVisit()}
     * and is what stops a meeting that never happened from blocking the pair
     * forever — with `scheduled` counted as open, the first no-show would
     * otherwise strand the pipeline with no way back.
     */
    public const OPEN_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_COMPLETED,
        self::STATUS_DISPUTED,
    ];

    /**
     * Offline and online meetings are two fully independent prices (D2), never a
     * ratio of each other, so which one a meeting was decides which rate froze
     * onto `fee_amount`.
     */
    public const MODE_OFFLINE = 'offline';
    public const MODE_ONLINE = 'online';

    public const MEETING_MODES = [
        self::MODE_OFFLINE,
        self::MODE_ONLINE,
    ];

    /**
     * Attendance outcome recorded on cancel into event `metadata_json` (U7) —
     * no dedicated column; the append-only trail is the home.
     */
    public const ATTENDANCE_NONE = 'none';

    public const ATTENDANCE_PARTIAL = 'partial';

    public const ATTENDANCE_BOTH = 'both';

    public const ATTENDANCES = [
        self::ATTENDANCE_NONE,
        self::ATTENDANCE_PARTIAL,
        self::ATTENDANCE_BOTH,
    ];

    public const COMPLETION_PENDING = 'pending';
    public const COMPLETION_SUCHAK_MARKED = 'suchak_marked_completed';

    public const COMPLETION_STATUSES = [
        self::COMPLETION_PENDING,
        self::COMPLETION_SUCHAK_MARKED,
    ];

    public const CONFIRMATION_PENDING = 'pending';
    public const CONFIRMATION_CONFIRMED = 'confirmed';
    public const CONFIRMATION_DISPUTED = 'disputed';
    public const CONFIRMATION_NOT_REQUIRED = 'not_required';

    public const CONFIRMATION_STATUSES = [
        self::CONFIRMATION_PENDING,
        self::CONFIRMATION_CONFIRMED,
        self::CONFIRMATION_DISPUTED,
        self::CONFIRMATION_NOT_REQUIRED,
    ];

    public const POLICY_USER_AND_ADMIN = 'user_and_admin';
    public const POLICY_ADMIN_ONLY = 'admin_only';
    public const POLICY_USER_ONLY = 'user_only';

    public const POLICY_MODES = [
        self::POLICY_USER_AND_ADMIN,
        self::POLICY_ADMIN_ONLY,
        self::POLICY_USER_ONLY,
    ];

    /**
     * WHERE A DISPUTED MEETING'S MONEY ENDS UP.
     *
     * Until 2026-08-03 this vocabulary had only the first two entries, the
     * transition was one-way, and nothing in app/ ever wrote it back — so
     * `pending_review` was terminal and a disputed meeting was unpayable
     * forever, including one whose dispute was closed IN THE SUCHAK'S FAVOUR.
     * The three closing values below are the three genuinely different answers
     * an adjudication can give, and they do NOT behave the same:
     *
     * - `upheld`   — the dispute was RESOLVED, i.e. the complaint stood. This
     *                meeting's fee is refused permanently; no confirmation
     *                arriving later can revive it.
     * - `dismissed`— the dispute was REJECTED, i.e. the complaint did not
     *                stand. The case is over and the FREEZE LIFTS: the payout
     *                hold is released and every ordinary door on the meeting
     *                reopens. It does NOT make the fee due — M4 admits no
     *                substitute for the customer's own act, and an admin
     *                deciding a dispute is not the customer confirming. The
     *                meeting goes back to awaiting the family's answer, which
     *                the family may now give.
     * - `closed_no_finding` — the case was filed away with NOBODY adjudicating
     *                (withdrawn, no evidence, out of time; §7.2 lapse at 90
     *                days lands here). No finding means no money conclusion:
     *                the row falls back to its OWN confirmation columns, so a
     *                family that never confirmed still owes nothing (M4) and a
     *                family that already had confirmed is not punished.
     *
     * `dispute_id` is never cleared by any of the three — the trail has to
     * survive the case that made it.
     */
    public const REFUND_NOT_REQUESTED = 'not_requested';
    public const REFUND_PENDING_REVIEW = 'pending_review';
    public const REFUND_UPHELD = 'upheld';
    public const REFUND_DISMISSED = 'dismissed';
    public const REFUND_CLOSED_NO_FINDING = 'closed_no_finding';

    public const REFUND_STATUSES = [
        self::REFUND_NOT_REQUESTED,
        self::REFUND_PENDING_REVIEW,
        self::REFUND_UPHELD,
        self::REFUND_DISMISSED,
        self::REFUND_CLOSED_NO_FINDING,
    ];

    /**
     * The review is over. Reaching any of these is what un-freezes the row.
     */
    public const REFUND_REVIEW_CLOSED_STATUSES = [
        self::REFUND_UPHELD,
        self::REFUND_DISMISSED,
        self::REFUND_CLOSED_NO_FINDING,
    ];

    /**
     * A finding. Somebody actually decided this case, one way or the other.
     *
     * `closed_no_finding` is deliberately NOT here — §7.2 clause 4's lapse lands there, and a
     * case that timed out is the opposite of an answer. That distinction is the whole reason
     * stonewalling cannot clear the stop-loss counter below.
     */
    public const REFUND_REVIEW_FINDING_STATUSES = [
        self::REFUND_UPHELD,
        self::REFUND_DISMISSED,
    ];

    /**
     * THE FOUR NUMBERS OF §7.2, in one place.
     *
     * They are read from the blueprint, not invented, and they live on this model rather than in
     * the sweep service because they are facts ABOUT THIS ROW that three different callers need
     * (the timer, the stop-loss gate, the payout guard). A second copy in a service constant is
     * how two of them end up disagreeing about how long seven days is.
     *
     *  - SILENCE — "Customer silent for 7 days → disputeVisit()" (§7.2 flow, M4/M5: silence opens
     *    a DISPUTE, never an automatic zero and never an automatic payment).
     *  - LAPSE — clause 4: "An unanswered dispute terminates at 90 days: never revivable, never
     *    due, still counted, still visible."
     *  - STOP-LOSS — clause 3: "A helper may not accept a new challenge from the same originating
     *    Suchak while 2 claims, or ₹5,000, sit past their window."
     *
     * The amount is a decimal STRING, like every other money value in this domain, so it can be
     * compared without ever becoming a float. It is INR because §7.2 wrote ₹5,000; a meeting
     * quoted in another currency still counts toward the claim leg but is never added into a
     * rupee total (see SuchakClaimSilenceService::unansweredClaimSummary()).
     */
    public const CLAIM_SILENCE_WINDOW_DAYS = 7;
    public const CLAIM_LAPSE_DAYS = 90;
    public const STOP_LOSS_UNANSWERED_CLAIMS = 2;
    public const STOP_LOSS_UNANSWERED_AMOUNT = '5000.00';
    public const STOP_LOSS_CURRENCY = 'INR';

    /**
     * Dispute closing status → what it means for THIS meeting's money.
     *
     * Declared once, here, because three call sites needed the answer (the
     * write-back, the payout guard and the admin surface) and three hand-written
     * copies of a money mapping is exactly how two of them end up disagreeing.
     *
     * @var array<string, string>
     */
    public const DISPUTE_CLOSE_REFUND_OUTCOME = [
        SuchakDispute::STATUS_RESOLVED => self::REFUND_UPHELD,
        SuchakDispute::STATUS_REJECTED => self::REFUND_DISMISSED,
        SuchakDispute::STATUS_CLOSED => self::REFUND_CLOSED_NO_FINDING,
    ];

    protected $table = 'suchak_visit_confirmations';

    protected $fillable = [
        'pipeline_id',
        'suchak_account_id',
        'helper_suchak_account_id',
        'request_id',
        'representation_id',
        'target_matrimony_profile_id',
        'requesting_matrimony_profile_id',
        'payment_context_id',
        'customer_context_id',
        'customer_agreement_id',
        'platform_payout_id',
        'dispute_id',
        'payout_hold_id',
        'visit_status',
        'confirmation_policy_mode',
        'meeting_sequence',
        'meeting_mode',
        'fee_amount',
        'fee_currency',
        'scheduled_for',
        'scheduled_by_user_id',
        'scheduled_at',
        'schedule_note',
        'suchak_completion_status',
        'suchak_completed_by_user_id',
        'suchak_completed_at',
        'suchak_completion_note',
        'user_confirmation_status',
        'user_confirmed_by_user_id',
        'user_confirmed_at',
        'user_confirmation_note',
        'admin_confirmation_status',
        'admin_confirmed_by_user_id',
        'admin_confirmed_at',
        'admin_confirmation_note',
        'refund_review_status',
        'refund_review_note',
        'claim_unanswered_since',
        'claim_lapsed_at',
        'payout_qualified_at',
    ];

    protected $casts = [
        'meeting_sequence' => 'integer',
        'fee_amount' => 'decimal:2',
        'scheduled_for' => 'datetime',
        'scheduled_at' => 'datetime',
        'suchak_completed_at' => 'datetime',
        'user_confirmed_at' => 'datetime',
        'admin_confirmed_at' => 'datetime',
        'claim_unanswered_since' => 'datetime',
        'claim_lapsed_at' => 'datetime',
        'payout_qualified_at' => 'datetime',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(SuchakPipeline::class, 'pipeline_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    /**
     * Whose candidate was met, when that is not the arranging Suchak's own.
     * Null on an ordinary meeting; set on a marketplace one.
     */
    public function helperSuchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class, 'helper_suchak_account_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRequest::class, 'request_id');
    }

    public function representation(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRepresentation::class, 'representation_id');
    }

    public function targetMatrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'target_matrimony_profile_id');
    }

    public function requestingMatrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'requesting_matrimony_profile_id');
    }

    public function paymentContext(): BelongsTo
    {
        return $this->belongsTo(SuchakPaymentContext::class, 'payment_context_id');
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    /**
     * The agreement revision that quoted `fee_amount` / `fee_currency`.
     *
     * A frozen figure with no source is not auditable a year later, and there
     * was no other way to reach it: the meeting hangs off `payment_context_id`,
     * and a payment context names no package and no agreement.
     */
    public function customerAgreement(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerAgreement::class, 'customer_agreement_id');
    }

    public function platformPayout(): BelongsTo
    {
        return $this->belongsTo(SuchakPlatformPayout::class, 'platform_payout_id');
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(SuchakDispute::class, 'dispute_id');
    }

    public function payoutHold(): BelongsTo
    {
        return $this->belongsTo(SuchakPayoutHold::class, 'payout_hold_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SuchakVisitConfirmationEvent::class, 'visit_confirmation_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    /**
     * Is a human going to be billed for this meeting?
     *
     * M4 hangs off exactly this question — "no fee falls due without the
     * customer's confirmation" is a rule about MONEY, so the answer has to come
     * from the frozen quote and not from the confirmation policy in force. A
     * quoted zero is not a charge; a null is not a charge either (nothing was
     * agreed for this meeting).
     */
    public function isFeeBearing(): bool
    {
        return $this->fee_amount !== null && (float) $this->fee_amount > 0;
    }

    /**
     * The frozen quote as text, amount and unit together.
     *
     * Exposed here rather than left to callers because the two halves must never
     * be paired by hand: `MoneyFormat::amount($visit->fee_amount)` alone silently
     * defaults to '₹' and renders a USD meeting as rupees, which is the whole
     * reason `fee_currency` exists. `MoneyFormat` stays the single formatter —
     * this only binds it to the right unit.
     *
     * The INR fallback covers a row written before `fee_currency` existed; every
     * such row was rupees, because that was the only currency the engine could
     * price in.
     */
    public function getFeeDisplayAttribute(): ?string
    {
        return MoneyFormat::amount($this->fee_amount, $this->fee_currency ?? 'INR');
    }

    /**
     * Is a dispute STILL governing this row?
     *
     * `dispute_id !== null` was the old test, in three separate guards, and it
     * is the wrong question: `dispute_id` is a permanent trail marker, so it
     * answered "was there ever a dispute", which froze a meeting whose dispute
     * had already been decided — including one decided for the Suchak.
     *
     * The review status is the one column that says whether the case is over,
     * so it is what is asked here. `visit_status` is consulted only as a
     * belt-and-braces reading of a row that was disputed before this vocabulary
     * existed (its `refund_review_status` is `pending_review`, which is
     * correctly still open).
     */
    public function hasOpenDispute(): bool
    {
        if (in_array($this->refund_review_status, self::REFUND_REVIEW_CLOSED_STATUSES, true)) {
            return false;
        }

        return $this->dispute_id !== null || $this->visit_status === self::STATUS_DISPUTED;
    }

    /**
     * The review found FOR the complaint: this meeting's fee is refused, and no
     * later confirmation can revive it.
     */
    public function isFeeRefusedByReview(): bool
    {
        return $this->refund_review_status === self::REFUND_UPHELD;
    }

    /**
     * The review found AGAINST the complaint. The freeze lifts; the money does not move.
     *
     * THIS METHOD WAS CALLED `isFeeAllowedByReview()` AND IT WAS A LIE THE CODE BELIEVED. Two
     * call sites read it as "the fee may now be paid": the payout guard accepted it INSTEAD of
     * the confirmation policy, and `refreshVisitStatus()` moved the meeting straight to
     * `confirmed`. So one admin rejecting one dispute — over a single form post, with the
     * customer nowhere in it — made a fee fall due on a meeting nobody had ever confirmed. M4 is
     * absolute and admits no such route: *no fee falls due without the customer's confirmation*.
     * An admin deciding a dispute and a customer confirming a meeting are different acts by
     * different people, and no mapping between them is legitimate.
     *
     * What a dismissal genuinely means is narrower, and is all this name now claims: the case is
     * over and it did not go against the Suchak, so the meeting STOPS BEING FROZEN. That part
     * matters — before 2026-08-03 the guards tested `dispute_id !== null`, a trail marker that is
     * never cleared, so a dispute settled in the Suchak's favour left the meeting unchangeable
     * and unpayable forever. Un-freezing is the fix for that; paying is not.
     *
     * The meeting therefore returns to exactly where it was before the contest: awaiting the
     * family's answer. It is not a dead end — the family, whose complaint has just been found not
     * to stand, may confirm ({@see \App\Modules\Suchak\Services\SuchakVisitConfirmationService::confirmByUser()}
     * accepts a row whose column reads `disputed`), and their own act is what makes the fee due.
     * If they never answer, §7.2's lapse ends it at 90 days with nothing owed — which the
     * blueprint states outright, so a claim ending unpaid is a designed outcome, not a defect.
     *
     * Still deliberately NOT written into `user_confirmation_status`: an adjudication is not the
     * customer's word, and stamping the customer's column with an admin's finding would put a
     * confirmation in the record that nobody ever gave.
     */
    public function isComplaintDismissedByReview(): bool
    {
        return $this->refund_review_status === self::REFUND_DISMISSED;
    }

    /**
     * WHEN THE CLAIM REACHED THE FAMILY — §7.2 clause 5, "clocks start on delivery, not dispatch".
     *
     * On this production the two instants are the same one, and this method exists to say so in
     * code rather than to hide it. Nothing is dispatched: there is no WhatsApp channel and no SMS
     * provider (§10 S4), so no claim is pushed anywhere. The claim is PULLED — the meeting appears
     * on the family's door the instant the Suchak marks it complete, which is `suchak_completed_at`.
     * There is no earlier "sent" event that this could be later than.
     *
     * WHEN S4 LANDS this becomes a real delivery receipt and only this method changes; the timer,
     * the stop-loss and the lapse all ask the question here and never read the column directly.
     *
     * WHAT THE GAP COSTS TODAY, stated plainly because it is not nothing: a family with no login
     * has no meeting list to read (the member routes need `$request->user()`), so the clock can run
     * on a claim they were never shown. The failure direction is deliberate — silence opens a
     * DISPUTE, which charges the family nothing and freezes the CLAIMANT's payouts (§7.3). The
     * party who made a claim nobody could answer is the party who pays for it.
     */
    public function claimDeliveredAt(): ?Carbon
    {
        if ($this->suchak_completion_status !== self::COMPLETION_SUCHAK_MARKED) {
            return null;
        }

        return $this->suchak_completed_at;
    }

    /**
     * The instant the family's seven days run out — and, in parallel (clause 5), the originating
     * Suchak's. Null when no claim has been made yet.
     */
    public function claimSilenceDueAt(): ?Carbon
    {
        return $this->claimDeliveredAt()?->copy()->addDays(self::CLAIM_SILENCE_WINDOW_DAYS);
    }

    /**
     * Has anybody actually ANSWERED this claim?
     *
     * Two kinds of answer, and no third: the family said yes or no (`confirmed` / `disputed` in
     * their own column), or a case about it was decided with a finding. A `closed_no_finding` is
     * not an answer — it is what the 90-day lapse writes, and treating it as one would mean a
     * Suchak who ignores a claim for three months ends up in the same place as one who resolved it.
     */
    public function isClaimAnswered(): bool
    {
        if (in_array($this->refund_review_status, self::REFUND_REVIEW_FINDING_STATUSES, true)) {
            return true;
        }

        return $this->user_confirmation_status === self::CONFIRMATION_CONFIRMED
            || $this->user_confirmation_status === self::CONFIRMATION_DISPUTED;
    }

    /**
     * Does this row count against its originating Suchak under §7.2 clause 3?
     *
     * `claim_unanswered_since` is set once, when the silence window closed, and is never cleared,
     * so the only way out of the count is to ANSWER IN TIME.
     *
     * THE SECOND CLAUSE IS CLAUSE 4'S "STILL COUNTED", AND IT IS LOAD-BEARING. Without it this
     * method read `claim_unanswered_since !== null && ! isClaimAnswered()`, so a family answering
     * on day 99 took the row straight back out of the counter — and with it, out of
     * {@see isClaimLapsed()}, which used to be gated on this method. One late answer therefore
     * cleared 90 days of stonewalling AND made the fee payable again. M3 forbids exactly that:
     * doing nothing must never make an obligation disappear. A claim that reached its lapse is in
     * the count permanently, whatever arrives afterwards.
     */
    public function hasUnansweredClaim(): bool
    {
        if ($this->claim_unanswered_since === null) {
            return false;
        }

        return ! $this->isClaimAnswered() || $this->isClaimLapsed();
    }

    /**
     * {@see hasUnansweredClaim()} as a query — the SAME three clauses, in the same order.
     *
     * It exists so the stop-loss counter never hand-writes that predicate: the row-level answer
     * and the aggregate answer must agree exactly, or a Suchak sees one number on his card and is
     * refused by a different one at the door.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnansweredClaims(Builder $query): Builder
    {
        return $query
            ->whereNotNull('claim_unanswered_since')
            ->where(function (Builder $counted): void {
                $counted
                    // Still unanswered — which also covers a claim past 90 days that nothing has
                    // stamped yet, since an unstamped lapse is by definition still unanswered.
                    ->where(function (Builder $unanswered): void {
                        $unanswered
                            ->whereNotIn('refund_review_status', self::REFUND_REVIEW_FINDING_STATUSES)
                            ->whereNotIn('user_confirmation_status', [
                                self::CONFIRMATION_CONFIRMED,
                                self::CONFIRMATION_DISPUTED,
                            ]);
                    })
                    // Or answered only AFTER it had already lapsed. Clause 4: still counted.
                    ->orWhereNotNull('claim_lapsed_at');
            });
    }

    /**
     * The instant this claim's 90 days run out. Null until the silence window has closed.
     */
    public function claimLapsesAt(): ?Carbon
    {
        return $this->claim_unanswered_since?->copy()->addDays(self::CLAIM_LAPSE_DAYS);
    }

    /**
     * §7.2 clause 4 — the claim terminated at 90 days: "never revivable, never due".
     *
     * A RECORDED FACT FIRST, ARITHMETIC SECOND, and it needs both halves.
     *
     * This method used to be arithmetic ONLY, gated on {@see hasUnansweredClaim()}, and that made
     * the lapse a description of how the row looks right now rather than something that happened.
     * A family confirming on day 99 flipped `hasUnansweredClaim()` false, which flipped this false,
     * which let `assertEligibleForPayout()` pay a claim that had already terminated — and dropped
     * the stop-loss counter to zero on the way past. Lapsing had become undoable by the one event
     * clause 4 names ("never revivable ... even if the family answers afterwards").
     *
     * So:
     *  1. `claim_lapsed_at` is consulted FIRST and on its own. Once the fact is on the row nothing
     *     can take it off — not a confirmation, not a contest, not an adjudication.
     *  2. The arithmetic remains, so "never due" holds on a production where `schedule:run` never
     *     fires and nothing has ever stamped the row. It is reached only while the claim is still
     *     unanswered, which is exactly the window in which the fact has not been written yet — and
     *     every path that could record an answer stamps the fact before writing it
     *     ({@see \App\Modules\Suchak\Services\SuchakVisitConfirmationService::recordClaimLapseIfDue()}).
     *
     * A finding still beats the clock, but only an IN-TIME one. An adjudication that lands on day
     * 8 settles the case and no lapse ever occurs; one that lands on day 100 arrives after the
     * claim has already terminated and finds the fact already recorded. "Never revivable" does not
     * make an exception for the adjudicator.
     */
    public function isClaimLapsed(?Carbon $at = null): bool
    {
        if ($this->claim_lapsed_at !== null) {
            return true;
        }

        $lapsesAt = $this->claimLapsesAt();

        return $lapsesAt !== null
            && ! $this->isClaimAnswered()
            && $lapsesAt->lessThanOrEqualTo($at ?? now());
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak visit confirmation records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak visit confirmation records cannot be deleted.');
    }
}
