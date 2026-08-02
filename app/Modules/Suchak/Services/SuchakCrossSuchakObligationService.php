<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCrossSuchakObligation;
use App\Models\SuchakMarketplaceChallenge;
use App\Models\SuchakMarriageOutcome;
use App\Models\SuchakSuccessFeeTranche;
use App\Models\User;
use App\Support\MoneyFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * THE ONE OWNER of "Suchak A owes Suchak B" — blueprint §7 M2, M3, M9, M10 and §9a A7.
 *
 * Four verbs and three reads:
 *
 *   raise()            turn a recorded marriage + a declared share into rows that name a payer
 *   settle()           the HELPER marks the share received (A7), and the loop closes on the ladder
 *   forEngagement()    owed-vs-paid for one engagement
 *   ledgerFor()        owed-vs-paid for one account, in both directions
 *   declarerRatio()    A7's realized-vs-declared, as a READ
 *   overdueExposureFor() §7.3's raw exposure figure, for whatever gate wants to read it
 *
 * ── WHAT THIS SERVICE DELIBERATELY DOES NOT DO ───────────────────────────────────────────────
 *
 * It creates NO payout hold, freezes NO feature and moves NO rupee. §7.3 is precise about the
 * limit: *"the platform does not stand between a customer and a Suchak for Suchak-earned fees. It
 * DOES control Suchak payouts."* A cross-Suchak share is Suchak-earned money paid Suchak-to-Suchak
 * off-platform; the only lever the platform holds over a defaulting payer is money it already owes
 * HIM — `suchak_platform_payouts` — and a payer with no pending payout has no balance to hold. So
 * this service publishes the exposure figure and the ratio, and a gate that wants to act on them
 * reads them. M1 forbids anything more: there is no shared pot and the platform guarantees nothing.
 *
 * It also writes no tranche state. `suchak_success_fee_tranches.settled_at` /
 * `customer_payment_id` are M3's half-A answer and are READ here; their writer is the tranche
 * release slice, and a second writer would be a second answer to "has the customer paid".
 */
class SuchakCrossSuchakObligationService
{
    /**
     * WHICH SIDE of an engagement a derived slice is being asked about.
     *
     * NOT two derivations — one routine ({@see derivedUnraisedObligations()}) asked a different
     * question. A7's ratio only ever asks the payer question, because a ratio is about a declarer's
     * own promises; the account ledger asks both, because being owed a share nobody raised is just
     * as real a fact as owing one.
     */
    private const DERIVED_FOR_PAYER = 'payer';

    private const DERIVED_FOR_PAYEE = 'payee';

    public function __construct(
        private readonly SuchakCollaborationService $collaborationService,
        private readonly SuchakSuccessFeeTrancheService $trancheService,
        private readonly SuchakActivityLogger $activityLogger,
    ) {
    }

