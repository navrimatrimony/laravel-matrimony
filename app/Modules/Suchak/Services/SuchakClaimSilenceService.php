<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakDispute;
use App\Models\SuchakVisitConfirmation;
use App\Models\User;
use App\Support\MoneyFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * THE §7.2 CLOCK — silence, stop-loss, lapse.
 *
 * Three rules from one blueprint paragraph, and they only work together:
 *
 *  1. SILENCE. A claim is made, the family does not answer inside 7 days, and a DISPUTE opens
 *     (M4/M5 — never an automatic zero, never an automatic payment). The write itself belongs to
 *     {@see SuchakVisitConfirmationService::openSilenceDispute()}, which is the only writer of
 *     `suchak_visit_confirmations` (FIELD-OWNERSHIP-MAP). This service decides WHICH rows.
 *  2. STOP-LOSS (clause 3). While 2 claims, or ₹5,000, sit unanswered against an originating
 *     Suchak, no helper may accept a new challenge from him.
 *  3. LAPSE (clause 4). An unanswered dispute terminates at 90 days — "never revivable, never
 *     due, still counted, still visible".
 *
 * ── WHY IT IS NOT A QUEUED JOB, AND WHY IT IS SWEPT TWICE ────────────────────────────────────
 *
 * Production may not run `schedule:run` at all, and the notifications and governance queues have
 * had no worker since 2026-06-17. A Phase-3 timer written as a queued job would silently never
 * fire, and a money timer that never fires is worse than no timer: the product would claim the
 * family is protected while nothing watches the clock. So there are three independent guarantees,
 * in descending order of how much they trust the infrastructure:
 *
 *  a. THE DAILY SWEEP runs SYNCHRONOUSLY inside the one Suchak timer that demonstrably works —
 *     `suchak:scheduled-jobs` → `SuchakScheduledJobsConsolidationService` → no `ShouldQueue`
 *     anywhere on the path.
 *  b. THE LAZY SWEEP runs on the read path, scoped to one account, exactly as
 *     `SuchakRequestPipelineService::expireDuePipelines()` and
 *     `SuchakMarketplaceChallengeService::expireDue()` already hedge. It is deliberately wired to
 *     the STOP-LOSS GATE itself: the counter is swept immediately before it is judged, so the
 *     answer at the door is correct even if the scheduler has never run once.
 *  c. THE LAPSE IS ARITHMETIC *AND* A RECORDED FACT — it needs both, and an earlier version
 *     that had only the first was wrong. {@see SuchakVisitConfirmation::isClaimLapsed()} computes
 *     "past 90 days" from `claim_unanswered_since`, so "never due" holds with no sweep at all;
 *     and `claim_lapsed_at` records that it happened, so a family answering on day 99 cannot
 *     unmake it. Pure arithmetic over "unanswered AND past 90 days" had two moving halves, and a
 *     late answer falsified the first one — which erased the lapse, paid the claim, and cleared
 *     the stop-loss counter in one move. The fact is stamped by whichever comes first: this
 *     sweep, or the very act that would have erased it
 *     ({@see \App\Modules\Suchak\Services\SuchakVisitConfirmationService::recordClaimLapseIfDue()}).
 *     What (a) adds is the tidy-up a human can see: the dispute closed, the hold lifted, the case
 *     off the queue.
 *
 * ── WHAT IS *NOT* HERE ───────────────────────────────────────────────────────────────────────
 *
 * No new dispute vocabulary, no second hold engine, no second meeting engine. `SuchakDispute`,
 * `SuchakPayoutHold`, `refund_review_status` and `DISPUTE_CLOSE_HOLD_OUTCOME` all already exist
 * and are bound to; the lapse closes a case through `SuchakSafetyService::closeDispute()`, the one
 * audited path, rather than moving statuses behind its back.
 */
