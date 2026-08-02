<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakServicePackage;
use App\Models\SuchakSuccessFeeTranche;
use App\Support\MoneyFormat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The ONE owner of blueprint section 7.4 — the success-fee split, its four arithmetic rules,
 * the canonical shape that goes into the agreement snapshot digest, AND the release ledger
 * (M9, M10, §7.4's per-tranche attribution).
 *
 * Nothing else may compute a tranche amount. T2 ("the parts must sum to the whole, exactly")
 * is only true if a single routine does the rounding, and that routine is {@see amounts()}.
 *
 * ── THE LEDGER HALF ──────────────────────────────────────────────────────────────────────────
 *
 * `released_by_collaboration_request_id`, `released_by_stage_event_id`, `released_at`,
 * `customer_payment_id` and `settled_at` shipped with the table and had NO ORIGINATOR: the only
 * code that ever assigned them was the copy-forward in
 * `SuchakAgreementService::persistTranchePlan()`, which moves state a previous revision already
 * held. Nothing created that state, so `isReleased()`, `isSettled()` and `isCommitted()` could
 * only ever return false and every rule that reads them was inert. {@see release()} and
 * {@see settle()} are the originators.
 *
 * ── THE RELEASE IS ARITHMETIC, NOT AN EVENT ──────────────────────────────────────────────────
 *
 * Copied from Phase 3's discipline (see `SuchakVisitConfirmation::isClaimLapsed`): production may
 * not run `schedule:run`, and the notifications and governance queues have had no worker since
 * 2026-06-17, so nothing may depend on a job firing at the right moment.
 *
 * {@see entitlement()} therefore DERIVES the whole ledger from facts that are already recorded —
 * the settled rungs of the engagement and the frozen split — and derives it identically whenever
 * it is asked. {@see release()} writes that derivation down, and writing it down changes nothing
 * about what it says: `released_at` is the instant the RUNG settled, never the instant the writer
 * happened to run. A release recorded a year late carries the same date as one recorded the same
 * afternoon, and a release never recorded at all still reads correctly through the GET door.
 *
 * Messages thrown from here are Marathi because a Suchak reads them on the screen where he
 * types the split. The English exceptions elsewhere in this module are internal invariants
 * ("only the latest revision can…"), which a customer or Suchak never sees; these are input
 * validation of what a human just entered.
 */
class SuchakSuccessFeeTrancheService
{
    /**
     * The last rung that may RELEASE a tranche — a position on the one ladder, never a second list.
     *
     * `share_settled` sits after `marriage` on STAGE_LADDER and is the HELPER'S OWN RECEIPT for the
     * cross-Suchak share (§6a: "helper marks; closes the loop"; A7 makes it a money rule). It
     * settles on the claim, carries no confirmation and needs no second party. The ladder does not
     * enforce monotonic progress — `SuchakCollaborationService::claimStage()` checks the ENGAGEMENT's
     * state and the rung's ACTOR, never that the rungs below it exist — so a helper can claim
     * `share_settled` on an engagement that never reached a wedding. Were it allowed to release,
     * M10's cascade below would then fire EVERY tranche in the plan, the largest sum in the system,
     * on one unconfirmed tap by the party being paid.
     *
     * A receipt for money is not evidence about a couple. The cascade stops at the wedding.
     */
    public const LAST_RELEASING_STAGE = SuchakCollaborationStageEvent::STAGE_MARRIAGE;

    /**
     * The FIRST rung that may release a tranche — the floor the cap above was missing.
     *
     * §7.4 names exactly three releasing events: लग्न ठरले, साखरपुडा, विवाह. Without a floor,
     * every rung below `marriage_settled` was a legal trigger, and `SuchakCollaborationStageEvent`
     * settles all of them ON THE CLAIM — `isSettled()` needs `confirmed_at` only for the three
     * CONFIRMABLE_STAGES (D26). So a plan of [`meeting_scheduled` 100%] released the WHOLE success
     * fee on one tap by a single Suchak, with no customer confirmation anywhere: M4 ("no fee falls
     * due without the customer's confirmation"), D25 (every rupee is earned by an event that has
     * already happened) and §7.4's refund argument, all breached by the same missing constant.
     * `assertPlanChangeAllowed()` then froze the row permanently, because it was committed.
     *
     * A position, exactly like the cap — the two together are a WINDOW on the one ladder, read by
     * {@see releasingStages()}. The window comes out as CONFIRMABLE_STAGES and must: §7.4's three
     * releasing events and D26's three claim-then-confirm rungs are the same three, and a test
     * pins that so the two can never drift apart in silence.
     */
    public const FIRST_RELEASING_STAGE = SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED;

    /**
     * The rungs a tranche may be triggered by — DERIVED from the ladder between the two positions
     * above, never a second hand-written list. Moving either constant moves this set with it.
     *
     * @return list<string>
     */
    public static function releasingStages(): array
    {
        $first = SuchakCollaborationStageEvent::stageIndex(self::FIRST_RELEASING_STAGE);
        $last = SuchakCollaborationStageEvent::stageIndex(self::LAST_RELEASING_STAGE);

        return array_values(array_filter(
            SuchakCollaborationStageEvent::STAGE_LADDER,
            static function (string $stageKey) use ($first, $last): bool {
                $index = SuchakCollaborationStageEvent::stageIndex($stageKey);

                return $index >= $first && $index <= $last;
            },
        ));
    }

    /**
     * True when this rung may release money. Free text answers false rather than throwing, because
     * the ledger reads stage keys off rows written long before this guard existed.
     */
    public static function isReleasingStage(?string $stageKey): bool
    {
        return $stageKey !== null && in_array($stageKey, self::releasingStages(), true);
    }

    /**
     * The three, in Marathi, for a refusal a Suchak can act on — one derivation, one wording.
     */
    private function releasingStageLabels(): string
    {
        return implode(', ', array_map(
            static fn (string $stageKey): string => '"'.SuchakCollaborationStageEvent::stageLabel($stageKey).'"',
            self::releasingStages(),
        ));
    }

    /**
     * @param  array<int, mixed>  $input  raw rows: trigger_stage_key, share_percent, optional
     *                                    sort_order and is_final_tranche
     * @return list<array<string, mixed>> canonical plan rows, ordered
     *
     * @throws InvalidArgumentException on any T2 or T3 breach (Marathi, shown to the Suchak)
     */
    public function normalizePlan(array $input): array
    {
        if ($input === []) {
            return [];
        }

        $rows = [];
        $seenStages = [];
        $previousLadderIndex = -1;

        foreach (array_values($input) as $position => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException(__('suchak.tranche.row_incomplete'));
            }

            $stageKey = is_string($raw['trigger_stage_key'] ?? null) ? trim($raw['trigger_stage_key']) : '';

            if (! SuchakCollaborationStageEvent::isValidStage($stageKey)) {
                throw new InvalidArgumentException(__('suchak.tranche.invalid_stage'));
            }

            // §7.4's three releasing events, and nothing else. A rung outside the window either
            // settles on one Suchak's own tap (everything below the floor) or is the helper's
            // receipt for his own share (`share_settled`, above the cap) — neither is evidence
            // about a couple, and both used to be accepted here.
            if (! self::isReleasingStage($stageKey)) {
                throw new InvalidArgumentException(
                    __('suchak.tranche.stage_not_releasable', [
                        'stage' => SuchakCollaborationStageEvent::stageLabel($stageKey),
                        'stages' => $this->releasingStageLabels(),
                    ])
                );
            }

            if (isset($seenStages[$stageKey])) {
                throw new InvalidArgumentException(__('suchak.tranche.duplicate_stage'));
            }
            $seenStages[$stageKey] = true;

            // The ladder is the order money is earned in, so the plan must read the same way.
            // Out of order, "the first tranche" and "the final tranche" stop meaning anything
            // and T2 and T4 both lose their subject.
            $ladderIndex = SuchakCollaborationStageEvent::stageIndex($stageKey);
            if ($ladderIndex <= $previousLadderIndex) {
                throw new InvalidArgumentException(__('suchak.tranche.order_mismatch'));
            }
            $previousLadderIndex = $ladderIndex;

            $percent = $this->percent($raw['share_percent'] ?? null);

            $rows[] = [
                'sort_order' => ((int) $position + 1) * 10,
                'trigger_stage_key' => $stageKey,
                'share_percent' => $percent,
                'is_final_tranche' => (bool) ($raw['is_final_tranche'] ?? false),
            ];
        }

        $rows = $this->applyFinalTrancheRule($rows);
        $this->assertSharesSumTo100($rows);

        return $rows;
    }

    /**
     * T2 — the last tranche is the remainder, and only the last one.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function applyFinalTrancheRule(array $rows): array
    {
        $lastIndex = count($rows) - 1;
        $flagged = [];

        foreach ($rows as $index => $row) {
            if ($row['is_final_tranche'] === true) {
                $flagged[] = $index;
            }
        }

        if (count($flagged) > 1) {
            throw new InvalidArgumentException(__('suchak.tranche.one_remainder_only'));
        }

        if ($flagged !== [] && $flagged[0] !== $lastIndex) {
            throw new InvalidArgumentException(__('suchak.tranche.remainder_must_be_last'));
        }

        // Nobody flagged it: the blueprint leaves no choice about which row is the remainder,
        // so this is a reading of the plan, not a guess about the Suchak's intent.
        $rows[$lastIndex]['is_final_tranche'] = true;

        return $rows;
    }

    /**
     * T3 — the declared shares must sum to exactly 100%. Without this, 10/40/40 ships and 10%
     * of the fee belongs to nobody.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertSharesSumTo100(array $rows): void
    {
        // Summed in hundredths of a percent as integers: 0.1 + 0.2 in floats is not 0.3, and a
        // rule that says "exactly" cannot be enforced with a tolerance.
        $totalBasisPoints = 0;
        foreach ($rows as $row) {
            $totalBasisPoints += (int) round(((float) $row['share_percent']) * 100);
        }

        if ($totalBasisPoints !== 10000) {
            throw new InvalidArgumentException(
                __('suchak.tranche.percent_must_total_100', [
                    'percent' => $this->readablePercent($totalBasisPoints / 100),
                ])
            );
        }
    }

    /**
     * T1 + T2 — the rupee figure of each tranche.
     *
     * T1: every share is a percentage OF THE TOTAL, never of the running balance.
     * T2: the final tranche is whatever is left, so the parts sum to the whole to the paisa.
     *
     * Computed in paise as integers throughout, then handed back as decimal strings in the same
     * shape the money columns use. No amount is ever stored — this is the only place it exists.
     *
     * @param  iterable<int, SuchakSuccessFeeTranche|array<string, mixed>>  $tranches
     * @return list<string> aligned with the plan order
     */
    public function amounts(int|float|string|null $totalFeeAmount, iterable $tranches): array
    {
        $rows = $this->planRows($tranches);
        if ($rows === []) {
            return [];
        }

        $totalPaise = (int) round(((float) $totalFeeAmount) * 100);
        $lastIndex = count($rows) - 1;

        $paise = [];
        $allocated = 0;
        foreach ($rows as $index => $row) {
            if ($index === $lastIndex) {
                continue;
            }

            $share = (int) round($totalPaise * ((float) $row['share_percent']) / 100);
            $paise[$index] = $share;
            $allocated += $share;
        }

        $paise[$lastIndex] = $totalPaise - $allocated;
        ksort($paise);

        // T2 as a live assertion rather than a promise in a comment. If this ever trips, a
        // rupee has gone missing and no amount may be quoted to anyone.
        if (array_sum($paise) !== $totalPaise) {
            throw new InvalidArgumentException(__('suchak.tranche.sum_mismatch'));
        }

        return array_map(
            static fn (int $value): string => number_format($value / 100, 2, '.', ''),
            array_values($paise),
        );
    }

    /**
     * T4 — advisory, never a block.
     *
     * The blueprint says the first tranche "should" be the smallest, and a "should" that
     * refuses to save is a "must". Two honest plans breach it: an equal 50/50 split, and a plan
     * whose first trigger is genuinely the hardest evidence. Blocking those would push the
     * Suchak into a shape he did not agree to, which is worse than the risk T4 guards against.
     * So it is recorded, not enforced: returned for the screen to show and written to the log
     * so the pattern D25 worries about (a big prize on the softest evidence) is visible in
     * hindsight rather than invisible.
     *
     * @param  iterable<int, SuchakSuccessFeeTranche|array<string, mixed>>  $tranches
     * @return list<string> Marathi advisories, empty when the plan is well shaped
     */
    public function advisories(iterable $tranches): array
    {
        $rows = $this->planRows($tranches);
        if (count($rows) < 2) {
            return [];
        }

        $first = (float) $rows[0]['share_percent'];
        $smallest = min(array_map(static fn (array $row): float => (float) $row['share_percent'], $rows));

        if ($first > $smallest) {
            return [__('suchak.tranche.first_should_be_smallest')];
        }

        return [];
    }

    /**
     * The canonical fragment that goes inside agreement_snapshot_hash.
     *
     * Only plan facts. Release and settlement state is deliberately excluded: a tranche firing
     * on a match is not a change to the agreed terms, and hashing it would make every released
     * tranche read as "package changed" and freeze the agreement it just earned money under.
     *
     * @param  iterable<int, SuchakSuccessFeeTranche|array<string, mixed>>  $tranches
     * @return list<array<string, mixed>>
     */
    public function snapshotPayload(iterable $tranches): array
    {
        return array_map(static fn (array $row): array => [
            'sort_order' => (int) $row['sort_order'],
            'trigger_stage_key' => (string) $row['trigger_stage_key'],
            'share_percent' => number_format((float) $row['share_percent'], 2, '.', ''),
            'is_final_tranche' => (bool) $row['is_final_tranche'],
        ], $this->planRows($tranches));
    }

    /**
     * A split only means something against a fixed figure. `as_wished` and `none` have no total
     * to take a percentage of, and a NULL amount has no total at all.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function assertPackageCarriesFixedSuccessFee(SuchakServicePackage $package, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        if ($package->post_marriage_fee_mode !== SuchakCustomerPlan::MODE_FIXED
            || $package->post_marriage_fee_amount === null
            || (float) $package->post_marriage_fee_amount <= 0.0) {
            throw new InvalidArgumentException(__('suchak.tranche.no_success_fee'));
        }
    }

    /**
     * M9's guard rail, applied PER COMMITTED ROW rather than to the plan as a whole.
     *
     * The rule it enforces is §7.4's own sentence: *"The paid tranche stands. Only the UNPAID
     * tranches fire on the new match."* A tranche that has been released against a match — or
     * paid — is spent history: removing it, re-cutting its share or demoting it from the
     * remainder would reset what the family already owes and let the same rupee be charged twice.
     * A tranche that has NOT fired is still only a plan, and re-cutting the part of the schedule
     * that has not happened yet is exactly what a revision is for.
     *
     * WHY THIS IS NOW PER-ROW. The first version compared the two plans WHOLE and refused any
     * difference the moment ANY row was committed. That was harmless only because nothing in the
     * codebase could commit a row — `assertPlanChangeAllowed()` returned at the first `if` on
     * every call this system has ever made. {@see release()} ends that, and a whole-plan
     * comparison would then have started refusing every later revision, including the legitimate
     * ones: a family whose settlement broke after the first tranche could never re-shape the two
     * that had not happened, because the one that had would veto the whole document.
     *
     * The refusals name the rung in Marathi, because "the split may not be changed" leaves a
     * Suchak staring at a screen with three rows on it and no idea which one is the problem.
     *
     * @param  iterable<int, SuchakSuccessFeeTranche>  $existing
     * @param  list<array<string, mixed>>  $rows
     */
    public function assertPlanChangeAllowed(iterable $existing, array $rows): void
    {
        $proposed = [];
        foreach ($this->planRows($rows) as $row) {
            $proposed[(string) $row['trigger_stage_key']] = $row;
        }

        foreach ($existing as $tranche) {
            if (! $tranche->isCommitted()) {
                continue;
            }

            $stageKey = (string) $tranche->trigger_stage_key;
            $label = SuchakCollaborationStageEvent::stageLabel($stageKey);
            $replacement = $proposed[$stageKey] ?? null;

            if ($replacement === null) {
                throw new InvalidArgumentException(
                    __('suchak.tranche.released_cannot_remove', ['stage' => $label])
                );
            }

            $samePercent = number_format((float) $replacement['share_percent'], 2, '.', '')
                === number_format((float) $tranche->share_percent, 2, '.', '');
            $sameFinal = ((bool) $replacement['is_final_tranche']) === ((bool) $tranche->is_final_tranche);

            if (! $samePercent || ! $sameFinal) {
                throw new InvalidArgumentException(
                    __('suchak.tranche.released_cannot_recut', ['stage' => $label])
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    //  THE LEDGER — what an engagement's settled rungs have actually earned (M9, M10, §7.4)
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * THE DERIVED TRUTH, and the only place the release rules live.
     *
     * Reads three things and nothing else: the settled rungs of this engagement, the agreement
     * revision in force, and the frozen total through {@see amounts()}. Writes nothing. Called by
     * {@see release()} to decide what to record, and by the read door to answer honestly even if
     * the writer has never run — see the class docblock on why nothing here may depend on a job.
     *
     * WHAT RELEASES A TRANCHE. The rung named by `trigger_stage_key` must be SETTLED, and settled
     * is `SuchakCollaborationStageEvent::isSettled()` — the split that model already encodes:
     * `marriage_settled`, `engagement` and `marriage` (CONFIRMABLE_STAGES, D26) need
     * `confirmed_at`; every other rung settles on the claim. That predicate is not restated here.
     *
     * M10 — "A later stage releases every earlier unpaid tranche with it. A wedding held without a
     * साखरपुडा still owes the engagement tranche." Implemented as: each unreleased tranche looks
     * for the EARLIEST settled rung at or after its own trigger. A plan of
     * settled/engagement/marriage on an engagement whose only settled rung is the wedding
     * therefore releases all three, and each row records the wedding as what released it — so the
     * ledger says out loud that two of them fired on a rung that is not their own.
     *
     * WHY `released_at` IS THE RUNG'S INSTANT AND NOT `now()`. Run the writer after the लग्न ठरले
     * and again after the wedding, or only once at the very end: both produce identical rows. The
     * moment a tranche was earned is a property of the marriage, not of when someone pressed a
     * button — and M3 already refuses to let a suppressed claim buy anybody time.
     *
     * FIRST WRITER WINS, and that is §7.4's attribution rule. "If helper A's match produced the
     * settled tranche and helper B's match produced the wedding, attribution is recorded per
     * tranche — A's declared share applies to the tranche A's work released, B's to B's." So a
     * tranche already released keeps the engagement and the rung that released it, even when a
     * later engagement re-derives an entitlement to the same row. Re-attributing it would hand B
     * the fruit of A's work, which is the exact failure §7.4 names.
     *
     * @return array{
     *     agreement: SuchakCustomerAgreement,
     *     total_fee_amount: ?string,
     *     currency: string,
     *     terms_satisfied: bool,
     *     other_chain_commitments: array<string, int>,
     *     other_chain_committed_paise: int,
     *     family_allowance_paise: int,
     *     rows: list<array{
     *         tranche: SuchakSuccessFeeTranche,
     *         amount: ?string,
     *         stage_event: ?SuchakCollaborationStageEvent,
     *         released_at: ?Carbon,
     *         is_recorded: bool,
     *         blocked_reason: ?string
     *     }>
     * }
     */
    public function entitlement(SuchakCollaborationRequest $collaboration, bool $lock = false): array
    {
        $agreement = $this->ledgerAgreementFor($collaboration);
        $agreement->loadMissing('servicePackage');

        $query = SuchakSuccessFeeTranche::query()
            ->where('customer_agreement_id', $agreement->id)
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $tranches = $query->get();

        $package = $agreement->servicePackage;
        $total = $package?->post_marriage_fee_amount;
        $currency = (string) ($agreement->currency ?: ($package?->currency ?: 'INR'));
        $termsSatisfied = $agreement->isTermsSatisfied();
        $elsewhere = $this->committedStagesOnOtherChains($agreement);
        $elsewhereMoneyPaise = $this->committedMoneyOnOtherChains($agreement);

        // Ordered identically to $tranches: planRows() sorts by sort_order and so does the query.
        $amounts = $tranches->isEmpty() ? [] : $this->amounts($total, $tranches);

        $settledRungs = $this->settledReleasingRungs($collaboration);

        // M9 IN MONEY. This chain's own agreed total is the ceiling, and what the family already
        // owes on their other chains is spent out of it before this ledger sees a rupee. On the
        // ordinary customer — one plan, one chain — `$elsewhereMoneyPaise` is 0, the allowance is
        // the whole fee, and the shares sum to exactly 100% of it, so nothing here can bind.
        $allowancePaise = max(0, $this->paise($total) - $elsewhereMoneyPaise);

        // Already-released rows are spent first, in one pass before the loop rather than as it
        // goes: release order is not guaranteed to be plan order (a revision can leave a later
        // instalment released and an earlier one not), and a newly-releasing row must never eat
        // budget that a recorded one already holds.
        $claimedPaise = 0;
        foreach ($tranches->values()->all() as $index => $tranche) {
            if ($tranche->isReleased()) {
                $claimedPaise += $this->paise($amounts[$index] ?? null);
            }
        }

        $rows = [];
        foreach ($tranches->values()->all() as $index => $tranche) {
            $stageKey = (string) $tranche->trigger_stage_key;
            $amount = $amounts[$index] ?? null;

            if ($tranche->isReleased()) {
                $rows[] = [
                    'tranche' => $tranche,
                    'amount' => $amount,
                    'stage_event' => $tranche->releasedByStageEvent,
                    'released_at' => $tranche->released_at,
                    'is_recorded' => true,
                    'blocked_reason' => null,
                ];

                continue;
            }

            $blocked = null;
            $trigger = null;

            if (! $termsSatisfied) {
                // No tranche exists under terms nobody accepted.
                //
                // This is NOT a second copy of the ladder's own gate. `claimStage()` checks the
                // terms of the revision the ENGAGEMENT is bound to, at the moment the rung is
                // recorded. The ledger lives on the LATEST revision (see ledgerAgreementFor), and
                // a fresh revision starts at `pending` — so rungs lawfully recorded under an
                // accepted revision 1 would otherwise release money under a revision 2 the family
                // has not seen. Same predicate, different subject, and the gap between them is a
                // customer being charged under terms they never accepted.
                $blocked = __('suchak.tranche.blocked_terms_pending');
            } elseif (! self::isReleasingStage($stageKey)) {
                // Written before the plan door refused it — and it can never fire, so the ledger
                // has to SAY so. Silence here was the other half of the same defect: a tranche
                // planned above the cap sat in the plan looking pending forever, with no reason
                // attached and no screen able to explain it.
                $blocked = __('suchak.tranche.blocked_stage_never_releases', [
                    'stage' => SuchakCollaborationStageEvent::stageLabel($stageKey),
                    'stages' => $this->releasingStageLabels(),
                ]);
            } elseif (isset($elsewhere[$stageKey])) {
                // M9's narrower unit — one RUNG is owed once, see committedStagesOnOtherChains().
                $blocked = __('suchak.tranche.blocked_stage_already_charged', [
                    'stage' => SuchakCollaborationStageEvent::stageLabel($stageKey),
                ]);
            } elseif ($claimedPaise + $this->paise($amount) > $allowancePaise) {
                // M9's real unit — the family's MONEY. A tranche is atomic, so it either fits in
                // what is left of the one agreed figure or it does not fire at all; there is no
                // half instalment to release. Under-charging here is the safe direction, and the
                // reason is published in rupees so the Suchak can see the arithmetic.
                $blocked = __('suchak.tranche.blocked_family_allowance', [
                    'committed' => MoneyFormat::amount($elsewhereMoneyPaise / 100, $currency),
                    'total' => MoneyFormat::amount($total, $currency),
                    'remaining' => MoneyFormat::amount(max(0, $allowancePaise - $claimedPaise) / 100, $currency),
                    'amount' => MoneyFormat::amount($amount, $currency),
                ]);
            } else {
                $trigger = $this->firstRungAtOrAfter($settledRungs, $stageKey);

                if ($trigger !== null) {
                    $claimedPaise += $this->paise($amount);
                }
            }

            $rows[] = [
                'tranche' => $tranche,
                'amount' => $amount,
                'stage_event' => $trigger['event'] ?? null,
                'released_at' => $trigger['at'] ?? null,
                'is_recorded' => false,
                'blocked_reason' => $blocked,
            ];
        }

        return [
            'agreement' => $agreement,
            'total_fee_amount' => $total === null ? null : (string) $total,
            'currency' => $currency,
            'terms_satisfied' => $termsSatisfied,
            'other_chain_commitments' => $elsewhere,
            'other_chain_committed_paise' => $elsewhereMoneyPaise,
            'family_allowance_paise' => $allowancePaise,
            'rows' => $rows,
        ];
    }

    /**
     * THE WRITER — records the derivation above, and originates all three release columns.
     *
     * Idempotent by construction: it writes only rows whose stored state does not already match
     * the derivation, and the `whereNull('released_at')` on the update makes the first writer win
     * even when two callers race. Running it twice, or ten times, changes nothing after the first.
     *
     * @return array<string, mixed> the ledger payload, re-derived after the write
     */
    public function release(SuchakCollaborationRequest $collaboration): array
    {
        return DB::transaction(function () use ($collaboration): array {
            $entitlement = $this->entitlement($collaboration, lock: true);

            if (! $entitlement['terms_satisfied']) {
                throw new InvalidArgumentException(
                    __('suchak.tranche.blocked_no_tranche_until_accept')
                );
            }

            foreach ($entitlement['rows'] as $row) {
                if ($row['is_recorded'] || $row['released_at'] === null || $row['stage_event'] === null) {
                    continue;
                }

                SuchakSuccessFeeTranche::query()
                    ->whereKey($row['tranche']->id)
                    ->whereNull('released_at')
                    ->update([
                        'released_by_collaboration_request_id' => $collaboration->id,
                        'released_by_stage_event_id' => $row['stage_event']->id,
                        'released_at' => $row['released_at'],
                        'updated_at' => now(),
                    ]);
            }

            return $this->ledgerPayload($collaboration);
        });
    }

    /**
     * THE OTHER TWO COLUMNS — the family actually paid this tranche.
     *
     * Release and settlement are two different facts and §7.4 keeps them apart on purpose:
     * released means EARNED (a rung already happened, D25's whole answer to the refund question),
     * settled means PAID. M9 turns on the second — "tranches already PAID count toward it
     * whichever match triggered them" — and M10's cascade fires on every earlier UNPAID tranche.
     *
     * A tranche may not be settled before it is released. D25's argument is that every rupee is
     * taken for an event that has already happened; money accepted against a tranche that has not
     * fired is money taken for a future that may not arrive, which is the one thing this design
     * removes.
     *
     * ── A RECEIPT IS A BUDGET, AND THAT IS BOTH HALVES OF THE RULE ────────────────────────────
     *
     * Every other check here is about WHETHER this payment may touch this tranche. None of them
     * was about HOW MUCH, so a ₹1 receipt set `settled_at` on a ₹50,000 instalment and the ledger
     * then read ₹50,000 settled. `settled_at` is M9's own paid predicate AND M3's half A, so that
     * one rupee made the family read as paid and made the cross-Suchak share fall due at once.
     *
     * The opposite half was the rule written to stop it: "one payment, one tranche" refused a
     * receipt that was ALREADY bound to another row — which made M10's headline case unsettleable.
     * A wedding cascades three instalments, the family pays the whole ₹1,00,000 in one receipt,
     * and exactly one of the three could ever be marked paid; the other two stayed unsettled
     * forever, because no second receipt was ever going to arrive.
     *
     * Both are the same missing idea. `amount_received` is a budget: a tranche settles against it
     * only if its own amount still fits in what that receipt has left, and what it has left is its
     * total minus the tranches already bound to it. One receipt then settles exactly the cascade
     * it paid for, and nothing it did not.
     *
     * SCOPED TO THIS AGREEMENT REVISION, because `persistTranchePlan()` copies `customer_payment_id`
     * forward: an unscoped count of "rows bound to this payment" counts the same instalment once
     * per revision of the chain and would exhaust a receipt that had never been spent.
     */
    public function settle(SuchakSuccessFeeTranche $tranche, SuchakCustomerPayment $payment): SuchakSuccessFeeTranche
    {
        $tranche->refresh();
        $payment->refresh();

        if (! $tranche->isReleased()) {
            throw new InvalidArgumentException(__('suchak.tranche.settle_not_released'));
        }

        if ((int) $payment->customer_agreement_id !== (int) $tranche->customer_agreement_id) {
            throw new InvalidArgumentException(__('suchak.tranche.settle_payment_not_this_agreement'));
        }

        if ($payment->payment_status !== SuchakCustomerPayment::STATUS_PAID) {
            throw new InvalidArgumentException(__('suchak.tranche.settle_payment_incomplete'));
        }

        if ($tranche->isSettled()) {
            if ((int) $tranche->customer_payment_id !== (int) $payment->id) {
                throw new InvalidArgumentException(__('suchak.tranche.settle_already_bound'));
            }

            return $tranche;
        }

        return DB::transaction(function () use ($tranche, $payment): SuchakSuccessFeeTranche {
            [, $amountsById] = $this->pricedLedger((int) $tranche->customer_agreement_id, lock: true);

            $tranchePaise = $amountsById[(int) $tranche->id] ?? 0;
            $receiptPaise = $this->paise($payment->amount_received);

            $spentPaise = 0;
            $bound = SuchakSuccessFeeTranche::query()
                ->where('customer_agreement_id', $tranche->customer_agreement_id)
                ->where('customer_payment_id', $payment->id)
                ->whereKeyNot($tranche->id)
                ->pluck('id');

            foreach ($bound as $boundId) {
                $spentPaise += $amountsById[(int) $boundId] ?? 0;
            }

            $currency = (string) ($payment->currency ?: 'INR');
            $remainingPaise = $receiptPaise - $spentPaise;

            if ($tranchePaise > $remainingPaise) {
                throw new InvalidArgumentException(
                    __('suchak.tranche.settle_exceeds_receipt', [
                        'tranche' => MoneyFormat::amount($tranchePaise / 100, $currency),
                        'receipt' => MoneyFormat::amount($receiptPaise / 100, $currency),
                        'remaining' => MoneyFormat::amount(max(0, $remainingPaise) / 100, $currency),
                    ])
                );
            }

            // The instant the MONEY arrived, for the same reason `released_at` is the rung's instant.
            $paidAt = $payment->payment_received_at ?? $payment->updated_at ?? now();

            SuchakSuccessFeeTranche::query()
                ->whereKey($tranche->id)
                ->whereNull('settled_at')
                ->update([
                    'customer_payment_id' => $payment->id,
                    'settled_at' => $paidAt,
                    'updated_at' => now(),
                ]);

            return $tranche->fresh() ?? $tranche;
        });
    }


    /**
     * The ledger as a screen reads it. Amounts through MoneyFormat — the one money formatter, so
     * Latin digits with Indian grouping, identical to the acceptance page the family accepted on.
     *
     * `released_by_stage_key` is the row that makes M10 visible: when it differs from
     * `trigger_stage_key`, this tranche fired on somebody else's rung — a साखरपुडा instalment
     * released by the wedding, which is precisely the case the rule exists for.
     *
     * @return array<string, mixed>
     */
    public function ledgerPayload(SuchakCollaborationRequest $collaboration): array
    {
        $entitlement = $this->entitlement($collaboration);
        $agreement = $entitlement['agreement'];
        $currency = $entitlement['currency'];

        $releasedPaise = 0;
        $settledPaise = 0;
        $tranches = [];

        foreach ($entitlement['rows'] as $row) {
            /** @var SuchakSuccessFeeTranche $tranche */
            $tranche = $row['tranche'];
            $amount = $row['amount'];
            $paise = $amount === null ? 0 : (int) round(((float) $amount) * 100);
            $event = $row['stage_event'];
            $releasedAt = $row['released_at'];

            if ($releasedAt !== null) {
                $releasedPaise += $paise;
            }

            if ($tranche->isSettled()) {
                $settledPaise += $paise;
            }

            $tranches[] = [
                'tranche_id' => (int) $tranche->id,
                'sort_order' => (int) $tranche->sort_order,
                'trigger_stage_key' => (string) $tranche->trigger_stage_key,
                'trigger_stage_label' => SuchakCollaborationStageEvent::stageLabel((string) $tranche->trigger_stage_key),
                'share_percent' => $this->readablePercent((float) $tranche->share_percent),
                'is_final_tranche' => (bool) $tranche->is_final_tranche,
                'amount' => $amount,
                'amount_display' => $amount === null ? null : MoneyFormat::amount($amount, $currency),
                'is_released' => $releasedAt !== null,
                'released_at' => $releasedAt?->toIso8601String(),
                // False means: this row IS released by the ladder, and nobody has written it down
                // yet. The GET door is honest without the POST door ever having been called.
                'is_recorded' => (bool) $row['is_recorded'],
                'released_by_collaboration_request_id' => $event === null
                    ? null
                    : (int) ($tranche->released_by_collaboration_request_id ?? $collaboration->id),
                'released_by_stage_event_id' => $event === null ? null : (int) $event->id,
                'released_by_stage_key' => $event === null ? null : (string) $event->stage_key,
                'released_by_stage_label' => $event === null
                    ? null
                    : SuchakCollaborationStageEvent::stageLabel((string) $event->stage_key),
                // M10 in one boolean: this instalment fired on a rung that is not its own.
                'released_by_later_stage' => $event !== null
                    && (string) $event->stage_key !== (string) $tranche->trigger_stage_key,
                'is_settled' => $tranche->isSettled(),
                'settled_at' => $tranche->settled_at?->toIso8601String(),
                'customer_payment_id' => $tranche->customer_payment_id === null
                    ? null
                    : (int) $tranche->customer_payment_id,
                'blocked_reason' => $row['blocked_reason'],
            ];
        }

        $outstandingPaise = $releasedPaise - $settledPaise;

        return [
            'collaboration_request_id' => (int) $collaboration->id,
            'customer_agreement_id' => (int) $agreement->id,
            'agreement_revision' => (int) $agreement->agreement_revision,
            'terms_satisfied' => (bool) $entitlement['terms_satisfied'],
            'currency' => $currency,
            'success_fee_amount' => $entitlement['total_fee_amount'],
            'success_fee_display' => MoneyFormat::amount($entitlement['total_fee_amount'], $currency),
            'released_total_display' => MoneyFormat::amount($releasedPaise / 100, $currency),
            'settled_total_display' => MoneyFormat::amount($settledPaise / 100, $currency),
            'outstanding_total_display' => MoneyFormat::amount($outstandingPaise / 100, $currency),
            'first_releasing_stage_key' => self::FIRST_RELEASING_STAGE,
            'last_releasing_stage_key' => self::LAST_RELEASING_STAGE,
            // M9 in money, published rather than hidden — the family's ceiling, what their other
            // plans have already taken out of it, and what is therefore left on this chain. All
            // three read ₹0 / the whole fee on every customer who was sent one plan.
            'other_chain_committed_display' => MoneyFormat::amount(
                $entitlement['other_chain_committed_paise'] / 100,
                $currency,
            ),
            'family_allowance_display' => MoneyFormat::amount(
                $entitlement['family_allowance_paise'] / 100,
                $currency,
            ),
            'other_chain_commitments' => array_map(
                static fn (string $stageKey, int $agreementId): array => [
                    'trigger_stage_key' => $stageKey,
                    'trigger_stage_label' => SuchakCollaborationStageEvent::stageLabel($stageKey),
                    'customer_agreement_id' => $agreementId,
                ],
                array_keys($entitlement['other_chain_commitments']),
                array_values($entitlement['other_chain_commitments']),
            ),
            'tranches' => $tranches,
        ];
    }

    /**
     * M9 AT THE CUSTOMER LEVEL — "the success fee is paid once per customer, IN TOTAL".
     *
     * The ledger hangs off `customer_agreement_id`, and an agreement chain is keyed by
     * `service_package_id`. `suchak_service_packages.customer_context_id` has an index and NO
     * unique, and payment-setup matches an existing package by `package_name` — so sending
     * "Basic" and later "Premium" to one family builds two packages, two agreement chains and two
     * independent tranche ledgers, EACH carrying a full 100% of a success fee. The refusal text on
     * that endpoint even instructs the Suchak to do it ("वेगळ्या नावाची योजना तयार करून पाठवा"),
     * and correctly: an accepted agreement must not be silently re-cut. Nothing about that is a
     * schema mistake — but it left M9 with no expression at the customer-context level whatsoever.
     *
     * This is that expression, applied where money actually moves: a trigger rung already
     * committed on ANOTHER package chain of the SAME customer context is not released a second
     * time here. The family owes लग्न ठरले once, whichever plan the Suchak sent.
     *
     * TWO UNITS, BOTH NEEDED, AND THE SECOND ONE IS M9's.
     *
     * The stage key was the only unit here, and against disjoint plans it guards NOTHING. Basic
     * [लग्न ठरले 10%, साखरपुडा 90%] and Premium [विवाह 100%], each quoting ₹1,00,000, share no
     * trigger at all — so both chains released in full and one family was charged ₹2,00,000. M9
     * does not say "each rung is charged once", it says *the success fee is paid once per
     * customer, IN TOTAL*. The unit is the FAMILY'S MONEY.
     *
     * So {@see committedMoneyOnOtherChains()} is the rule, and the stage map stays beside it as a
     * narrower one: even inside the family's total, one RUNG is owed once — §7.4 attributes per
     * tranche, and charging लग्न ठरले twice for the same family under two package names is wrong
     * however the arithmetic lands.
     *
     * WHAT THE MONEY IS COUNTED AGAINST, and why it is not a new owner: THIS chain's own agreed
     * total, less what the family has already committed on every other chain. The figure is the
     * one on the agreement in force for this ledger — the number the family accepted — so nothing
     * new has to be invented to hold it.
     *
     * WHAT IS STILL MISSING, stated exactly rather than half-guarded: there is no owner of
     * "this customer's success fee". Two chains may quote two DIFFERENT totals (₹1,00,000 on
     * Basic, ₹1,50,000 on Premium), and then the family's ceiling depends on which chain is
     * asked — each chain caps itself at its own figure minus the rest, so the family can reach
     * the LARGER of the two but never the sum. Making the two figures one figure needs a
     * `customer_context`-level success fee that nothing in the schema carries today, and inventing
     * one silently here would be inventing a fact. Cross-SUCHAK is deliberately out of scope:
     * `customer_context_id` is per Suchak account and M1 says each customer pays only their own.
     *
     * @return array<string, int> trigger stage key => the agreement that already committed it
     */
    public function committedStagesOnOtherChains(SuchakCustomerAgreement $agreement): array
    {
        $committed = [];

        foreach ($this->siblingLedgerAgreements($agreement) as $sibling) {
            foreach ($this->committedTranchesOf($sibling) as [$tranche]) {
                $stageKey = (string) $tranche->trigger_stage_key;
                $committed[$stageKey] ??= (int) $sibling->id;
            }
        }

        return $committed;
    }

    /**
     * M9's unit — every paisa of a success fee this FAMILY has already been committed to on some
     * OTHER package chain of the same customer context.
     *
     * Priced through {@see amounts()}, the one arithmetic owner, against each sibling chain's own
     * frozen total. Nothing is stored: the rupee figure of a tranche exists in exactly one place.
     *
     * @return int paise
     */
    public function committedMoneyOnOtherChains(SuchakCustomerAgreement $agreement): int
    {
        $paise = 0;

        foreach ($this->siblingLedgerAgreements($agreement) as $sibling) {
            foreach ($this->committedTranchesOf($sibling) as [, $amountPaise]) {
                $paise += $amountPaise;
            }
        }

        return $paise;
    }

    /**
     * The LIVE ledger row of every OTHER package chain this family was sent.
     *
     * LATEST REVISION PER CHAIN, and that is load-bearing for the money unit in a way it never was
     * for the stage map. `persistTranchePlan()` COPIES release and settlement state forward onto
     * each new revision, so a committed tranche exists once per revision of its chain. A set of
     * stage keys absorbs the duplicates silently; a SUM does not — counting every revision would
     * report a family as having committed the same rupee twice, three times, once per package
     * edit, and would then refuse instalments they had never been charged for.
     *
     * "Latest" is resolved as {@see ledgerAgreementFor()} resolves it: highest `agreement_revision`
     * on a `service_package_id`. One definition, two callers.
     *
     * @return list<SuchakCustomerAgreement>
     */
    private function siblingLedgerAgreements(SuchakCustomerAgreement $agreement): array
    {
        if ($agreement->customer_context_id === null) {
            return [];
        }

        $rows = SuchakCustomerAgreement::query()
            ->where('customer_context_id', $agreement->customer_context_id)
            ->where('service_package_id', '!=', $agreement->service_package_id)
            ->with('servicePackage')
            ->orderByDesc('agreement_revision')
            ->orderByDesc('id')
            ->get();

        $latest = [];
        foreach ($rows as $row) {
            $latest[(int) $row->service_package_id] ??= $row;
        }

        return array_values($latest);
    }

    /**
     * The committed rows of one agreement, each with the rupee figure it committed, in paise.
     *
     * @return list<array{0: SuchakSuccessFeeTranche, 1: int}>
     */
    private function committedTranchesOf(SuchakCustomerAgreement $agreement): array
    {
        [$tranches, $paise] = $this->pricedLedger((int) $agreement->id);

        $committed = [];
        foreach ($tranches as $tranche) {
            if ($tranche->isCommitted()) {
                $committed[] = [$tranche, $paise[(int) $tranche->id] ?? 0];
            }
        }

        return $committed;
    }

    /**
     * One agreement's tranche rows AND their rupee figures, in one place, through {@see amounts()}.
     *
     * Every caller that needs a tranche's amount comes through here — the M9 family cap, the
     * settlement budget — so there is exactly one rounding and exactly one ordering assumption
     * (`sort_order`, then `id`, matching what `planRows()` sorts by).
     *
     * @return array{0: list<SuchakSuccessFeeTranche>, 1: array<int, int>} rows, then id => paise
     */
    private function pricedLedger(int $agreementId, bool $lock = false): array
    {
        /** @var SuchakCustomerAgreement $agreement */
        $agreement = SuchakCustomerAgreement::query()->with('servicePackage')->findOrFail($agreementId);

        $query = SuchakSuccessFeeTranche::query()
            ->where('customer_agreement_id', $agreementId)
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $tranches = $query->get()->values()->all();
        if ($tranches === []) {
            return [[], []];
        }

        $amounts = $this->amounts($agreement->servicePackage?->post_marriage_fee_amount, $tranches);

        $paise = [];
        foreach ($tranches as $index => $tranche) {
            $paise[(int) $tranche->id] = $this->paise($amounts[$index] ?? null);
        }

        return [$tranches, $paise];
    }

    private function paise(int|float|string|null $amount): int
    {
        return $amount === null ? 0 : (int) round(((float) $amount) * 100);
    }

    /**
     * The agreement revision whose tranche rows are the LIVE ledger for this engagement.
     *
     * The engagement is bound write-once to the revision in force when it was created
     * (`suchak_commission_agreements.customer_agreement_id`, blueprint 6.1). Revisions after that
     * carry the ledger forward row by row, so the rows that must be written are the LATEST
     * revision's — releasing onto the bound one would write to a ledger nothing reads.
     *
     * "Latest" is resolved exactly as `SuchakAgreementService` already resolves it for
     * supersession and for payment requests: highest `agreement_revision` on the same
     * `service_package_id`. One definition, three callers.
     */
    public function ledgerAgreementFor(SuchakCollaborationRequest $collaboration): SuchakCustomerAgreement
    {
        $collaboration->loadMissing('commissionAgreement');
        $boundId = $collaboration->commissionAgreement?->customer_agreement_id;

        if ($boundId === null) {
            throw new InvalidArgumentException(
                __('suchak.tranche.no_agreement_linked')
            );
        }

        /** @var SuchakCustomerAgreement $bound */
        $bound = SuchakCustomerAgreement::query()->findOrFail($boundId);

        /** @var SuchakCustomerAgreement|null $latest */
        $latest = SuchakCustomerAgreement::query()
            ->where('service_package_id', $bound->service_package_id)
            ->orderByDesc('agreement_revision')
            ->orderByDesc('id')
            ->first();

        return $latest ?? $bound;
    }

    /**
     * This engagement's SETTLED rungs, in ladder order, inside the releasing WINDOW —
     * {@see FIRST_RELEASING_STAGE} through {@see LAST_RELEASING_STAGE}.
     *
     * The cap alone was not a guard. Everything below the floor settles on ONE Suchak's claim
     * (`isSettled()` requires `confirmed_at` only for the three CONFIRMABLE_STAGES), so with no
     * floor an `interested` or `meeting_scheduled` row was a settled rung that M10's cascade would
     * then carry onto every tranche at or below it. The window is the guard; the cap is half of it.
     *
     * `isSettled()` is still the stage model's own predicate and is deliberately not restated.
     *
     * @return list<array{event: SuchakCollaborationStageEvent, index: int, at: Carbon}>
     */
    private function settledReleasingRungs(SuchakCollaborationRequest $collaboration): array
    {
        $settled = [];
        $events = SuchakCollaborationStageEvent::query()
            ->where('collaboration_request_id', $collaboration->id)
            ->get();

        foreach ($events as $event) {
            $stageKey = (string) $event->stage_key;
            if (! self::isReleasingStage($stageKey) || ! $event->isSettled()) {
                continue;
            }

            $index = SuchakCollaborationStageEvent::stageIndex($stageKey);

            $at = SuchakCollaborationStageEvent::requiresConfirmation($stageKey)
                ? $event->confirmed_at
                : $event->claimed_at;

            if (! $at instanceof Carbon) {
                continue;
            }

            $settled[] = ['event' => $event, 'index' => $index, 'at' => $at];
        }

        usort($settled, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        return array_values($settled);
    }

    /**
     * M10's cascade, in one lookup: the EARLIEST settled rung at or after this tranche's trigger.
     *
     * Its own rung when that rung settled; otherwise the first later one that did — which is what
     * "a wedding held without a साखरपुडा still owes the engagement tranche" means in code.
     *
     * @param  list<array{event: SuchakCollaborationStageEvent, index: int, at: Carbon}>  $settledRungs
     * @return array{event: SuchakCollaborationStageEvent, index: int, at: Carbon}|null
     */
    private function firstRungAtOrAfter(array $settledRungs, string $triggerStageKey): ?array
    {
        $triggerIndex = SuchakCollaborationStageEvent::stageIndex($triggerStageKey);

        foreach ($settledRungs as $rung) {
            if ($rung['index'] >= $triggerIndex) {
                return $rung;
            }
        }

        return null;
    }

    /**
     * Log the T4 advisory once, where the plan is actually persisted.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function logAdvisories(array $rows, int $agreementId): void
    {
        foreach ($this->advisories($rows) as $advisory) {
            Log::warning('Suchak success-fee tranche advisory (blueprint 7.4 T4).', [
                'customer_agreement_id' => $agreementId,
                'advisory' => $advisory,
                'shares' => array_map(static fn (array $row): string => (string) $row['share_percent'], $rows),
            ]);
        }
    }

    /**
     * @param  iterable<int, SuchakSuccessFeeTranche|array<string, mixed>>  $tranches
     * @return list<array<string, mixed>>
     */
    private function planRows(iterable $tranches): array
    {
        $rows = [];
        foreach ($tranches as $tranche) {
            $rows[] = $tranche instanceof SuchakSuccessFeeTranche
                ? [
                    'sort_order' => (int) $tranche->sort_order,
                    'trigger_stage_key' => (string) $tranche->trigger_stage_key,
                    'share_percent' => (string) $tranche->share_percent,
                    'is_final_tranche' => (bool) $tranche->is_final_tranche,
                ]
                : $tranche;
        }

        usort($rows, static fn (array $a, array $b): int => (int) $a['sort_order'] <=> (int) $b['sort_order']);

        return array_values($rows);
    }

    private function percent(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException(__('suchak.tranche.percent_required'));
        }

        $percent = (float) $value;
        if ($percent <= 0.0 || $percent > 100.0) {
            throw new InvalidArgumentException(__('suchak.tranche.percent_range'));
        }

        return number_format($percent, 2, '.', '');
    }

    /**
     * Latin digits, and no trailing ".00" on a whole percentage — the frozen digit rule, and
     * "90%" is what the Suchak typed, not "90.00%".
     */
    private function readablePercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
    }
}