    /**
     * Turn the recorded marriage on this engagement into the obligations the declared share created.
     *
     * IDEMPOTENT. Calling it twice adds nothing — which is what lets EITHER Suchak call it. That is
     * not a convenience: M3 says suppressing the record must accelerate the obligation and never
     * kill it, so the payee must be able to raise his own share without waiting for the payer to do
     * it. (The payee can already record the marriage itself: `STAGE_MARRIAGE` is
     * `CLAIMANT_EITHER_SUCHAK`.)
     *
     * ── AN OBLIGATION MAY NOT EXIST AHEAD OF THE THING IT IS A SHARE OF ─────────────────────
     *
     * Two gates, and the reason for both is one sentence: a share of a fee that cannot be collected
     * is a debt nobody owes. M1 — *"no shared pot, each customer pays only their own Suchak"* — is
     * broken just as thoroughly by making A pay B out of pocket as by a platform float.
     *
     *  GATE 1, the marriage.   The `marriage` rung must be SETTLED, and `marriage` is one of
     *      `CONFIRMABLE_STAGES`, so settled means the CUSTOMER (or an admin standing in) confirmed
     *      it — `SuchakCollaborationStageEvent::isSettled()` is that predicate and it is not
     *      restated here. Without this gate the payee records the wedding himself (the rung is
     *      `CLAIMANT_EITHER_SUCHAK`), types the date, raises his own share, and thirty days later
     *      the payer is publicly overdue on A7's ratio for a fee whose own release is stuck on the
     *      same unconfirmed rung. It is also the row's DEADLINE: `married_on` is typed by whoever
     *      claims, so an unconfirmed rung would let the payee choose the day his own money falls due.
     *
     *  GATE 2, the tranche.    A tranche is credited only when the ledger's OWN derivation says it
     *      has a release instant — `SuchakSuccessFeeTrancheService::entitlement()`, row by row. That
     *      one call carries every release rule at once and none of them is copied here: the trigger
     *      rung must have settled (or a later one, M10's cascade), the terms of the LIVE revision
     *      must be accepted, M9's other-chain block must not apply, and a trigger sitting ABOVE
     *      `LAST_RELEASING_STAGE` never gets an instant at all — so the `share_settled`-triggered
     *      tranche that can never release can never be billed as a share either.
     *
     * ── WHICH TRANCHES THIS ENGAGEMENT IS CREDITED WITH ──────────────────────────────────────
     *
     * M10: *"A later stage releases every earlier unpaid tranche with it. A wedding held without a
     * साखरपुडा still owes the engagement tranche."* That cascade is `entitlement()`'s, read above.
     * On top of it, a tranche already released against a DIFFERENT engagement is skipped — §7.4's
     * per-tranche attribution and M9's "the paid tranche stands": if helper A's match produced the
     * settled tranche and helper B's produced the wedding, A's declared share applies to A's tranche
     * and B's to B's.
     *
     * ── THE HONEST GAP THIS LEAVES, STATED RATHER THAN PAPERED OVER ─────────────────────────
     *
     * §2: the customer is a family and *"`users.mobile` is null whenever the number on file is a
     * household number"*. A family with no login cannot confirm anything, so on those engagements
     * gate 1 passes only when an ADMIN confirms the marriage in their place
     * (`CONFIRM_ACTOR_TYPES` admits `ACTOR_ADMIN`, and `SuchakCollaborationService::confirmStage()`
     * is the door). Until Phase 6's OTP (D23) lands there is no third route: if no admin confirms,
     * no obligation is raised on that engagement at all. That is a real gap and it is the right one
     * — a share that nobody can collect the fee for must not be a debt on anyone's public ratio.
     *
     * @return list<SuchakCrossSuchakObligation> the rows that exist afterwards, ordered
     */
    public function raise(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $account,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $account->refresh();
        $collaboration->refresh()->loadMissing('commissionAgreement', 'marketplaceChallenge');

        // The existing owner of "is this a real accepted engagement, and are you on it". Both
        // commission acknowledgements are required, which is right: the share is a term of the
        // engagement and an engagement one side never acknowledged has no terms to owe under.
        $this->collaborationService->assertAcceptedParticipant($collaboration, $account, $actor);

        $challenge = $collaboration->marketplaceChallenge;
        if ($challenge === null) {
            // D5 in one sentence, said to the person who pressed the button.
            throw new InvalidArgumentException(
                'हे सहकार्य बाजारपेठेतील आव्हानातून झालेले नाही, त्यामुळे आधी जाहीर केलेला वाटा नाही — जाहीर न केलेले काहीही देय नसते.'
            );
        }

        $outcome = $this->marriageOutcome($collaboration);
        if ($outcome === null) {
            throw new InvalidArgumentException(
                'या सहकार्यासाठी विवाहाची नोंद अजून झालेली नाही, त्यामुळे वाटा कधी देय होतो हे ठरवता येत नाही.'
            );
        }

        // GATE 1 — see the docblock. The rung's own predicate, never a second copy of it.
        if (! $this->marriageIsSettled($outcome)) {
            throw new InvalidArgumentException(
                'विवाहाची नोंद अजून ग्राहकाकडून निश्चित झालेली नाही. निश्चित होईपर्यंत यशस्वी विवाह शुल्कच लागू होत नाही, '
                .'त्यामुळे त्यातील वाटाही देय होत नाही.'
            );
        }

        $totalShare = $challenge->declaredShareTotal();
        if ($totalShare === null || $totalShare <= 0.0) {
            // A percent of `as_wished` / `none` / a null amount has no total. Same refusal
            // SuchakSuccessFeeTrancheService::assertPackageCarriesFixedSuccessFee() makes.
            throw new InvalidArgumentException(
                'जाहीर केलेला वाटा रकमेत मांडता येत नाही — ठरलेले यशस्वी विवाह शुल्क नसताना टक्केवारीला आधार नाही.'
            );
        }

        $plan = $this->collectibleSlices($collaboration, $challenge, $totalShare);

        DB::transaction(function () use ($collaboration, $challenge, $outcome, $plan): void {
            /** @var SuchakCollaborationRequest $locked */
            $locked = SuchakCollaborationRequest::query()
                ->whereKey($collaboration->id)
                ->lockForUpdate()
                ->firstOrFail();

            $common = [
                'payer_suchak_account_id' => $locked->customerOwnerSuchakAccountId(),
                'payee_suchak_account_id' => $locked->helpingSuchakAccountId(),
                'collaboration_request_id' => (int) $locked->id,
                'marriage_outcome_id' => (int) $outcome->id,
                'marketplace_challenge_id' => (int) $challenge->id,
                'currency' => $plan['currency'],
            ];

            foreach ($plan['rows'] as $row) {
                $trancheId = $row['tranche']?->id;

                // The unique index cannot close the no-installment-plan case — MySQL and SQLite both
                // treat a tuple containing NULL as distinct — so both cases are closed here, under
                // the engagement row lock taken above.
                $exists = SuchakCrossSuchakObligation::query()
                    ->where('marriage_outcome_id', $outcome->id)
                    ->when(
                        $trancheId === null,
                        static fn ($query) => $query->whereNull('success_fee_tranche_id'),
                        static fn ($query) => $query->where('success_fee_tranche_id', $trancheId),
                    )
                    ->lockForUpdate()
                    ->exists();

                if ($exists) {
                    continue;
                }

                SuchakCrossSuchakObligation::query()->create($common + [
                    'success_fee_tranche_id' => $trancheId === null ? null : (int) $trancheId,
                    'amount' => $row['amount'],
                ]);
            }
        });

        $obligations = $this->obligationsFor($collaboration);
        $this->recordActivity(
            $collaboration,
            $actor,
            SuchakActivityLog::ACTION_CROSS_SUCHAK_OBLIGATION_RAISED,
            $obligations,
            $account,
            $ipAddress,
            $userAgent,
        );

        return $obligations;
    }