class SuchakClaimSilenceService
{
    /**
     * How many rows one sweep will touch.
     *
     * Matches `SuchakScheduledJobsConsolidationService::PER_JOB_LIMIT`, deliberately — but unlike
     * the eight jobs beside it, this one never lets the cap hide a backlog: every sweep reports
     * `due_total` next to `processed`, and the selection is ordered OLDEST FIRST so the overflow
     * drains first-in-first-out instead of starving the oldest claim forever. A money timer that
     * silently skips row 501 is a different defect from a slow one.
     */
    private const SWEEP_LIMIT = 500;

    public function __construct(
        private readonly SuchakVisitConfirmationService $visitConfirmationService,
        private readonly SuchakSafetyService $safetyService,
    ) {
    }

    // ── 1. The seven-day silence ──────────────────────────────────────────────────────────────

    /**
     * Open a dispute on every claim whose window closed with no answer.
     *
     * NEVER THROWS ON A ROW. One malformed meeting must not abort the remaining claims — and,
     * because `SuchakScheduledJobsConsolidationService::runTrackedJob()` re-throws and aborts
     * every LATER sub-job in the same run, a throw here would also take out whatever is scheduled
     * after it. Failures are counted and named in the metrics instead of ending the run.
     *
     * @return array<string, mixed>
     */
    public function sweepSilenceDue(?SuchakAccount $account = null, ?Carbon $at = null, int $limit = self::SWEEP_LIMIT): array
    {
        $at ??= now();
        $query = $this->silenceDueQuery($account, $at);

        $dueTotal = (clone $query)->count();
        $dueIds = $query
            ->orderBy('suchak_completed_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $opened = 0;
        $skipped = 0;
        $failed = [];

        foreach ($dueIds as $visitId) {
            $visit = SuchakVisitConfirmation::query()->find($visitId);
            if (! $visit instanceof SuchakVisitConfirmation) {
                $skipped++;

                continue;
            }

            try {
                $fresh = $this->visitConfirmationService->openSilenceDispute($visit, $at);
                $fresh->claim_unanswered_since === null ? $skipped++ : $opened++;
            } catch (Throwable $throwable) {
                $failed[] = [
                    'visit_confirmation_id' => (int) $visitId,
                    'error_class' => class_basename($throwable),
                ];
            }
        }

        return [
            'due_total' => $dueTotal,
            'processed' => $dueIds->count(),
            // Non-zero means the cap bit. Visible, never silent — and FIFO, so tomorrow's run
            // starts with today's oldest leftovers rather than with whatever is newest.
            'deferred_backlog' => max(0, $dueTotal - $dueIds->count()),
            'disputes_opened' => $opened,
            'skipped_not_due' => $skipped,
            'failed_rows' => count($failed),
            'failures' => $failed,
        ];
    }

    /**
     * Rows whose claim is old enough, cheaply.
     *
     * The status/timestamp pair is the indexed half (`sk_visit_confirmations_claim_clock_idx`).
     * Everything else here restates {@see SuchakVisitConfirmationService::isSilenceDisputeDue()}
     * as a filter so the sweep does not load the whole table to reject it — that method stays the
     * authority, and it re-checks every clause under the row lock before writing anything.
     *
     * @return Builder<SuchakVisitConfirmation>
     */
    private function silenceDueQuery(?SuchakAccount $account, Carbon $at): Builder
    {
        return SuchakVisitConfirmation::query()
            ->where('visit_status', SuchakVisitConfirmation::STATUS_COMPLETED)
            ->where('suchak_completion_status', SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED)
            ->whereNotNull('suchak_completed_at')
            ->where('suchak_completed_at', '<=', $at->copy()->subDays(SuchakVisitConfirmation::CLAIM_SILENCE_WINDOW_DAYS))
            ->where('user_confirmation_status', SuchakVisitConfirmation::CONFIRMATION_PENDING)
            ->where('refund_review_status', SuchakVisitConfirmation::REFUND_NOT_REQUESTED)
            ->whereNull('claim_unanswered_since')
            ->whereNull('dispute_id')
            // M4 is a rule about FEES. A meeting nobody is billed for has nothing to dispute, and
            // freezing a Suchak's payouts over a ₹0 row is leverage applied to nothing.
            ->whereNotNull('fee_amount')
            ->where('fee_amount', '>', 0)
            ->when($account, fn (Builder $query): Builder => $query->where('suchak_account_id', $account->id));
    }

    // ── 2. The stop-loss (clause 3) ───────────────────────────────────────────────────────────

    /**
     * WHAT SITS UNANSWERED AGAINST ONE ORIGINATING SUCHAK.
     *
     * Read from the VISIT side, never the dispute side. `suchak_disputes` has no foreign key back
     * to the meeting, so a counter starting there has no index path to the money or to the two
     * accounts — it would need a scan and a guess. Every column this needs is already on
     * `suchak_visit_confirmations`, on one indexed row: who arranged it (`suchak_account_id`),
     * what it cost (`fee_amount`), whether a case exists (`dispute_id`) and whether the claim was
     * ever answered. No FK is added and no join is performed.
     *
     * SCOPED TO THE ORIGINATING SUCHAK, ACROSS ALL HELPERS. Clause 2 publishes exactly this figure
     * on his card — "6 helper claims unanswered, oldest 91 days" — and it is not per-helper there.
     * Per-pair counting would let a Suchak stonewall one helper and go on recruiting the next
     * three; the victims would rotate and the abuse would not stop.
     *
     * CURRENCY. The claim leg counts every meeting whatever it was priced in; the ₹5,000 leg adds
     * up only rupee meetings, because ₹5,000 is a rupee threshold and adding a dollar fee into it
     * would be inventing an exchange rate. A `fee_currency` of NULL is a row written before the
     * column existed, and every one of those was rupees.
     *
     * @return array{claims: int, amount: string, amount_display: ?string, currency: string, oldest_days: ?int, oldest_since: ?string, blocked: bool, reasons: list<string>}
     */
    public function unansweredClaimSummary(SuchakAccount $originating, ?Carbon $at = null): array
    {
        $at ??= now();

        $base = SuchakVisitConfirmation::query()
            ->where('suchak_account_id', $originating->id)
            ->unansweredClaims();

        $claims = (clone $base)->count();
        $amount = (string) ((clone $base)
            ->where(function (Builder $query): void {
                $query
                    ->where('fee_currency', SuchakVisitConfirmation::STOP_LOSS_CURRENCY)
                    ->orWhereNull('fee_currency');
            })
            ->sum('fee_amount'));
        $oldest = (clone $base)->min('claim_unanswered_since');
        $oldestSince = $oldest === null ? null : Carbon::parse((string) $oldest);

        $reasons = [];
        if ($claims >= SuchakVisitConfirmation::STOP_LOSS_UNANSWERED_CLAIMS) {
            $reasons[] = 'claim_count';
        }

        // bccomp-free string comparison would be wrong on '900' vs '5000'; the two sides are both
        // decimal money strings, so they are compared as numbers and never stored as floats.
        if ((float) $amount >= (float) SuchakVisitConfirmation::STOP_LOSS_UNANSWERED_AMOUNT) {
            $reasons[] = 'amount';
        }

        return [
            'claims' => $claims,
            'amount' => $amount,
            'amount_display' => MoneyFormat::amount($amount, SuchakVisitConfirmation::STOP_LOSS_CURRENCY),
            'currency' => SuchakVisitConfirmation::STOP_LOSS_CURRENCY,
            // A raw count and a raw age, exactly as clause 2 asks — "a raw count from the first
            // event beats a ratio that needs volume to move".
            'oldest_days' => $oldestSince === null ? null : (int) abs($oldestSince->diffInDays($at)),
            'oldest_since' => $oldestSince?->toIso8601String(),
            'blocked' => $reasons !== [],
            'reasons' => $reasons,
        ];
    }

    /**
     * The counter, swept first — the READ half of hedge (b).
     *
     * Every surface that shows or acts on this figure uses this one, never the bare summary: a
     * card that says "0 unanswered" while the gate refuses the helper two seconds later is worse
     * than no card. Sweeping on a read is not new on this path — `openListing()` already writes
     * D18's log row, and `expireDue()` already closes challenges on a browse.
     *
     * @return array{claims: int, amount: string, amount_display: ?string, currency: string, oldest_days: ?int, oldest_since: ?string, blocked: bool, reasons: list<string>}
     */
    public function unansweredClaimSummaryAfterSweep(SuchakAccount $originating, ?Carbon $at = null): array
    {
        $this->sweepForAccount($originating, $at);

        return $this->unansweredClaimSummary($originating, $at);
    }

    /**
     * THE DOOR the stop-loss is enforced at: a helper answering a challenge.
     *
     * Swept first, then judged. The lazy sweep here is what makes the rule true on a production
     * with no scheduler: an unanswered claim that crossed its window an hour ago is stamped and
     * counted before this Suchak's next helper is admitted, rather than waiting for 04:00.
     *
     * Refused in Marathi, with the reason and the numbers, because the person refused is a Suchak
     * being told he cannot take work — "not allowed" without a figure is unactionable. Digits are
     * Latin, amounts go through MoneyFormat.
     */
    public function assertHelperMayAcceptChallengeFrom(SuchakAccount $originating, ?Carbon $at = null): void
    {
        $summary = $this->unansweredClaimSummaryAfterSweep($originating, $at);
        if (! $summary['blocked']) {
            return;
        }

        throw new InvalidArgumentException(
            'या सूचकाकडे '.$summary['claims'].' दावे उत्तराविना प्रलंबित आहेत ('
            .($summary['amount_display'] ?? MoneyFormat::amount(0)).')'
            .($summary['oldest_days'] === null ? '' : ', सर्वात जुना '.$summary['oldest_days'].' दिवसांचा')
            .'. ते निकाली निघेपर्यंत त्यांचे नवीन आव्हान स्वीकारता येणार नाही.'
        );
    }

    /**
     * The lazy, account-scoped sweep (b) — safe to call from any read or gate.
     *
     * Only the silence half. The lapse closes a dispute, which is an audited admin act, and a
     * browsing Suchak is not an admin; that half belongs to the daily run.
     *
     * @return int disputes opened
     */
    public function sweepForAccount(SuchakAccount $account, ?Carbon $at = null): int
    {
        return (int) $this->sweepSilenceDue($account, $at)['disputes_opened'];
    }

    // ── 3. The lapse (clause 4) ───────────────────────────────────────────────────────────────

    /**
     * Terminate every claim that has now been unanswered for 90 days.
     *
     * WHAT "TERMINATE" MEANS, and how each word of clause 4 is actually delivered:
     *
     *  - "never revivable" — `claim_lapsed_at` is stamped and never cleared, so no confirmation,
     *    contest or finding arriving afterwards can put the claim back in play. `disputeVisit()`
     *    already refuses a second contest on any row that has been disputed once.
     *  - "never due" — `SuchakVisitConfirmation::isClaimLapsed()` reads that fact, and falls back
     *    to arithmetic on `claim_unanswered_since` when nothing has stamped the row yet;
     *    `assertEligibleForPayout()` refuses either way. This holds whether or not this method
     *    ever runs, which is the entire point of keeping the arithmetic.
     *  - "still counted" — `claim_unanswered_since` is NOT cleared, the closing status is `closed`
     *    → `closed_no_finding` (not a finding), and `hasUnansweredClaim()` is
     *    `! isClaimAnswered() || isClaimLapsed()`, so the row stays in the count even once somebody
     *    finally answers. THIS IS THE M3 GUARANTEE: waiting out the 90 days does not clear a
     *    Suchak's counter — not by silence, and not by a late answer either — so an obligation
     *    cannot be made to disappear by doing nothing.
     *  - "still visible" — nothing is deleted. The dispute row, the hold row and the append-only
     *    visit trail all survive; `dispute_id` and `payout_hold_id` are never cleared.
     *
     * WHY IT NEEDS AN ADMIN. Closing a case writes an `AdminAuditLog`, and this codebase has
     * exactly one audited path for it (`SuchakSafetyService::closeDispute()`). Rather than move
     * the statuses behind that path's back — which would leave the payout hold frozen and the
     * money answer unwritten — this job takes the same admin the seven other admin-governed
     * scheduled jobs take, and SKIPS when the run has none. Nothing about the money depends on it
     * running: the lapse is already enforced by arithmetic above.
     *
     * @return array<string, mixed>
     */
    public function sweepLapsedClaims(
        ?User $admin = null,
        ?SuchakAccount $account = null,
        ?Carbon $at = null,
        int $limit = self::SWEEP_LIMIT,
    ): array {
        $at ??= now();
        $query = $this->lapsedQuery($account, $at);
        $dueTotal = (clone $query)->count();

        if (! $admin instanceof User) {
            return [
                'due_total' => $dueTotal,
                'processed' => 0,
                'deferred_backlog' => $dueTotal,
                'claims_lapsed' => 0,
                'failed_rows' => 0,
                'failures' => [],
                // The claims are already unpayable and already counted; only the tidy-up waits.
                'admin_required' => true,
            ];
        }

        $dueIds = $query
            ->orderBy('claim_unanswered_since')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('dispute_id')
            ->unique()
            ->values();

        $lapsed = 0;
        $failed = [];

        foreach ($dueIds as $disputeId) {
            $dispute = SuchakDispute::query()->find($disputeId);
            if (! $dispute instanceof SuchakDispute
                || ! in_array($dispute->status, [SuchakDispute::STATUS_OPEN, SuchakDispute::STATUS_UNDER_REVIEW], true)) {
                continue;
            }

            try {
                $this->safetyService->closeDispute(
                    $dispute,
                    $admin,
                    // `closed`, never `resolved` or `rejected`. Nobody adjudicated this; the clock
                    // ran out. `DISPUTE_CLOSE_REFUND_OUTCOME` maps it to `closed_no_finding` and
                    // `DISPUTE_CLOSE_HOLD_OUTCOME` cancels the hold rather than releasing it —
                    // "released" would read as a finding for the Suchak, which this is not.
                    SuchakDispute::STATUS_CLOSED,
                    'Lapsed under blueprint §7.2 clause 4: no answer within '
                        .SuchakVisitConfirmation::CLAIM_LAPSE_DAYS
                        .' days of the claim going unanswered. Closed by the consolidated Suchak scheduled job, NOT adjudicated — the claim is never revivable and never due, and it is still counted against the originating Suchak.',
                );
                $lapsed++;
            } catch (Throwable $throwable) {
                $failed[] = [
                    'dispute_id' => (int) $disputeId,
                    'error_class' => class_basename($throwable),
                ];
            }
        }

        return [
            'due_total' => $dueTotal,
            'processed' => $dueIds->count(),
            'deferred_backlog' => max(0, $dueTotal - $dueIds->count()),
            'claims_lapsed' => $lapsed,
            'failed_rows' => count($failed),
            'failures' => $failed,
            'admin_required' => false,
        ];
    }

    /**
     * Claims 90 days past their silence stamp, still unanswered, still carrying an open case.
     *
     * @return Builder<SuchakVisitConfirmation>
     */
    private function lapsedQuery(?SuchakAccount $account, Carbon $at): Builder
    {
        return SuchakVisitConfirmation::query()
            ->unansweredClaims()
            ->whereNotNull('dispute_id')
            ->where('refund_review_status', SuchakVisitConfirmation::REFUND_PENDING_REVIEW)
            ->where('claim_unanswered_since', '<=', $at->copy()->subDays(SuchakVisitConfirmation::CLAIM_LAPSE_DAYS))
            ->when($account, fn (Builder $query): Builder => $query->where('suchak_account_id', $account->id));
    }
}
