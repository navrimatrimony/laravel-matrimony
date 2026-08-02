<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakMarketplaceChallenge;
use App\Models\SuchakMarriageOutcome;
use App\Models\SuchakVisitConfirmation;
use App\Support\PercentDisplay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * WHAT ONE SUCHAK'S RECORD ACTUALLY SAYS — blueprint §11 phase 5, "behavioural readers".
 *
 * The audience is another Suchak deciding whether to open his own customer to this one, or whether
 * to answer his challenge. §9's visibility matrix admits Suchak reputation to everybody, which is
 * what makes it a DISCLOSURE and not merely a dashboard — see "what is not in this payload" below.
 *
 * ── THIS IS A READ. IT STORES NOTHING ────────────────────────────────────────────────────────
 *
 * There is no score column, no counter table and no nightly rollup. Every figure below is derived
 * on the read from rows that already exist, for the reason §5.5 gives outright: *"Add a READER over
 * existing event tables, not a new event log."* A stored score is stale the instant a meeting is
 * confirmed, a claim is answered or a share is settled, and under the frozen no-duplicate rule it
 * would be a second home for a fact the events already carry. `SuchakQualityControlService` is NOT
 * extended for the same reason in reverse — §12 forbids a second 0-100 score, and that one is an
 * admin RESTRICTION risk score, a genuinely different fact that must keep a different name.
 *
 * ── WHAT IS BOUND RATHER THAN RECOMPUTED ─────────────────────────────────────────────────────
 *
 *   `declared_share`     {@see SuchakCrossSuchakObligationService::declarerRatio()} — A7's
 *                        realized-vs-declared, verbatim. It already carries D13's `is_new`, the
 *                        per-currency split and the derive-then-record discipline. A second
 *                        computation of it here would be a second answer to the one number A7
 *                        exists to publish.
 *   `unanswered_claims`  {@see SuchakClaimSilenceService::unansweredClaimSummaryAfterSweep()} —
 *                        §7.2 clause 2's raw counter, verbatim, SWEPT FIRST. The swept variant is
 *                        the one every other cross-Suchak surface uses
 *                        (`SuchakMarketplaceChallengeService::openListing()` / `published()`),
 *                        and its own docblock states why: a card that says "0 unanswered" while
 *                        clause 3's gate refuses the helper two seconds later is worse than no
 *                        card at all.
 *
 * ── THE THREE RULES THIS READ OBEYS ──────────────────────────────────────────────────────────
 *
 * 1. D13 — A NEWCOMER IS NEW, NEVER 0%. {@see self::MIN_RATE_DENOMINATOR} and
 *    {@see self::rate()} mean no proportion is ever computed over an empty denominator: it comes
 *    back `null` with `suppressed_reason = no_events`, and `is_new` is true across the whole card
 *    when this account has recorded nothing anywhere. Zero out of zero rendered as 0% is a
 *    defamation with arithmetic behind it, and a Suchak who cannot be employed on day one cannot
 *    reach day two.
 *
 * 2. SMALL DENOMINATORS LIE. One dispute out of one meeting is not a 100% dispute rate. So every
 *    proportion travels WITH its numerator and denominator, and is withheld entirely below five
 *    events (`suppressed_reason = too_few_events`) — the COUNTS still ship from event one. Five is
 *    chosen, not inherited: below it a single event moves the rate by twenty points or more, which
 *    is larger than any real difference between two Suchaks the number could be used to tell apart,
 *    so the figure would report noise as character. It is five rather than ten because a marketplace
 *    event is a year of somebody's work — a threshold of ten would leave almost every genuine
 *    Suchak permanently rate-less, which is D13's unemployability arriving one step later. The
 *    blueprint already chose the same trade in §7.2 clause 2: *"a raw count from the first event
 *    beats a ratio that needs volume to move."*
 *
 * 3. IT IS A DISCLOSURE, SO IT NAMES NOBODY. Not one key below identifies a candidate, a family, a
 *    village or a customer. There is no location dimension of any kind — *"3 marriages in
 *    Lakhandur"* identifies people, and a district is not much better in a taluka-sized market.
 *    There are no per-event dates and no free-text notes (a `schedule_note` or an `event_note` is
 *    one Suchak's own words and routinely names the family). No `matrimony_profile_id`,
 *    `representation_id`, `customer_context_id`, `collaboration_request_id` or `challenge_id`
 *    appears: this payload holds counts, sums and ages about ONE Suchak, plus his own name and
 *    badge — the same three publisher keys `SuchakMarketplaceChallengeService::listingPayload()`
 *    already publishes to the same audience. `SuchakCandidateMaskingService` is deliberately NOT
 *    called: it is the one cross-Suchak presenter for a CANDIDATE, and this read presents no
 *    candidate for it to mask. The one age published, `oldest_awaiting_days`, is about a claim this
 *    Suchak himself made and is the shape §7.2 clause 2 mandates ("oldest 91 days").
 */
class SuchakReputationService
{
    /**
     * Below this many events a proportion is withheld and only the raw counts are published. See
     * rule 2 in the class docblock for why it is five.
     */
    public const MIN_RATE_DENOMINATOR = 5;

    public const SUPPRESSED_NO_EVENTS = 'no_events';

    public const SUPPRESSED_TOO_FEW_EVENTS = 'too_few_events';

    public function __construct(
        private readonly SuchakCrossSuchakObligationService $obligationService,
        private readonly SuchakClaimSilenceService $claimSilenceService,
    ) {
    }

    /**
     * One Suchak's whole behavioural record.
     *
     * @return array<string, mixed>
     */
    public function record(SuchakAccount $account, ?Carbon $at = null): array
    {
        $at ??= now();
        $accountId = (int) $account->id;

        $arranged = $this->meetingTotals(
            SuchakVisitConfirmation::query()->where('suchak_account_id', $accountId),
        );
        $asHelper = $this->meetingTotals(
            SuchakVisitConfirmation::query()->where('helper_suchak_account_id', $accountId),
        );
        $terminalClaims = $this->terminalClaims($accountId, $at);
        $engagements = $this->engagements($accountId);
        $marriages = $this->marriages($engagements['ids']);
        $challenges = $this->challenges($accountId);

        $declaredShare = $this->obligationService->declarerRatio($accountId, $at);
        $unansweredClaims = $this->claimSilenceService->unansweredClaimSummaryAfterSweep($account, $at);

        // D13's denominator, and the ONLY thing that decides the NEW badge. Every measure on the
        // card is counted, so a Suchak who has done anything at all stops being "new" — and one who
        // has genuinely done nothing is never described by a percentage.
        $recordedEvents = $arranged['total']
            + $asHelper['total']
            + $terminalClaims['claimed']
            + $engagements['total']
            + $marriages['credited']
            + $challenges['published']
            + (int) $declaredShare['declared_obligation_count'];

        return [
            'suchak_account_id' => $accountId,
            // The three publisher keys the marketplace listing already publishes to this audience.
            // A reputation card with no name on it is a card nobody can act on, and a Suchak's own
            // name is not a candidate's.
            'suchak_name' => $account->suchak_name,
            'is_verified' => $account->isVerified(),
            // D13 — never "0 marriages", never "0%".
            'is_new' => $recordedEvents === 0,
            'recorded_event_count' => $recordedEvents,
            // Published so a screen can explain a null proportion instead of printing a dash.
            'rate_threshold' => self::MIN_RATE_DENOMINATOR,
            'declared_share' => $declaredShare,
            'unanswered_claims' => $unansweredClaims,
            'meetings_arranged' => $this->meetingsPayload($arranged),
            'meetings_as_helper' => $this->meetingsPayload($asHelper),
            'terminal_claims' => $terminalClaims['payload'],
            'engagements' => $engagements['payload'],
            'marriages' => $marriages['payload'],
            'challenges' => $challenges['payload'],
        ];
    }

    // ── meetings ─────────────────────────────────────────────────────────────────────────────

    /**
     * The meeting engine's own columns, counted in one pass.
     *
     * WHICH COLUMN ANSWERS WHICH QUESTION, because two of them look interchangeable and are not:
     *
     *  - `suchak_completion_status` is THE CLAIM. It is what the Suchak asserted happened, and it
     *    is therefore the only honest denominator for "when this Suchak says a meeting took place,
     *    how often does the family agree?". Total meetings arranged is the wrong denominator:
     *    a meeting scheduled for next Tuesday has not been claimed and cannot yet be confirmed, so
     *    counting it would drag every active Suchak's confirmation rate down for working.
     *  - `user_confirmation_status` is THE FAMILY'S ANSWER, and nothing else writes it. An admin
     *    deciding a dispute deliberately does not stamp it ({@see SuchakVisitConfirmation::
     *    isComplaintDismissedByReview()}), so this count is the family's word alone.
     *  - `dispute_id` is WHETHER A CASE EXISTS, from either direction and including the one §7.2's
     *    silence sweep opens. It is one column, so "disputed" has one meaning here — reading
     *    `visit_status = disputed` beside it would count some rows twice and miss the ones whose
     *    case has since closed, because `dispute_id` is a permanent trail marker and the status is
     *    not.
     *
     * @param  Builder<SuchakVisitConfirmation>  $query
     * @return array<string, int>
     */
    private function meetingTotals(Builder $query): array
    {
        $row = (array) $query
            ->selectRaw('COUNT(*) as total')
            ->selectRaw($this->countIf("visit_status = ?", 'scheduled_open'), [SuchakVisitConfirmation::STATUS_SCHEDULED])
            ->selectRaw($this->countIf("visit_status = ?", 'cancelled'), [SuchakVisitConfirmation::STATUS_CANCELLED])
            ->selectRaw($this->countIf("visit_status = ?", 'payout_qualified'), [SuchakVisitConfirmation::STATUS_PAYOUT_QUALIFIED])
            ->selectRaw($this->countIf('suchak_completion_status = ?', 'claims_made'), [SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED])
            ->selectRaw($this->countIf('user_confirmation_status = ?', 'confirmed_by_customer'), [SuchakVisitConfirmation::CONFIRMATION_CONFIRMED])
            ->selectRaw($this->countIf('user_confirmation_status = ?', 'refused_by_customer'), [SuchakVisitConfirmation::CONFIRMATION_DISPUTED])
            ->selectRaw($this->countIf('dispute_id is not null', 'disputed'))
            ->selectRaw($this->countIf('meeting_sequence > 1', 'repeat_meetings'))
            ->selectRaw($this->countIf('meeting_mode = ?', 'online'), [SuchakVisitConfirmation::MODE_ONLINE])
            ->toBase()
            ->first();

        $totals = [];
        foreach ([
            'total', 'scheduled_open', 'cancelled', 'payout_qualified', 'claims_made',
            'confirmed_by_customer', 'refused_by_customer', 'disputed', 'repeat_meetings', 'online',
        ] as $key) {
            $totals[$key] = (int) ($row[$key] ?? 0);
        }

        // Derived rather than a tenth CASE: a claim the family has neither answered nor contested.
        $totals['awaiting_customer'] = max(
            0,
            $totals['claims_made'] - $totals['confirmed_by_customer'] - $totals['refused_by_customer'] - $totals['disputed'],
        );
        $totals['offline'] = max(0, $totals['total'] - $totals['online']);

        return $totals;
    }

    /**
     * @param  array<string, int>  $totals
     * @return array<string, mixed>
     */
    private function meetingsPayload(array $totals): array
    {
        return [
            'total' => $totals['total'],
            'scheduled_open' => $totals['scheduled_open'],
            'claims_made' => $totals['claims_made'],
            'confirmed_by_customer' => $totals['confirmed_by_customer'],
            'refused_by_customer' => $totals['refused_by_customer'],
            'awaiting_customer' => $totals['awaiting_customer'],
            'disputed' => $totals['disputed'],
            'cancelled' => $totals['cancelled'],
            'payout_qualified' => $totals['payout_qualified'],
            'repeat_meetings' => $totals['repeat_meetings'],
            'offline' => $totals['offline'],
            'online' => $totals['online'],
            // "When he says a meeting happened, how often does the family say so too?"
            'confirmed_rate' => $this->rate($totals['confirmed_by_customer'], $totals['claims_made']),
            // "How often does a claim of his end in a case?" One dispute out of one claim is not
            // 100% and is not published as one.
            'disputed_rate' => $this->rate($totals['disputed'], $totals['claims_made']),
            // "How often does a meeting he arranged never happen?" Denominator is every meeting
            // arranged, because a cancellation can only ever be one of those.
            'cancelled_rate' => $this->rate($totals['cancelled'], $totals['total']),
        ];
    }

    // ── the three terminal rungs (D26) ───────────────────────────────────────────────────────

    /**
     * Stage claims this Suchak raised that the family confirmed, versus the ones nobody answered.
     *
     * Only `CONFIRMABLE_STAGES` — the three terminal rungs D26 names. Every other rung on the
     * ladder is settled by the claim itself ({@see SuchakCollaborationStageEvent::isSettled()}), so
     * an "unconfirmed" count over them would be counting rungs that were never waiting for anybody.
     *
     * @return array{claimed: int, payload: array<string, mixed>}
     */
    private function terminalClaims(int $accountId, Carbon $at): array
    {
        /** @var list<SuchakCollaborationStageEvent> $rows */
        $rows = SuchakCollaborationStageEvent::query()
            ->where('claimed_by_suchak_account_id', $accountId)
            ->whereIn('stage_key', SuchakCollaborationStageEvent::CONFIRMABLE_STAGES)
            ->get(['id', 'stage_key', 'claimed_at', 'confirmed_at'])
            ->all();

        $claimed = 0;
        $confirmed = 0;
        $awaiting = 0;
        $oldestAwaitingDays = null;

        // Seeded from the ladder's own vocabulary and in its own order, so a rung with no claims
        // reads as `0` rather than vanishing — a missing row on a screen is indistinguishable from
        // a rung the client forgot to render.
        $byStage = [];
        foreach (SuchakCollaborationStageEvent::CONFIRMABLE_STAGES as $stageKey) {
            $byStage[$stageKey] = ['claimed' => 0, 'confirmed' => 0, 'awaiting' => 0];
        }

        foreach ($rows as $row) {
            $stageKey = (string) $row->stage_key;
            $claimed++;
            $byStage[$stageKey]['claimed']++;

            if ($row->confirmed_at !== null) {
                $confirmed++;
                $byStage[$stageKey]['confirmed']++;

                continue;
            }

            $awaiting++;
            $byStage[$stageKey]['awaiting']++;

            $days = $row->claimed_at === null ? null : (int) abs($row->claimed_at->diffInDays($at));
            if ($days !== null && ($oldestAwaitingDays === null || $days > $oldestAwaitingDays)) {
                $oldestAwaitingDays = $days;
            }
        }

        $stages = [];
        foreach ($byStage as $stageKey => $counts) {
            $stages[] = [
                'stage_key' => $stageKey,
                'stage_label' => SuchakCollaborationStageEvent::stageLabel((string) $stageKey),
            ] + $counts;
        }

        return [
            'claimed' => $claimed,
            'payload' => [
                'claimed' => $claimed,
                'confirmed_by_customer' => $confirmed,
                'awaiting_customer' => $awaiting,
                // A raw age, exactly as §7.2 clause 2 asks of the other counter. It is about a
                // claim THIS Suchak made and names nothing about the family that owes the answer.
                'oldest_awaiting_days' => $oldestAwaitingDays,
                'confirmed_rate' => $this->rate($confirmed, $claimed),
                'by_stage' => $stages,
            ],
        ];
    }

    // ── engagements ──────────────────────────────────────────────────────────────────────────

    /**
     * Engagements entered, and how they ended.
     *
     * THE ROLE IS THE ENGAGEMENT'S, AND ONLY WHEN IT WAS RECORDED. `customer_owner_side` DEFAULTS
     * to `target` in the database and is written only by `linkCustomerAgreement()`, so reading it
     * on an unlinked engagement would publish the column default as a finding and tell the wrong
     * Suchak he owned the customer. An engagement whose commission agreement names no customer
     * agreement revision is therefore counted under `role_unrecorded` rather than guessed at.
     *
     * @return array{total: int, ids: list<int>, payload: array<string, mixed>}
     */
    private function engagements(int $accountId): array
    {
        /** @var list<SuchakCollaborationRequest> $rows */
        $rows = SuchakCollaborationRequest::query()
            ->where(function (Builder $participant) use ($accountId): void {
                $participant
                    ->where('requesting_suchak_account_id', $accountId)
                    ->orWhere('target_suchak_account_id', $accountId);
            })
            ->with('commissionAgreement')
            ->get([
                'id',
                'requesting_suchak_account_id',
                'target_suchak_account_id',
                'marketplace_challenge_id',
                'customer_owner_side',
                'status',
            ])
            ->all();

        $ids = [];
        $byStatus = array_fill_keys(SuchakCollaborationRequest::STATUSES, 0);
        $fromMarketplace = 0;
        $asCustomerOwner = 0;
        $asHelper = 0;
        $roleUnrecorded = 0;

        foreach ($rows as $row) {
            $ids[] = (int) $row->id;
            $status = (string) $row->status;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            if ($row->marketplace_challenge_id !== null) {
                $fromMarketplace++;
            }

            if ($row->commissionAgreement?->customer_agreement_id === null) {
                $roleUnrecorded++;

                continue;
            }

            $row->isCustomerOwner($accountId) ? $asCustomerOwner++ : $asHelper++;
        }

        $decided = ($byStatus[SuchakCollaborationRequest::STATUS_ACCEPTED] ?? 0)
            + ($byStatus[SuchakCollaborationRequest::STATUS_REJECTED] ?? 0)
            + ($byStatus[SuchakCollaborationRequest::STATUS_EXPIRED] ?? 0)
            + ($byStatus[SuchakCollaborationRequest::STATUS_CANCELLED] ?? 0);

        return [
            'total' => count($rows),
            'ids' => $ids,
            'payload' => [
                'total' => count($rows),
                'pending' => $byStatus[SuchakCollaborationRequest::STATUS_PENDING] ?? 0,
                'accepted' => $byStatus[SuchakCollaborationRequest::STATUS_ACCEPTED] ?? 0,
                'rejected' => $byStatus[SuchakCollaborationRequest::STATUS_REJECTED] ?? 0,
                'expired' => $byStatus[SuchakCollaborationRequest::STATUS_EXPIRED] ?? 0,
                'cancelled' => $byStatus[SuchakCollaborationRequest::STATUS_CANCELLED] ?? 0,
                'admin_review' => $byStatus[SuchakCollaborationRequest::STATUS_ADMIN_REVIEW] ?? 0,
                'from_marketplace' => $fromMarketplace,
                'as_customer_owner' => $asCustomerOwner,
                'as_helper' => $asHelper,
                'role_unrecorded' => $roleUnrecorded,
                // Denominator is engagements that actually reached an end. A pending one has not
                // failed; counting it as a miss would punish a Suchak for a proposal nobody has
                // answered yet.
                'accepted_rate' => $this->rate($byStatus[SuchakCollaborationRequest::STATUS_ACCEPTED] ?? 0, $decided),
            ],
        ];
    }

    // ── marriages (§6.2) ─────────────────────────────────────────────────────────────────────

    /**
     * Marriages credited to engagements this Suchak was on.
     *
     * COUNTS ONLY, and no dimension of any kind. §6.2's row names two candidates, a wedding date
     * and an agreement revision; not one of those may cross to another Suchak, and a breakdown by
     * place or by month would re-identify the family the row is about. The claimed-but-unconfirmed
     * split IS published, because it is the difference between a marriage the family agreed to and
     * one man's assertion — which is the whole question a reader of this card is asking.
     *
     * The model's `SCOPE_LIVE` global scope applies, so a claim an admin set aside stops counting
     * here without this read knowing the door exists.
     *
     * @param  list<int>  $engagementIds
     * @return array{credited: int, payload: array<string, mixed>}
     */
    private function marriages(array $engagementIds): array
    {
        if ($engagementIds === []) {
            return [
                'credited' => 0,
                'payload' => [
                    'credited' => 0,
                    'confirmed_by_customer' => 0,
                    'claimed_awaiting_confirmation' => 0,
                ],
            ];
        }

        /** @var list<SuchakMarriageOutcome> $rows */
        $rows = SuchakMarriageOutcome::query()
            ->whereIn('collaboration_request_id', $engagementIds)
            ->with('stageEvent')
            ->get()
            ->all();

        $confirmed = 0;
        foreach ($rows as $row) {
            if ($row->isConfirmed()) {
                $confirmed++;
            }
        }

        return [
            'credited' => count($rows),
            'payload' => [
                'credited' => count($rows),
                'confirmed_by_customer' => $confirmed,
                'claimed_awaiting_confirmation' => count($rows) - $confirmed,
            ],
        ];
    }

    // ── challenges (D4 / D18) ────────────────────────────────────────────────────────────────

    /**
     * Challenges published, and what became of them.
     *
     * A8 is why the withdrawn count ships rather than being tidied away: *"withdrawing or
     * re-publishing a challenge to escape a declared share"* is a named attack, and a publisher who
     * withdraws everything he publishes looks exactly like one on this line.
     *
     * The fulfilment denominator is CLOSED challenges only. An open one has not failed — it is
     * still waiting for the market, and counting it as a miss would make a Suchak's number fall
     * every time he published.
     *
     * @return array{published: int, payload: array<string, mixed>}
     */
    private function challenges(int $accountId): array
    {
        $counts = SuchakMarketplaceChallenge::query()
            ->where('suchak_account_id', $accountId)
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $byStatus = [];
        foreach (SuchakMarketplaceChallenge::STATUSES as $status) {
            $byStatus[$status] = (int) ($counts[$status] ?? 0);
        }

        $published = array_sum($byStatus);
        $closed = $byStatus[SuchakMarketplaceChallenge::STATUS_FULFILLED]
            + $byStatus[SuchakMarketplaceChallenge::STATUS_WITHDRAWN]
            + $byStatus[SuchakMarketplaceChallenge::STATUS_EXPIRED];

        return [
            'published' => $published,
            'payload' => [
                'published' => $published,
                'open' => $byStatus[SuchakMarketplaceChallenge::STATUS_OPEN],
                'fulfilled' => $byStatus[SuchakMarketplaceChallenge::STATUS_FULFILLED],
                'withdrawn' => $byStatus[SuchakMarketplaceChallenge::STATUS_WITHDRAWN],
                'expired' => $byStatus[SuchakMarketplaceChallenge::STATUS_EXPIRED],
                'fulfilled_rate' => $this->rate($byStatus[SuchakMarketplaceChallenge::STATUS_FULFILLED], $closed),
            ],
        ];
    }

    // ── the one place a proportion is decided ────────────────────────────────────────────────

    /**
     * A proportion that refuses to lie about how much it knows.
     *
     * THREE OUTCOMES, and the caller is told which one it got rather than being handed a number it
     * cannot tell apart from a real one:
     *
     *  - no denominator at all  → `percent: null`, `no_events`. D13. NEVER `0`.
     *  - a denominator under {@see self::MIN_RATE_DENOMINATOR} → `percent: null`,
     *    `too_few_events`. The counts are still there; a reader can see "1 dispute out of 1
     *    meeting" and weigh it himself, which is a true sentence, where "100% disputed" is not.
     *  - enough events → the percentage, WITH its numerator and denominator beside it, because a
     *    rate divorced from its volume is the same lie one size larger.
     *
     * @return array<string, mixed>
     */
    private function rate(int $numerator, int $denominator): array
    {
        $base = [
            'numerator' => $numerator,
            'denominator' => $denominator,
            'threshold' => self::MIN_RATE_DENOMINATOR,
        ];

        if ($denominator <= 0) {
            return $base + [
                'percent' => null,
                'is_publishable' => false,
                'suppressed_reason' => self::SUPPRESSED_NO_EVENTS,
            ];
        }

        if ($denominator < self::MIN_RATE_DENOMINATOR) {
            return $base + [
                'percent' => null,
                'is_publishable' => false,
                'suppressed_reason' => self::SUPPRESSED_TOO_FEW_EVENTS,
            ];
        }

        return $base + [
            // The ONE percent formatter (`App\Support\PercentDisplay`), never a private copy: four
            // of those already existed in this domain and had drifted between one and two decimals,
            // so the same 12.25% read as "12.3" on one Suchak screen and "12.25" on the next.
            // `DECIMALS_RATE` is the right one here — this is a DERIVED rate, not a figure a human
            // typed. Latin digits by construction; nothing on the path is locale-aware.
            'percent' => PercentDisplay::rate($numerator, $denominator, PercentDisplay::DECIMALS_RATE),
            'is_publishable' => true,
            'suppressed_reason' => null,
        ];
    }

    /**
     * `SUM(CASE WHEN … THEN 1 ELSE 0 END) as alias`, spelled once.
     *
     * Written this way rather than as N separate `count()` round trips because a reputation card is
     * read by every helper deciding whether to answer a challenge, and ten queries per card for
     * one table is a cost that shows up on the marketplace's busiest screen. `SUM(CASE …)` is the
     * portable form: production is MySQL and the suite is SQLite, and `COUNT(*) FILTER` is not
     * available on both.
     */
    private function countIf(string $condition, string $alias): string
    {
        return 'SUM(CASE WHEN '.$condition.' THEN 1 ELSE 0 END) as '.$alias;
    }
}