    /**
     * The HELPER marks his share received — A7's *"share-settled stage, markable only by the
     * helper"*, at the level of one obligation.
     *
     * Only the PAYEE may do it, and the reason is the whole of A7: a payer who could mark his own
     * debt settled would author the realized-vs-declared ratio that judges him.
     */
    public function settle(
        SuchakCrossSuchakObligation $obligation,
        SuchakAccount $account,
        User $actor,
        ?string $reference = null,
        ?string $note = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCrossSuchakObligation {
        $account->refresh();
        $obligation->refresh()->loadMissing('collaborationRequest');

        $collaboration = $obligation->collaborationRequest;
        if ($collaboration === null) {
            throw new InvalidArgumentException('A cross-Suchak obligation must name the engagement it arose on.');
        }

        // The existing owner of actor↔account↔engagement: the acting user must own the account, the
        // account must be on the engagement, the account must be able to operate, and the engagement
        // must be accepted with both commission acknowledgements.
        $this->collaborationService->assertAcceptedParticipant($collaboration, $account, $actor);

        if ((int) $obligation->payee_suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException(
                'वाटा मिळाल्याची नोंद फक्त ज्याला तो मिळायचा आहे तो सूचकच करू शकतो.'
            );
        }

        DB::transaction(function () use ($obligation, $actor, $reference, $note): void {
            /** @var SuchakCrossSuchakObligation $locked */
            $locked = SuchakCrossSuchakObligation::query()
                ->whereKey($obligation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isSettled()) {
                throw new InvalidArgumentException('या वाट्याची नोंद आधीच झाली आहे.');
            }

            $locked->forceFill([
                'settled_at' => now(),
                'settled_by_user_id' => $actor->id,
                'settlement_reference' => $reference === null ? null : Str::limit(trim($reference), 160, ''),
                'settlement_note' => $note,
            ])->save();
        });

        $obligation->refresh();
        $this->closeLoopIfFullySettled($collaboration, $account, $actor);

        $this->recordActivity(
            $collaboration,
            $actor,
            SuchakActivityLog::ACTION_CROSS_SUCHAK_OBLIGATION_SETTLED,
            [$obligation],
            $account,
            $ipAddress,
            $userAgent,
        );

        return $obligation->fresh(['successFeeTranche', 'marriageOutcome']) ?? $obligation;
    }

    /**
     * Owed-vs-paid for ONE engagement — the §11 phase 4 gate in a payload.
     *
     * @return array<string, mixed>
     */
    public function forEngagement(SuchakCollaborationRequest $collaboration, ?Carbon $at = null): array
    {
        $obligations = $this->obligationsFor($collaboration);
        $at ??= now();

        return [
            'collaboration_request_id' => (int) $collaboration->id,
            'payer_suchak_account_id' => $collaboration->customerOwnerSuchakAccountId(),
            'payee_suchak_account_id' => $collaboration->helpingSuchakAccountId(),
            'marketplace_challenge_id' => $collaboration->marketplace_challenge_id === null
                ? null
                : (int) $collaboration->marketplace_challenge_id,
            'share_settled_stage_recorded_at' => $this->settlementRung($collaboration)?->claimed_at?->toIso8601String(),
            'totals' => $this->totals($obligations, $at),
            'obligations' => array_map(fn (SuchakCrossSuchakObligation $row): array => $this->payload($row, $at), $obligations),
        ];
    }

    /**
     * One account's whole cross-Suchak position, in BOTH directions. Two lists because they are two
     * different facts about the same Suchak — what he owes decides his A7 ratio, what he is owed
     * decides whether the marketplace was worth entering.
     *
     * ── DERIVE-THEN-RECORD, ON THE LEDGER TOO (fixed 2026-08-04) ─────────────────────────────
     *
     * This read used to return STORED ROWS ONLY while {@see declarerRatio()} — the card rendered
     * directly above this list on the same screen — counted stored rows PLUS the ones
     * {@see derivedUnraisedObligations()} computes. One screen therefore said "जाहीर: 3 · उशीर: 2"
     * and, an inch below it, "अजून एकही आंतर-सूचक नोंद नाही", about the same Suchak's money. It was
     * not a rendering bug: a marriage that is recorded, confirmed and paid produces a real, overdue
     * cross-Suchak debt, and the ledger showed the payer nothing to raise and the payee nothing to
     * chase. The judged party could not act on a number he was being judged by.
     *
     * So the SAME derivation now feeds both. Not a second copy of it — the identical private
     * routine, asked once per direction, over the identical {@see collectibleSlices()} that
     * `raise()` persists. A derived row is marked `is_derived` in {@see payload()} and carries
     * `obligation_id = 0`, so nothing here can be mistaken for a debt somebody committed to.
     *
     * @return array<string, mixed>
     */
    public function ledgerFor(SuchakAccount $account, ?Carbon $at = null): array
    {
        $at ??= now();
        $accountId = (int) $account->id;

        $recordedOwedByMe = $this->rows(SuchakCrossSuchakObligation::query()->owedBy($accountId));
        $recordedOwedToMe = $this->rows(SuchakCrossSuchakObligation::query()->owedTo($accountId));

        // ONE dedup baseline for BOTH directions — every stored row this account is named on. A
        // derived slice is dropped the moment ANY stored row covers it, so a row and its derived
        // twin can never both reach `totals()` and inflate a sum two businesses will argue about.
        // Per-direction baselines would be enough today (a raised row's payer is the engagement's
        // customer owner by `assertMatchesItsOrigin()`, so its twin always lands in the same list),
        // but that holds only while `customer_owner_side` never moves after a raise. The union
        // costs one array_merge and does not depend on that.
        $recorded = array_merge($recordedOwedByMe, $recordedOwedToMe);

        $owedByMe = array_merge(
            $recordedOwedByMe,
            $this->derivedUnraisedObligations($accountId, $recorded, self::DERIVED_FOR_PAYER),
        );
        $owedToMe = array_merge(
            $recordedOwedToMe,
            $this->derivedUnraisedObligations($accountId, $recorded, self::DERIVED_FOR_PAYEE),
        );

        return [
            'suchak_account_id' => (int) $account->id,
            'owed_by_me' => [
                'totals' => $this->totals($owedByMe, $at),
                'obligations' => array_map(fn (SuchakCrossSuchakObligation $row): array => $this->payload($row, $at), $owedByMe),
            ],
            'owed_to_me' => [
                'totals' => $this->totals($owedToMe, $at),
                'obligations' => array_map(fn (SuchakCrossSuchakObligation $row): array => $this->payload($row, $at), $owedToMe),
            ],
        ];
    }

    /**
     * §9a A7 — THE REALIZED-VS-DECLARED RATIO, AS A READ.
     *
     * *"Declaring 70/30 and never paying"* is closed by *"the share-settled stage, markable only by
     * the helper, plus a public realized-vs-declared ratio on every declarer's card."* Before this
     * method, `grep -i realized` over `app/` returned four comments and zero code, and
     * `STAGE_SHARE_SETTLED` was claimable and read by nothing.
     *
     * DENOMINATOR = obligations RAISED, PLUS the ones that would be raised if anybody pressed the
     * button. Never challenges published: D5 is explicit that a Suchak who declared nothing owes
     * nothing, and a Suchak whose fifty challenges never produced a match owes nothing either —
     * counting publications would punish him for a market that did not answer, which is the
     * opposite of what A7 is aimed at.
     *
     * ── DERIVE-THEN-RECORD, RESTORED ─────────────────────────────────────────────────────────
     *
     * A ratio whose denominator only exists once somebody presses `raise()` is a ratio the judged
     * party controls by inaction: the declarer never raises his own debt, and a helper who has
     * given up chasing his share never raises it either — so fifty unpaid shares render as
     * `is_new = true`, the NEW badge, on the card of the worst payer on the platform. That is A7
     * inverted.
     *
     * So this read carries the Phase 3 discipline the ledger dropped (`isClaimLapsed()`,
     * `entitlement()`, `shareFallsDueAt()` — all of them a recorded fact PLUS arithmetic that is
     * correct on a production where nothing has ever swept): the STORED rows, plus the rows
     * {@see derivedUnraisedObligations()} computes from facts already recorded — a confirmed
     * marriage, a declared challenge, a released tranche — through the very same
     * {@see collectibleSlices()} that `raise()` persists. A stored row always wins over its derived
     * twin, and the payload states how many of each it counted rather than blending them silently.
     *
     * §7.3's {@see overdueExposureFor()} deliberately does NOT do this: exposure is the figure a
     * payout gate would act on, and acting is reserved for debts that have actually been recorded
     * against a named payee. A public number may be derived; a lever may not.
     *
     * D13 — *"a new Suchak shows a New badge, never 0 marriages"* — is why a Suchak with no
     * obligations gets `realized_ratio_percent = null` and `is_new = true`, never `0`. Zero out of
     * zero rendered as 0% is a defamation with arithmetic behind it.
     *
     * The ratio is computed PER CURRENCY. A ratio of amounts in two currencies is not a number, and
     * this domain's own rule ("a fee can never carry another") is what makes mixing them impossible
     * rather than merely untidy.
     *
     * @return array<string, mixed>
     */
    public function declarerRatio(int $suchakAccountId, ?Carbon $at = null): array
    {
        $at ??= now();
        $recorded = $this->rows(SuchakCrossSuchakObligation::query()->owedBy($suchakAccountId));
        $derived = $this->derivedUnraisedObligations($suchakAccountId, $recorded, self::DERIVED_FOR_PAYER);
        $rows = array_merge($recorded, $derived);

        $byCurrency = [];
        foreach ($rows as $row) {
            $currency = (string) $row->currency;
            $byCurrency[$currency] ??= [];
            $byCurrency[$currency][] = $row;
        }
        ksort($byCurrency);

        $currencies = [];
        foreach ($byCurrency as $currency => $currencyRows) {
            $totals = $this->totals($currencyRows, $at);
            $declared = (float) $totals['declared_amount'];
            $settled = (float) $totals['settled_amount'];

            $currencies[] = $totals + [
                'currency' => $currency,
                'realized_ratio_percent' => $declared <= 0.0
                    ? null
                    : $this->readablePercent($settled / $declared * 100),
            ];
        }

        $across = $this->totalsAcross($rows, $at);
        $declaredCount = count($rows);

        return [
            'suchak_account_id' => $suchakAccountId,
            // D13. A Suchak with no cross-Suchak history is NEW, not a defaulter.
            'is_new' => $declaredCount === 0,
            'declared_obligation_count' => $declaredCount,
            // Derive-then-record, said out loud: how much of the denominator is a stored row and
            // how much is arithmetic over facts nobody has turned into a row yet.
            'recorded_obligation_count' => count($recorded),
            'derived_obligation_count' => count($derived),
            'settled_obligation_count' => (int) $across['settled_count'],
            'overdue_obligation_count' => (int) $across['overdue_count'],
            'oldest_overdue_days' => $across['oldest_overdue_days'],
            // Only meaningful in one currency; stated rather than silently summed.
            'mixed_currency' => count($currencies) > 1,
            'realized_ratio_percent' => count($currencies) === 1 ? $currencies[0]['realized_ratio_percent'] : null,
            'by_currency' => $currencies,
        ];
    }

    /**
     * §7.3's raw exposure figure for one payer — what a payout gate would need if the platform ever
     * decides to hold against it.
     *
     * This method DECIDES NOTHING. It creates no `SuchakPayoutHold`, cancels no payout and freezes
     * no feature: the platform's leverage exists only over money it already owes this Suchak, and a
     * payer with no pending payout has none. Publishing the number is the honest half; inventing a
     * platform guarantee over money the platform never touches is what M1 forbids.
     *
     * @return array<string, mixed>
     */
    public function overdueExposureFor(int $suchakAccountId, ?Carbon $at = null): array
    {
        $at ??= now();
        $rows = array_filter(
            $this->rows(SuchakCrossSuchakObligation::query()->owedBy($suchakAccountId)->unsettled()),
            static fn (SuchakCrossSuchakObligation $row): bool => $row->isOverdue($at),
        );

        $totals = $this->totalsAcross(array_values($rows), $at);

        return [
            'suchak_account_id' => $suchakAccountId,
            'overdue_count' => (int) $totals['overdue_count'],
            'overdue_amount' => $totals['overdue_amount'],
            'overdue_amount_display' => $totals['overdue_amount_display'],
            'oldest_overdue_days' => $totals['oldest_overdue_days'],
            // Stated so no caller mistakes this read for an enforcement (§7.3, M1).
            'platform_enforces' => false,
        ];
    }

    /**
     * A7's ARITHMETIC HALF — the obligations that exist in fact but not yet in a row.
     *
     * Nothing here writes. It walks this declarer's marketplace engagements that already carry a
     * confirmed marriage and asks {@see collectibleSlices()} — the same routine `raise()` persists —
     * what the declared share comes to, then returns UNSAVED model instances for the slices no
     * stored row covers. Unsaved and not saveable by accident: they are never handed to a writer,
     * and `SuchakCrossSuchakObligation::assertMatchesItsOrigin()` would refuse anything malformed on
     * `saving` in any case. They are model instances rather than arrays so that `isSettled()`,
     * `isOverdue()` and `overdueDays()` stay in ONE place — the row that owns M3.
     *
     * A derived row is by definition UNSETTLED: settlement is a fact only the payee can record, and
     * an unrecorded settlement is exactly what A7 is measuring the absence of.
     *
     * THE ROLE IS THE MODEL'S, NEVER THE QUERY'S. The SQL narrows by PARTICIPATION — the two
     * directional account columns — and the engagement's own role accessors then decide which of the
     * two sides this account is on. Reading `target_suchak_account_id` as "the payer" would be the
     * §6.2 direction/role confusion one layer down, and on a challenge answered by proposing it is
     * backwards.
     *
     * $role is which side is being asked about, and it is the ONLY thing that differs between A7's
     * ratio (always the payer — a ratio judges a declarer's own promises) and the account ledger,
     * which asks both because a share nobody raised is owed to somebody as surely as it is owed by
     * somebody. The arithmetic, the gates and the dedup grain are one and the same either way.
     *
     * @param  list<SuchakCrossSuchakObligation>  $recorded  the stored rows a derived slice must not duplicate
     * @param  self::DERIVED_FOR_*  $role
     * @return list<SuchakCrossSuchakObligation>
     */
    private function derivedUnraisedObligations(int $suchakAccountId, array $recorded, string $role): array
    {
        $alreadyRecorded = [];
        foreach ($recorded as $row) {
            $alreadyRecorded[$this->sliceKey(
                (int) $row->marriage_outcome_id,
                $row->success_fee_tranche_id === null ? null : (int) $row->success_fee_tranche_id,
            )] = true;
        }

        $outcomes = SuchakMarriageOutcome::query()
            ->whereHas('collaborationRequest', function (Builder $query) use ($suchakAccountId): void {
                // M2/D5 — only a declared share can produce an obligation, so only a marketplace
                // engagement is a candidate at all.
                $query->whereNotNull('marketplace_challenge_id')
                    ->where(function (Builder $participant) use ($suchakAccountId): void {
                        $participant
                            ->where('requesting_suchak_account_id', $suchakAccountId)
                            ->orWhere('target_suchak_account_id', $suchakAccountId);
                    });
            })
            ->with([
                'stageEvent',
                'collaborationRequest.commissionAgreement',
                'collaborationRequest.marketplaceChallenge',
            ])
            ->orderBy('id')
            ->get();

        $derived = [];
        foreach ($outcomes as $outcome) {
            $collaboration = $outcome->collaborationRequest;
            if ($collaboration === null) {
                continue;
            }

            // The engagement's two ROLES, read from the engagement. Participation was already
            // established by the query; this is which of the two seats the caller asked about.
            $onTheSideAsked = $role === self::DERIVED_FOR_PAYER
                ? $collaboration->isCustomerOwner($suchakAccountId)
                : $collaboration->helpingSuchakAccountId() === $suchakAccountId;

            if (! $onTheSideAsked) {
                continue;
            }

            $challenge = $collaboration->marketplaceChallenge;
            if ($challenge === null || ! $this->marriageIsSettled($outcome)) {
                continue;
            }

            $totalShare = $challenge->declaredShareTotal();
            if ($totalShare === null || $totalShare <= 0.0) {
                continue;
            }

            try {
                $plan = $this->collectibleSlices($collaboration, $challenge, $totalShare);
            } catch (InvalidArgumentException) {
                // An engagement whose ledger cannot be resolved at all (no customer agreement
                // linked) owes nothing derivable. A READ never throws on one bad engagement.
                continue;
            }

            foreach ($plan['rows'] as $row) {
                $trancheId = $row['tranche'] === null ? null : (int) $row['tranche']->id;
                if (isset($alreadyRecorded[$this->sliceKey((int) $outcome->id, $trancheId)])) {
                    continue;
                }

                $derived[] = $this->unsavedObligation($collaboration, $challenge, $outcome, $row, $plan['currency']);
            }
        }

        return $derived;
    }

    /**
     * One derived slice as a model instance — never saved, never returned through a write door.
     *
     * @param  array{tranche: ?SuchakSuccessFeeTranche, amount: string}  $row
     */
    private function unsavedObligation(
        SuchakCollaborationRequest $collaboration,
        SuchakMarketplaceChallenge $challenge,
        SuchakMarriageOutcome $outcome,
        array $row,
        string $currency,
    ): SuchakCrossSuchakObligation {
        $obligation = new SuchakCrossSuchakObligation();
        $obligation->forceFill([
            'payer_suchak_account_id' => $collaboration->customerOwnerSuchakAccountId(),
            'payee_suchak_account_id' => $collaboration->helpingSuchakAccountId(),
            'collaboration_request_id' => (int) $collaboration->id,
            'marriage_outcome_id' => (int) $outcome->id,
            'marketplace_challenge_id' => (int) $challenge->id,
            'success_fee_tranche_id' => $row['tranche'] === null ? null : (int) $row['tranche']->id,
            'amount' => $row['amount'],
            'currency' => $currency,
            'settled_at' => null,
        ]);

        // The relations M3 reads, handed over rather than lazily loaded off an unsaved row.
        $obligation->setRelation('marriageOutcome', $outcome);
        $obligation->setRelation('marketplaceChallenge', $challenge);
        $obligation->setRelation('successFeeTranche', $row['tranche']);

        return $obligation;
    }

    /**
     * The grain of one slice: a marriage plus a tranche, or a marriage plus "the whole fee". Same
     * pair the unique index carries, spelled once so the stored and derived sides cannot disagree.
     */
    private function sliceKey(int $marriageOutcomeId, ?int $trancheId): string
    {
        return $marriageOutcomeId.':'.($trancheId === null ? 'whole_fee' : $trancheId);
    }

    /**
     * The §6.2 attribution row for one engagement, or null. Read, never written here.
     */
    private function marriageOutcome(SuchakCollaborationRequest $collaboration): ?SuchakMarriageOutcome
    {
        /** @var SuchakMarriageOutcome|null $outcome */
        $outcome = SuchakMarriageOutcome::query()
            ->where('collaboration_request_id', $collaboration->id)
            ->first();

        return $outcome;
    }

    /**
     * GATE 1's predicate, borrowed whole.
     *
     * `marriage` is one of `CONFIRMABLE_STAGES`, so `isSettled()` there means `confirmed_at` — by
     * the customer over their portal link, or by an admin standing in for a family with no login.
     * The claim alone is not the fact; that split is the stage model's and is not re-decided here.
     */
    private function marriageIsSettled(SuchakMarriageOutcome $outcome): bool
    {
        $outcome->loadMissing('stageEvent');

        return $outcome->stageEvent?->isSettled() === true;
    }

    /**
     * THE ONE OWNER of "what would this engagement's declared share come to, and on which tranches".
     *
     * Two callers, deliberately: {@see raise()} persists these rows, and
     * {@see derivedUnraisedObligations()} instantiates the same rows unsaved so A7's ratio can be
     * answered without anyone pressing raise. A second copy of this arithmetic would be a second
     * answer to the largest cross-Suchak figure in the system.
     *
     * WHICH REVISION. `entitlement()` resolves the agreement through
     * `SuchakSuccessFeeTrancheService::ledgerAgreementFor()` — the LATEST revision on the chain,
     * which is where release and settlement actually write. Reading the revision the §6.2 row is
     * BOUND to (`marriage_outcome.customer_agreement_id`, forced by `assertMatchesItsEngagement`)
     * would point every obligation at rows that will never release and never settle: M3 half A
     * would be permanently null, and the §7.4 attribution skip below would test dead rows and hand
     * helper B the tranche helper A released. One revision owns the ledger, and it is the live one.
     *
     * @return array{currency: string, rows: list<array{tranche: ?SuchakSuccessFeeTranche, amount: string}>}
     */
    private function collectibleSlices(
        SuchakCollaborationRequest $collaboration,
        SuchakMarketplaceChallenge $challenge,
        float $totalShare,
    ): array {
        $currency = $challenge->declaredShareCurrency();
        $entitlement = $this->trancheService->entitlement($collaboration);
        $planned = $entitlement['rows'];

        if ($planned === []) {
            // No installment plan at all: there is no tranche row to carry a release instant, so the
            // declared share is ONE obligation over the whole fee and the confirmed marriage rung
            // (gate 1) is what earned it.
            //
            // GATE 2 STILL APPLIES, in the only form this case can express it. With a plan, a tranche
            // under terms the customer has not accepted is blocked and therefore skipped above; the
            // same customer with no plan must not be treated as owing MORE. `terms_satisfied` is the
            // LIVE revision's, and a fresh revision starts `pending` — so without this line a Suchak
            // could revise his terms, leave them unaccepted, and still have a helper's share raised
            // against a fee the customer currently owes nothing of.
            if ($entitlement['terms_satisfied'] !== true) {
                return ['currency' => $currency, 'rows' => []];
            }

            return [
                'currency' => $currency,
                'rows' => [['tranche' => null, 'amount' => number_format($totalShare, 2, '.', '')]],
            ];
        }

        // T1 + T2 arithmetic, performed by its one owner and over the WHOLE plan: a share of the
        // fee, cut the same way the fee itself is cut. Slicing only the collectible subset would
        // re-price the remainder tranche the moment one row was skipped.
        $sliceAmounts = $this->trancheService->amounts(
            $totalShare,
            array_map(static fn (array $row): SuchakSuccessFeeTranche => $row['tranche'], $planned),
        );

        $rows = [];
        foreach ($planned as $index => $row) {
            /** @var SuchakSuccessFeeTranche $tranche */
            $tranche = $row['tranche'];

            // GATE 2 — the ledger's own derivation. No release instant means this tranche has not
            // been earned (its rung is unsettled, the live revision's terms are unaccepted, M9
            // blocked it on another chain) or CAN never be earned (a trigger above the ladder's
            // last releasing stage). Either way there is no fee here to take a share of.
            if ($row['released_at'] === null) {
                continue;
            }

            // §7.4 / M9 — a tranche already released against ANOTHER engagement belongs to that
            // helper's work, and crediting it here would hand one helper the fruit of another's.
            if ($tranche->released_by_collaboration_request_id !== null
                && (int) $tranche->released_by_collaboration_request_id !== (int) $collaboration->id) {
                continue;
            }

            $rows[] = ['tranche' => $tranche, 'amount' => $sliceAmounts[$index]];
        }

        return ['currency' => $currency, 'rows' => $rows];
    }

    /**
     * @return list<SuchakCrossSuchakObligation>
     */
    private function obligationsFor(SuchakCollaborationRequest $collaboration): array
    {
        return $this->rows(
            SuchakCrossSuchakObligation::query()->where('collaboration_request_id', $collaboration->id)
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<SuchakCrossSuchakObligation>  $query
     * @return list<SuchakCrossSuchakObligation>
     */
    private function rows($query): array
    {
        /** @var list<SuchakCrossSuchakObligation> $rows */
        $rows = $query
            ->with(['successFeeTranche', 'marriageOutcome', 'marketplaceChallenge'])
            ->orderBy('id')
            ->get()
            ->all();

        return $rows;
    }

    /**
     * A7 closes the loop on the ladder: when nothing is left outstanding on this engagement, the
     * `share_settled` rung is claimed — by the HELPER, which is who `STAGE_CLAIMANTS` already names
     * and who this payee is.
     *
     * The rung is EVIDENCE, and the settlement is MONEY. So a rung that cannot be claimed never
     * costs the settlement: the failure is logged and the row stays settled. The already-recorded
     * case is decided by RE-READING rather than by matching the exception's text — a message is not
     * an interface, a trap this repository has already recorded once.
     */
    private function closeLoopIfFullySettled(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $account,
        User $actor,
    ): ?SuchakCollaborationStageEvent {
        $outstanding = SuchakCrossSuchakObligation::query()
            ->where('collaboration_request_id', $collaboration->id)
            ->unsettled()
            ->exists();

        if ($outstanding) {
            return null;
        }

        $existing = $this->settlementRung($collaboration);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->collaborationService->claimStage(
                $collaboration,
                $account,
                $actor,
                SuchakCrossSuchakObligation::SETTLEMENT_STAGE,
                null,
            );
        } catch (InvalidArgumentException $exception) {
            Log::warning('Cross-Suchak share settled, but the share_settled rung could not be claimed.', [
                'collaboration_request_id' => (int) $collaboration->id,
                'suchak_account_id' => (int) $account->id,
                'reason' => $exception->getMessage(),
            ]);

            return $this->settlementRung($collaboration);
        }
    }

    private function settlementRung(SuchakCollaborationRequest $collaboration): ?SuchakCollaborationStageEvent
    {
        /** @var SuchakCollaborationStageEvent|null $event */
        $event = SuchakCollaborationStageEvent::query()
            ->where('collaboration_request_id', $collaboration->id)
            ->where('stage_key', SuchakCrossSuchakObligation::SETTLEMENT_STAGE)
            ->first();

        return $event;
    }

    /**
     * @param  list<SuchakCrossSuchakObligation>  $rows
     * @return array<string, mixed>
     */
    private function totals(array $rows, Carbon $at): array
    {
        $currencies = array_values(array_unique(array_map(
            static fn (SuchakCrossSuchakObligation $row): string => (string) $row->currency,
            $rows,
        )));

        return $this->totalsAcross($rows, $at) + [
            'currency' => count($currencies) === 1 ? $currencies[0] : null,
            'mixed_currency' => count($currencies) > 1,
        ];
    }

    /**
     * Sums in integer PAISE, then formatted once. Floats do not add up and this is the number two
     * businesses will argue about.
     *
     * @param  list<SuchakCrossSuchakObligation>  $rows
     * @return array<string, mixed>
     */
    private function totalsAcross(array $rows, Carbon $at): array
    {
        $declaredPaise = 0;
        $settledPaise = 0;
        $overduePaise = 0;
        $settledCount = 0;
        $overdueCount = 0;
        $oldestOverdueDays = null;

        foreach ($rows as $row) {
            $paise = (int) round(((float) $row->amount) * 100);
            $declaredPaise += $paise;

            if ($row->isSettled()) {
                $settledPaise += $paise;
                $settledCount++;

                continue;
            }

            if (! $row->isOverdue($at)) {
                continue;
            }

            $overduePaise += $paise;
            $overdueCount++;
            $days = $row->overdueDays($at);
            if ($days !== null && ($oldestOverdueDays === null || $days > $oldestOverdueDays)) {
                $oldestOverdueDays = $days;
            }
        }

        $outstandingPaise = $declaredPaise - $settledPaise;
        $currency = count($rows) > 0 ? (string) $rows[0]->currency : 'INR';

        return [
            'declared_count' => count($rows),
            'declared_amount' => $this->rupees($declaredPaise),
            'declared_amount_display' => MoneyFormat::amount($this->rupees($declaredPaise), $currency),
            'settled_count' => $settledCount,
            'settled_amount' => $this->rupees($settledPaise),
            'settled_amount_display' => MoneyFormat::amount($this->rupees($settledPaise), $currency),
            'outstanding_count' => count($rows) - $settledCount,
            'outstanding_amount' => $this->rupees($outstandingPaise),
            'outstanding_amount_display' => MoneyFormat::amount($this->rupees($outstandingPaise), $currency),
            'overdue_count' => $overdueCount,
            'overdue_amount' => $this->rupees($overduePaise),
            'overdue_amount_display' => MoneyFormat::amount($this->rupees($overduePaise), $currency),
            'oldest_overdue_days' => $oldestOverdueDays,
        ];
    }

    /**
     * One obligation, said out loud. Latin digits by construction — no locale-aware formatter is
     * reachable from here, and every rupee string comes through MoneyFormat.
     *
     * @return array<string, mixed>
     */
    private function payload(SuchakCrossSuchakObligation $obligation, Carbon $at): array
    {
        return [
            // ZERO on a derived row — the model is unsaved, so `id` is null and the cast makes it 0.
            // That is deliberate and is the second half of the settle guard: `is_derived` says what
            // the row is, and a `0` here means there is no path to
            // `POST /cross-suchak-obligations/{obligation}/settle` that could resolve to anything
            // (it would 404 on route-model binding). RAISE is the verb a derived row answers to, and
            // it is keyed on `collaboration_request_id`, which every row carries for real.
            'obligation_id' => (int) $obligation->id,
            // THE SENTENCE THIS TABLE EXISTS TO SAY.
            'payer_suchak_account_id' => (int) $obligation->payer_suchak_account_id,
            'payee_suchak_account_id' => (int) $obligation->payee_suchak_account_id,
            'amount' => (string) $obligation->amount,
            'currency' => (string) $obligation->currency,
            'amount_display' => MoneyFormat::amount($obligation->amount, (string) $obligation->currency),
            // THE ORIGIN — which engagement, which marriage, which declaration, which tranche.
            'collaboration_request_id' => (int) $obligation->collaboration_request_id,
            'marriage_outcome_id' => (int) $obligation->marriage_outcome_id,
            'marketplace_challenge_id' => (int) $obligation->marketplace_challenge_id,
            'success_fee_tranche_id' => $obligation->success_fee_tranche_id === null
                ? null
                : (int) $obligation->success_fee_tranche_id,
            'trigger_stage_key' => $obligation->successFeeTranche?->trigger_stage_key,
            'trigger_stage_label' => $obligation->successFeeTranche === null
                ? null
                : SuchakCollaborationStageEvent::stageLabel((string) $obligation->successFeeTranche->trigger_stage_key),
            'married_on' => $obligation->marriageOutcome?->married_on?->toDateString(),
            // M3, both halves, each named for what it is.
            'customer_paid_at' => $obligation->customerPaidAt()?->toIso8601String(),
            'customer_payment_is_answerable' => $obligation->customerPaymentIsAnswerable(),
            'marriage_clock_due_at' => $obligation->marriageClockDueAt()?->toIso8601String(),
            'share_due_days_after_marriage' => SuchakMarriageOutcome::SHARE_DUE_DAYS_AFTER_MARRIAGE,
            'falls_due_at' => $obligation->fallsDueAt()?->toIso8601String(),
            'due_reason' => $obligation->dueReason($at),
            'is_due' => $obligation->isDue($at),
            'is_overdue' => $obligation->isOverdue($at),
            'overdue_days' => $obligation->overdueDays($at),
            // THE SETTLEMENT. All four settlement columns are read here, because a column with a
            // writer and no reader is a column nobody can hold anyone to: `settled_by_user_id` is
            // WHICH PERSON in the payee's account said the money arrived — the one settlement fact
            // that is not derivable — and `settlement_note` is what he said about it. In a dispute a
            // year later they are the evidence; unread, they were a promise the row could not keep.
            'is_settled' => $obligation->isSettled(),
            'settled_at' => $obligation->settled_at?->toIso8601String(),
            'settled_by_user_id' => $obligation->settled_by_user_id === null
                ? null
                : (int) $obligation->settled_by_user_id,
            'settlement_reference' => $obligation->settlement_reference,
            'settlement_note' => $obligation->settlement_note,
            // A7's arithmetic half — true on a slice this read DERIVED because nobody raised it.
            // Never persisted, and never true on a stored row.
            'is_derived' => ! $obligation->exists,
        ];
    }

    private function rupees(int $paise): string
    {
        return number_format($paise / 100, 2, '.', '');
    }

    /**
     * Latin digits, no trailing ".0" on a whole percentage. Same shape
     * SuchakSuccessFeeTrancheService uses for a percent a human reads.
     */
    private function readablePercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.');
    }

    /**
     * Filed under the PAYER's account. This log is about a declarer's promises and whether he kept
     * them — the same filing rule ACTION_MARRIAGE_OUTCOME_RECORDED and
     * ACTION_MARKETPLACE_PROPOSAL_RECEIVED use, with whoever acted travelling in the metadata.
     *
     * @param  list<SuchakCrossSuchakObligation>  $obligations
     */
    private function recordActivity(
        SuchakCollaborationRequest $collaboration,
        User $actor,
        string $actionType,
        array $obligations,
        SuchakAccount $account,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $collaboration->customerOwnerSuchakAccountId(),
            'actor_user_id' => $actor->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => $actionType,
            'target_type' => 'suchak_cross_suchak_obligation',
            'target_id' => $obligations === [] ? null : (int) $obligations[array_key_last($obligations)]->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => [
                'context' => $actionType,
                'collaboration_request_id' => (int) $collaboration->id,
                'payer_suchak_account_id' => $collaboration->customerOwnerSuchakAccountId(),
                'payee_suchak_account_id' => $collaboration->helpingSuchakAccountId(),
                'acted_by_suchak_account_id' => (int) $account->id,
                'obligation_ids' => array_map(
                    static fn (SuchakCrossSuchakObligation $row): int => (int) $row->id,
                    $obligations,
                ),
                'obligation_count' => count($obligations),
            ],
        ]);
    }
}
