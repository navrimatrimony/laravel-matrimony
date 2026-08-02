<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakMarketplaceChallenge;
use App\Models\SuchakMarriageOutcome;
use App\Models\SuchakVisitConfirmation;
use App\Models\SuchakVisitConfirmationEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * D20 — THE CUSTOMER'S OWN TRAIL. History, not a rating.
 *
 * *"Customer-side signal is history, not rating — '8 meetings, 6 attended, 2 cancelled by the
 * family, 1 marriage'. Facts only, DERIVED FROM RECORDS, NEVER TYPED BY A SUCHAK. It stops at
 * marriage."*
 *
 * §15 records why this exists at all, in the product owner's own correction: the signal is not
 * there to deter the family — a family marries once and has no future exposure to deter. It is
 * there to inform a Suchak during a six-month, ten-meeting window (A11: *"a customer takes many
 * meetings and rejects everything"*).
 *
 * ── A READ, AND NOTHING BUT ──────────────────────────────────────────────────────────────────
 *
 * No column, no rollup, no rating, no score. Every figure is counted on the read from the meeting
 * engine's own rows and the stage ladder's own rows. "Never typed by a Suchak" is enforced by
 * construction rather than by a validator: there is no writer here, and every source column below
 * is written by the engine that owns it.
 *
 * ── NO MONEY IS PUBLISHED HERE, DELIBERATELY ─────────────────────────────────────────────────
 *
 * D17 puts the cumulative figure on the payments screen — *"where a person has gone to look at
 * money, not on the screen where they are deciding about a person"* — and D20 asks for facts about
 * what happened, not what it cost. Neither this service nor its door carries a rupee, so the
 * regret-ledger mistake §15 records twice cannot be made a third time through this payload.
 *
 * ── TWO FACTS D20 NAMES THAT THIS PLATFORM DOES NOT RECORD ───────────────────────────────────
 *
 * Both are stated in the payload's `coverage` block rather than papered over with a zero, because a
 * `0` a client renders as "0 cancelled by the family" is a claim about the family that no row
 * supports.
 *
 *  1. **"cancelled by the family" cannot happen.** {@see SuchakVisitConfirmationService::cancelVisit()}
 *     admits the ARRANGING Suchak or an admin, and refuses the member outright: a cancellation is a
 *     scheduling fact the arranging side owns, and M5 gives the family `dispute` instead. So
 *     `cancellations.by_family` is `null`, never `0` — the actor breakdown is read from the
 *     append-only visit trail (`suchak_visit_confirmation_events.actor_type`), which is where §5.1
 *     B4 said the cancelling actor should live, and it can only ever say `suchak` or `admin`.
 *  2. **Attendance is not recorded anywhere.** §5.1 B4 lists it as missing and it is still missing:
 *     there is no attendance column, no `actual_held_at` and no no-show event. The nearest true
 *     fact is a meeting whose date has passed while the row is still `scheduled` —
 *     `meetings.scheduled_past_date` — and it is named for exactly what it is. It is NOT called
 *     attendance, and it is NOT counted as "did not attend": a Suchak who simply has not got round
 *     to marking a meeting complete produces the same row as a family who did not turn up, and
 *     publishing the two as one fact would put a no-show on a family's record for a Suchak's
 *     paperwork.
 *
 * ── SCOPE ────────────────────────────────────────────────────────────────────────────────────
 *
 * Keyed on the CUSTOMER CONTEXT — the same key `SuchakTwelveMonthClauseService` uses for the same
 * family — and the door ({@see \App\Http\Controllers\Api\Suchak\SuchakCustomerHistoryApiController})
 * answers only the Suchak whose account owns that context. This service takes the context it is
 * given and counts; whose it is, is the door's question, and it answers it with a 404 so a Suchak
 * never learns that another Suchak's customer exists.
 */
class SuchakCustomerHistoryService
{
    /**
     * The customer's whole recorded trail.
     *
     * @return array<string, mixed>
     */
    public function forCustomer(SuchakCustomerContext $context, ?Carbon $at = null): array
    {
        $at ??= now();
        $contextId = (int) $context->id;

        $visitIds = SuchakVisitConfirmation::query()
            ->where('customer_context_id', $contextId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $meetings = $this->meetingTotals($contextId, $at);
        $cancellations = $this->cancellations($visitIds, $meetings['cancelled']);
        $engagementIds = $this->engagementIds($contextId);
        $ladder = $this->ladder($contextId, $engagementIds);
        $engagements = $this->engagements($engagementIds);
        $marriage = $this->marriage($engagementIds);

        $recordedEvents = $meetings['total']
            + $ladder['profiles_suggested']
            + $ladder['viewed']
            + $engagements['total']
            + $marriage['recorded_count'];

        return [
            'customer_context_id' => $contextId,
            'suchak_account_id' => (int) $context->suchak_account_id,
            // The counterpart of D13 on the customer side: a family with no trail yet is NEW, and a
            // screen must say so rather than render a wall of zeroes as a verdict on them.
            'is_new' => $recordedEvents === 0,
            'recorded_event_count' => $recordedEvents,
            // D20's last sentence, made machine-readable. The trail is closed by a CONFIRMED
            // marriage — a claim alone is one Suchak's word, and closing a family's record on it
            // would let a claim nobody agreed to end their history.
            'stops_at_marriage' => true,
            'is_closed_by_marriage' => $marriage['is_confirmed'],
            'marriage' => [
                'is_recorded' => $marriage['recorded_count'] > 0,
                'is_confirmed' => $marriage['is_confirmed'],
                'married_on' => $marriage['married_on'],
                'recorded_count' => $marriage['recorded_count'],
            ],
            'meetings' => [
                'arranged' => $meetings['total'],
                'scheduled_open' => $meetings['scheduled_open'],
                // A meeting whose date has passed while nobody has marked it either way. Named for
                // what it is; see the class docblock on why it is not attendance.
                'scheduled_past_date' => $meetings['scheduled_past_date'],
                'held' => $meetings['claims_made'],
                'confirmed_by_family' => $meetings['confirmed_by_family'],
                'refused_by_family' => $meetings['refused_by_family'],
                'awaiting_family' => $meetings['awaiting_family'],
                'disputed' => $meetings['disputed'],
                'cancelled' => $meetings['cancelled'],
                'repeat_meetings' => $meetings['repeat_meetings'],
                'offline' => $meetings['offline'],
                'online' => $meetings['online'],
            ],
            'cancellations' => $cancellations,
            'profiles' => [
                'suggested' => $ladder['profiles_suggested'],
                'viewed' => $ladder['viewed'],
                'interested' => $ladder['interested'],
                // A6 — "we already know them", the family's own one-tap release of the 12-month
                // clause. It sits on the `viewed` rung and nowhere else.
                'prior_acquaintance_declared' => $ladder['prior_acquaintance_declared'],
            ],
            'engagements' => $engagements['payload'],
            'coverage' => [
                // Both false, and both are §5.1 B4 items this platform still does not record. A
                // client must not print a zero for either.
                'family_cancellation_recorded' => false,
                'attendance_recorded' => false,
            ],
        ];
    }

    // ── meetings ─────────────────────────────────────────────────────────────────────────────

    /**
     * The meeting engine's columns for one family, counted in one pass.
     *
     * Which column answers which question is the same split
     * {@see SuchakReputationService::meetingTotals()} documents, read from the other side: there it
     * judges the Suchak, here it describes the family. `user_confirmation_status` is the FAMILY's
     * own word and nothing else writes it — an admin deciding a dispute deliberately does not stamp
     * it — so `confirmed_by_family` and `refused_by_family` are theirs alone.
     *
     * @return array<string, int>
     */
    private function meetingTotals(int $contextId, Carbon $at): array
    {
        $row = (array) SuchakVisitConfirmation::query()
            ->where('customer_context_id', $contextId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw($this->countIf('visit_status = ?', 'scheduled_open'), [SuchakVisitConfirmation::STATUS_SCHEDULED])
            ->selectRaw($this->countIf('visit_status = ?', 'cancelled'), [SuchakVisitConfirmation::STATUS_CANCELLED])
            ->selectRaw($this->countIf('visit_status = ? and scheduled_for is not null and scheduled_for < ?', 'scheduled_past_date'), [
                SuchakVisitConfirmation::STATUS_SCHEDULED,
                $at,
            ])
            ->selectRaw($this->countIf('suchak_completion_status = ?', 'claims_made'), [SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED])
            ->selectRaw($this->countIf('user_confirmation_status = ?', 'confirmed_by_family'), [SuchakVisitConfirmation::CONFIRMATION_CONFIRMED])
            ->selectRaw($this->countIf('user_confirmation_status = ?', 'refused_by_family'), [SuchakVisitConfirmation::CONFIRMATION_DISPUTED])
            ->selectRaw($this->countIf('dispute_id is not null', 'disputed'))
            ->selectRaw($this->countIf('meeting_sequence > 1', 'repeat_meetings'))
            ->selectRaw($this->countIf('meeting_mode = ?', 'online'), [SuchakVisitConfirmation::MODE_ONLINE])
            ->toBase()
            ->first();

        $totals = [];
        foreach ([
            'total', 'scheduled_open', 'cancelled', 'scheduled_past_date', 'claims_made',
            'confirmed_by_family', 'refused_by_family', 'disputed', 'repeat_meetings', 'online',
        ] as $key) {
            $totals[$key] = (int) ($row[$key] ?? 0);
        }

        $totals['awaiting_family'] = max(
            0,
            $totals['claims_made'] - $totals['confirmed_by_family'] - $totals['refused_by_family'] - $totals['disputed'],
        );
        $totals['offline'] = max(0, $totals['total'] - $totals['online']);

        return $totals;
    }

    /**
     * WHO CALLED THE MEETING OFF — read from the append-only visit trail, which is where §5.1 B4
     * said the cancelling actor belongs and where `cancelVisit()` actually writes it.
     *
     * `by_family` is `null` and not `0`. The family cannot cancel — see the class docblock — so a
     * zero here would be a measured finding about them, and it would be one this platform is
     * incapable of measuring. Null is the honest answer and the `coverage` block says why.
     *
     * @param  list<int>  $visitIds
     * @return array<string, mixed>
     */
    private function cancellations(array $visitIds, int $cancelledMeetings): array
    {
        $byActor = [];
        if ($visitIds !== []) {
            $byActor = SuchakVisitConfirmationEvent::query()
                ->whereIn('visit_confirmation_id', $visitIds)
                ->where('event_type', SuchakVisitConfirmationEvent::EVENT_CANCELLED)
                ->toBase()
                ->selectRaw('actor_type, COUNT(*) as aggregate')
                ->groupBy('actor_type')
                ->pluck('aggregate', 'actor_type')
                ->all();
        }

        return [
            'total' => $cancelledMeetings,
            'by_suchak' => (int) ($byActor[SuchakVisitConfirmationEvent::ACTOR_SUCHAK] ?? 0),
            'by_admin' => (int) ($byActor[SuchakVisitConfirmationEvent::ACTOR_ADMIN] ?? 0),
            // Not zero. Not recordable. See `coverage.family_cancellation_recorded`.
            'by_family' => null,
        ];
    }

    // ── the ladder (§6a) ─────────────────────────────────────────────────────────────────────

    /**
     * Every engagement this family's agreement revisions are on.
     *
     * TWO RECORDED LINKS, not a guess. An engagement belongs to this customer when either its
     * commission agreement NAMES one of this context's agreement revisions (`linkCustomerAgreement`,
     * write-once) or it answers a CHALLENGE published on one of them. Matching on the candidate
     * profile plus `customer_owner_side` was rejected: that column defaults to `target` in the
     * database and is written only by the link, so an unlinked engagement would be filed under
     * whichever family the default happened to point at.
     *
     * @return list<int>
     */
    private function engagementIds(int $contextId): array
    {
        $agreementIds = SuchakCustomerAgreement::query()
            ->where('customer_context_id', $contextId)
            ->pluck('id')
            ->all();

        if ($agreementIds === []) {
            return [];
        }

        $challengeIds = SuchakMarketplaceChallenge::query()
            ->whereIn('customer_agreement_id', $agreementIds)
            ->pluck('id')
            ->all();

        return SuchakCollaborationRequest::query()
            ->where(function (Builder $owned) use ($agreementIds, $challengeIds): void {
                $owned
                    ->whereIn('id', SuchakCommissionAgreement::query()
                        ->whereIn('customer_agreement_id', $agreementIds)
                        ->select('collaboration_request_id'));

                if ($challengeIds !== []) {
                    $owned->orWhereIn('marketplace_challenge_id', $challengeIds);
                }
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * What was put in front of this family, and what they did about it.
     *
     * `profile_suggested` is the HELPER's rung; `viewed` and `interested` are the FAMILY's own,
     * recorded over their portal link and refused to every Suchak
     * ({@see SuchakCollaborationStageEvent::assertClaimChannel()}). That split is what makes this a
     * record of the family's behaviour rather than of a Suchak's account of it — D20's *"never
     * typed by a Suchak"* is already enforced one layer down, and is not re-checked here.
     *
     * @param  list<int>  $engagementIds
     * @return array<string, int>
     */
    private function ladder(int $contextId, array $engagementIds): array
    {
        $empty = [
            'profiles_suggested' => 0,
            'viewed' => 0,
            'interested' => 0,
            'prior_acquaintance_declared' => 0,
        ];

        if ($engagementIds === []) {
            return $empty;
        }

        $counts = SuchakCollaborationStageEvent::query()
            ->whereIn('collaboration_request_id', $engagementIds)
            ->whereIn('stage_key', [
                SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED,
                SuchakCollaborationStageEvent::STAGE_VIEWED,
                SuchakCollaborationStageEvent::STAGE_INTERESTED,
            ])
            ->toBase()
            ->selectRaw('stage_key, COUNT(*) as aggregate')
            ->selectRaw('SUM(CASE WHEN prior_acquaintance_declared = 1 THEN 1 ELSE 0 END) as released')
            ->groupBy('stage_key')
            ->get()
            ->keyBy('stage_key');

        return [
            'profiles_suggested' => (int) ($counts[SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED]->aggregate ?? 0),
            'viewed' => (int) ($counts[SuchakCollaborationStageEvent::STAGE_VIEWED]->aggregate ?? 0),
            'interested' => (int) ($counts[SuchakCollaborationStageEvent::STAGE_INTERESTED]->aggregate ?? 0),
            // A6's release lives only on the `viewed` rung — the model refuses it anywhere else —
            // so it is read from that rung and nowhere else.
            'prior_acquaintance_declared' => (int) ($counts[SuchakCollaborationStageEvent::STAGE_VIEWED]->released ?? 0),
        ];
    }

    /**
     * How many Suchaks worked this family's case, and how those engagements ended.
     *
     * A12's screening signal in count form — *"days since registration, times published, helpers
     * who left"*. No Suchak is NAMED: a helping Suchak's identity is not this family's fact to
     * carry, and the owner reads the engagements themselves on `/suchak/collaborations`.
     *
     * @param  list<int>  $engagementIds
     * @return array{total: int, payload: array<string, mixed>}
     */
    private function engagements(array $engagementIds): array
    {
        $byStatus = array_fill_keys(SuchakCollaborationRequest::STATUSES, 0);
        $fromMarketplace = 0;

        if ($engagementIds !== []) {
            $rows = SuchakCollaborationRequest::query()
                ->whereIn('id', $engagementIds)
                ->toBase()
                ->selectRaw('status, COUNT(*) as aggregate')
                ->selectRaw('SUM(CASE WHEN marketplace_challenge_id is not null THEN 1 ELSE 0 END) as from_marketplace')
                ->groupBy('status')
                ->get();

            foreach ($rows as $row) {
                $byStatus[(string) $row->status] = (int) $row->aggregate;
                $fromMarketplace += (int) $row->from_marketplace;
            }
        }

        return [
            'total' => count($engagementIds),
            'payload' => [
                'total' => count($engagementIds),
                'pending' => $byStatus[SuchakCollaborationRequest::STATUS_PENDING] ?? 0,
                'accepted' => $byStatus[SuchakCollaborationRequest::STATUS_ACCEPTED] ?? 0,
                'rejected' => $byStatus[SuchakCollaborationRequest::STATUS_REJECTED] ?? 0,
                'expired' => $byStatus[SuchakCollaborationRequest::STATUS_EXPIRED] ?? 0,
                'cancelled' => $byStatus[SuchakCollaborationRequest::STATUS_CANCELLED] ?? 0,
                'admin_review' => $byStatus[SuchakCollaborationRequest::STATUS_ADMIN_REVIEW] ?? 0,
                'from_marketplace' => $fromMarketplace,
            ],
        ];
    }

    /**
     * The marriage, if one has been recorded on any of this family's engagements.
     *
     * Confirmation is READ through the stage event and never copied — the same rule
     * {@see SuchakMarriageOutcome} enforces on itself. `SCOPE_LIVE` applies, so a claim an admin
     * set aside does not close a family's history.
     *
     * @param  list<int>  $engagementIds
     * @return array{recorded_count: int, is_confirmed: bool, married_on: ?string}
     */
    private function marriage(array $engagementIds): array
    {
        if ($engagementIds === []) {
            return ['recorded_count' => 0, 'is_confirmed' => false, 'married_on' => null];
        }

        /** @var list<SuchakMarriageOutcome> $rows */
        $rows = SuchakMarriageOutcome::query()
            ->whereIn('collaboration_request_id', $engagementIds)
            ->with('stageEvent')
            ->orderBy('id')
            ->get()
            ->all();

        $confirmed = null;
        foreach ($rows as $row) {
            if ($row->isConfirmed()) {
                $confirmed = $row;

                break;
            }
        }

        $named = $confirmed ?? ($rows[0] ?? null);

        return [
            'recorded_count' => count($rows),
            'is_confirmed' => $confirmed !== null,
            // The owner of this customer already knows the family; the wedding date is his own
            // record and this door is his alone. It is deliberately absent from the reputation
            // read, where the audience is another Suchak.
            'married_on' => $named?->married_on?->toDateString(),
        ];
    }

    /**
     * `SUM(CASE WHEN … THEN 1 ELSE 0 END) as alias`. Portable across the MySQL production and the
     * SQLite suite, where `COUNT(*) FILTER` is not.
     */
    private function countIf(string $condition, string $alias): string
    {
        return 'SUM(CASE WHEN '.$condition.' THEN 1 ELSE 0 END) as '.$alias;
    }
}
