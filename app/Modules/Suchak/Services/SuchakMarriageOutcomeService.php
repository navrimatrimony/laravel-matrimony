<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakMarriageOutcome;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Blueprint §6.2 — RECORD THE MARRIAGE, AND NAME THE ENGAGEMENT CREDITED WITH IT.
 *
 * One verb: {@see record()}. It writes two rows in one transaction — the `marriage` rung on the
 * ladder (through the existing single writer, never a second one) and the §6.2 attribution row that
 * names the engagement, the agreement revision in force, that rung as the evidence, and the DATE OF
 * THE WEDDING.
 *
 * ── WHY THE DATE FORCED A DOOR OF ITS OWN ────────────────────────────────────────────────────
 *
 * `SuchakCollaborationService::claimStage()` is stage-agnostic by design: a stage key, an optional
 * note, and nothing else. A wedding date is a fact only the `marriage` rung has, so bolting a date
 * parameter onto the generic claimer would have put a parameter meaningless on thirteen of fourteen
 * rungs into the one method every rung passes through. Instead this service OWNS the marriage verb
 * and DELEGATES the rung to the generic writer, so there is still exactly one place a stage event
 * is created and exactly one place the ladder's actor rules are enforced.
 *
 * The consequence is deliberate and is enforced at the HTTP edge: `marriage` is removed from the
 * generic stage route's accepted keys (SuchakCollaborationStagesApiController), because a
 * `marriage` rung claimed without a wedding date is a marriage M3 cannot put a clock on and a §6.2
 * row that does not exist.
 *
 * ── TWO ENTRY SHAPES, TWO GATES, AND WHY THEY DIFFER ─────────────────────────────────────────
 *
 *  - NO rung yet — the normal case. `claimStage()` runs, with the full ladder gates: participant,
 *    verified account, accepted engagement, customer agreement revision recorded, terms in force,
 *    D26's either-Suchak claimant rule.
 *  - A rung ALREADY claimed and no outcome on it — data from before this door existed, and the rung
 *    an admin's correction leaves standing. Attaching an attribution to a rung SOMEBODY ELSE
 *    claimed is a stronger act than claiming one, so it takes the stronger gate:
 *    `assertAcceptedParticipant()`, which additionally requires both commission acknowledgements.
 *    That is not an inconsistency; it is the difference between recording your own act and
 *    completing another party's.
 *
 * ── ONE TRANSACTION, OR NEITHER ROW ──────────────────────────────────────────────────────────
 *
 * The rung and the §6.2 row are written inside ONE transaction and there is no path that produces
 * one without the other. `claimStage()` opens its own transaction; nested inside this one it
 * becomes a savepoint, so a refusal below it — a candidate already credited elsewhere, a wedding
 * date this recorder may not type, the model's own guards — rolls the rung back with it.
 *
 * This was the worst defect in Phase 4. The rung used to be committed FIRST, by a separate
 * transaction, and a refused recording left it standing on an engagement that could never carry an
 * attribution. `SuchakSuccessFeeTrancheService` keys the entire release on SETTLED RUNGS and never
 * reads this table, so a single confirmation on that orphan released the whole success fee with
 * nothing naming who had earned it. The invariant is now enforced from both ends: nothing can
 * create a lone rung here, and `SuchakCollaborationService::confirmStage()` refuses to confirm a
 * `marriage` rung that has no LIVE attribution row behind it, so even a rung this service did not
 * write can never settle into money.
 *
 * ── WHAT THIS SERVICE DOES NOT DO ────────────────────────────────────────────────────────────
 *
 * No money. It releases no tranche, writes no ledger row and touches no payout — those belong to
 * `SuchakSuccessFeeTrancheService` and the payout files, which read this row rather than being
 * called from it. The one money-adjacent thing here is a DATE, and even that is arithmetic on the
 * model ({@see SuchakMarriageOutcome::shareFallsDueAt()}) rather than a stored figure.
 */
class SuchakMarriageOutcomeService
{
    public function __construct(
        private readonly SuchakCollaborationService $collaborationService,
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakAccessService $accessService,
    ) {
    }

    /**
     * Record that this engagement's two candidates married on `$marriedOn`, and credit the
     * engagement with it.
     *
     * @param  Carbon|string|null  $marriedOn  the WEDDING DAY, not the day it is being reported
     */
    public function record(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $account,
        User $actor,
        Carbon|string|null $marriedOn,
        ?string $note = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakMarriageOutcome {
        $account->refresh();
        $collaboration->refresh()->loadMissing('commissionAgreement.customerAgreement');

        // Both refusals are pure reads and happen before anything is written, so a bad date can
        // never be the thing that has to be rolled back.
        $weddingDay = $this->weddingDay($collaboration, $marriedOn);
        $this->assertRecorderMaySetThisDate($collaboration, $account, $weddingDay);

        $outcome = DB::transaction(function () use ($collaboration, $account, $actor, $note, $weddingDay): SuchakMarriageOutcome {
            /** @var SuchakCollaborationRequest $locked */
            $locked = SuchakCollaborationRequest::query()
                ->whereKey($collaboration->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->loadMissing('commissionAgreement');

            $customerCandidateId = (int) $locked->customerOwnerMatrimonyProfileId();
            $spouseCandidateId = (int) $locked->helpingMatrimonyProfileId();

            // Asked BEFORE the rung is claimed, so the common refusal costs nothing to undo.
            $this->assertNeitherCandidateIsAlreadyMarried($customerCandidateId, $spouseCandidateId);

            $existingRung = $this->existingMarriageRung($locked);
            if ($existingRung !== null) {
                // Completing a rung another party claimed — the stronger gate. See the class docblock.
                $this->collaborationService->assertAcceptedParticipant($locked, $account, $actor);
            }

            // Nested inside this transaction, so `claimStage()`'s own transaction is a savepoint and
            // every refusal below takes the rung down with it.
            $event = $existingRung ?? $this->collaborationService->claimStage(
                $locked,
                $account,
                $actor,
                SuchakMarriageOutcome::EVIDENCE_STAGE,
                $note,
            );

            if (SuchakMarriageOutcome::query()->where('stage_event_id', $event->id)->exists()) {
                throw new InvalidArgumentException('या सहकार्यासाठी विवाहाची नोंद आधीच झाली आहे.');
            }

            return SuchakMarriageOutcome::query()->create([
                'collaboration_request_id' => $locked->id,
                'customer_agreement_id' => $locked->commissionAgreement?->customer_agreement_id,
                'stage_event_id' => $event->id,
                'married_matrimony_profile_id' => $customerCandidateId,
                'spouse_matrimony_profile_id' => $spouseCandidateId,
                'married_on' => $weddingDay->toDateString(),
            ]);
        });

        $this->recordActivity($collaboration, $account, $actor, $outcome, $ipAddress, $userAgent);

        return $outcome->fresh(['stageEvent', 'customerAgreement']) ?? $outcome;
    }

    /**
     * THE CORRECTION DOOR — §6.2's competing claims, and the way out of the wrong one.
     *
     * §6.2 opens with "two Suchaks can hold simultaneously valid representations, agreements and
     * success-fee terms on the same candidate". So a rival Suchak holding his own engagement on
     * candidate X may claim a marriage on it, and the candidate-level uniqueness that stops the
     * ₹1,00,000 having two owners used to make that first tap FINAL: these rows are undeletable, no
     * update path existed, and the real engagement's attribution was destroyed by whoever moved
     * first. A first-write-wins rule on an UNCONFIRMED claim is not attribution, it is a race.
     *
     * WHAT A COMPETING CLAIM MEANS NOW, in two sentences:
     *
     *  - Against a CONFIRMED attribution it means nothing. D26's confirmation is what turns a
     *    Suchak's word into the attribution, so a confirmed row is refused to this door outright —
     *    an admin able to set that aside could take a settled success fee off the Suchak who earned
     *    it, and a family's own confirmation is not an administrator's to overrule.
     *  - Against an UNCONFIRMED one it means the platform holds two accounts of the same candidate
     *    and cannot tell which is true. It still refuses the second row — one live attribution per
     *    candidate, always — but the first no longer wins forever: an admin who establishes which
     *    engagement produced the wedding sets the wrong claim aside WITH A STATED REASON, and the
     *    right engagement then records normally.
     *
     * The row is SET ASIDE, never erased — the discipline every evidentiary row in this domain
     * carries. It keeps its candidates, its terms, its evidence and its date, and stops counting.
     *
     * The rival's `marriage` RUNG deliberately survives: it is his claim, and this door corrects the
     * attribution, not the ladder. It cannot turn into money on its own, because
     * `SuchakCollaborationService::confirmStage()` refuses to confirm a `marriage` rung with no live
     * attribution behind it — and if the void was for a mistyped date rather than a wrong
     * engagement, that standing rung is exactly what the same engagement re-records against.
     */
    public function voidClaim(
        SuchakMarriageOutcome $outcome,
        User $admin,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakMarriageOutcome {
        if (! $this->accessService->isAdmin($admin)) {
            throw new InvalidArgumentException(
                'विवाहाची नोंद रद्द करण्याचा अधिकार फक्त प्रशासकाला आहे.'
            );
        }

        $statedReason = trim($reason);
        if ($statedReason === '') {
            throw new InvalidArgumentException(
                'नोंद रद्द करण्याचे कारण लिहिणे आवश्यक आहे — कारणाशिवाय रद्द करणे म्हणजे पुरावा पुसणे.'
            );
        }

        [$voided, $auditLog] = DB::transaction(function () use ($outcome, $admin, $statedReason): array {
            /** @var SuchakMarriageOutcome|null $locked */
            $locked = SuchakMarriageOutcome::includingVoided()
                ->whereKey($outcome->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new InvalidArgumentException('ही विवाह नोंद सापडली नाही.');
            }

            if ($locked->isVoided()) {
                throw new InvalidArgumentException('ही विवाह नोंद आधीच रद्द केलेली आहे.');
            }

            $locked->loadMissing('stageEvent');
            if ($locked->isConfirmed()) {
                throw new InvalidArgumentException(
                    'या विवाहाला ग्राहकाचा दुजोरा मिळालेला आहे, त्यामुळे ही नोंद रद्द करता येणार नाही.'
                );
            }

            // Written through the model, not a builder update, so the row's own guards run on the
            // way out: `void_seq` and `voided_at` must agree, and the row must still match its
            // engagement.
            $locked->forceFill([
                'void_seq' => (int) $locked->id,
                'voided_at' => now(),
                'voided_by_user_id' => $admin->id,
                'void_reason' => Str::limit($statedReason, 500, ''),
            ])->save();

            // The platform-wide admin trail, through the existing writer. `SuchakActivityLogger`
            // refuses an ACTOR_ADMIN row without one, and that refusal is the right shape: an
            // administrator setting aside somebody else's attribution must land in the same audit
            // table as every other admin act, not only in the Suchak's own timeline.
            $auditLog = AuditLogService::log(
                $admin,
                SuchakActivityLog::ACTION_MARRIAGE_OUTCOME_VOIDED,
                class_basename($locked),
                (int) $locked->id,
                $statedReason
                    .' | collaboration_request_id='.(int) $locked->collaboration_request_id
                    .' | married_on='.$locked->married_on->toDateString(),
            );

            return [$locked, $auditLog];
        });

        $this->recordVoidActivity($voided, $admin, $auditLog, $ipAddress, $userAgent);

        return SuchakMarriageOutcome::includingVoided()
            ->with(['stageEvent', 'customerAgreement'])
            ->findOrFail($voided->id);
    }

    /**
     * The §6.2 row for one engagement, or null while no marriage has been recorded on it.
     */
    public function outcomeFor(SuchakCollaborationRequest $collaboration): ?SuchakMarriageOutcome
    {
        /** @var SuchakMarriageOutcome|null $outcome */
        $outcome = SuchakMarriageOutcome::query()
            ->with(['stageEvent', 'customerAgreement', 'marriedMatrimonyProfile', 'spouseMatrimonyProfile'])
            ->where('collaboration_request_id', $collaboration->id)
            ->first();

        return $outcome;
    }

    /**
     * The marriage this platform recorded for one candidate, whichever side of the engagement they
     * sat on. Null is the honest answer for "we have no record", never a claim that they did not
     * marry — this platform only sees the marriages it was told about.
     */
    public function outcomeForCandidate(int $matrimonyProfileId): ?SuchakMarriageOutcome
    {
        /** @var SuchakMarriageOutcome|null $outcome */
        $outcome = SuchakMarriageOutcome::query()
            ->forCandidate($matrimonyProfileId)
            ->with(['stageEvent', 'customerAgreement'])
            ->first();

        return $outcome;
    }

    /**
     * The attribution card §6.2 exists to produce: who is credited, under which terms, on what
     * evidence, for a wedding on which day, and when M3's share clock runs out.
     *
     * Latin digits with no localisation call anywhere — the frozen digit rule. Dates are ISO here
     * because this is an API payload; the app formats them.
     *
     * @return array<string, mixed>
     */
    public function attribution(SuchakMarriageOutcome $outcome): array
    {
        $outcome->loadMissing(['stageEvent', 'customerAgreement', 'collaborationRequest']);
        $collaboration = $outcome->collaborationRequest;

        return [
            'marriage_outcome_id' => (int) $outcome->id,
            // WHEN THE WEDDING HAPPENED.
            'married_on' => $outcome->married_on->toDateString(),
            // WHEN IT WAS REPORTED — a different fact, named differently on purpose.
            'reported_at' => $outcome->stageEvent?->claimed_at?->toIso8601String(),
            'confirmed_at' => $outcome->stageEvent?->confirmed_at?->toIso8601String(),
            'is_confirmed' => $outcome->isConfirmed(),
            // The engagement credited with the introduction (§6.2), by ROLE and never by direction.
            'credited_collaboration_request_id' => (int) $outcome->collaboration_request_id,
            'credited_customer_owner_suchak_account_id' => $collaboration?->customerOwnerSuchakAccountId(),
            'credited_helping_suchak_account_id' => $collaboration?->helpingSuchakAccountId(),
            // The terms the success fee is a fee under.
            'customer_agreement_id' => (int) $outcome->customer_agreement_id,
            'customer_agreement_revision' => $outcome->customerAgreement?->agreement_revision === null
                ? null
                : (int) $outcome->customerAgreement->agreement_revision,
            // The evidence.
            'evidence_stage_event_id' => (int) $outcome->stage_event_id,
            'evidence_stage_key' => SuchakMarriageOutcome::EVIDENCE_STAGE,
            'evidence_stage_label' => SuchakCollaborationStageEvent::stageLabel(SuchakMarriageOutcome::EVIDENCE_STAGE),
            'married_matrimony_profile_id' => (int) $outcome->married_matrimony_profile_id,
            'spouse_matrimony_profile_id' => (int) $outcome->spouse_matrimony_profile_id,
            // M3, by arithmetic over the wedding date — no stored column, no scheduler.
            'share_due_days_after_marriage' => SuchakMarriageOutcome::SHARE_DUE_DAYS_AFTER_MARRIAGE,
            'share_falls_due_at' => $outcome->shareFallsDueAt()->toIso8601String(),
            'share_due_by_elapsed_days' => $outcome->isShareDueByElapsedDays(),
            // The correction door's record. Always false/null on the Suchak read door, which only
            // ever sees live rows; the admin door reads a set-aside row back through this same card
            // so the withdrawal is legible in the same shape as the claim.
            'is_voided' => $outcome->isVoided(),
            'voided_at' => $outcome->voided_at?->toIso8601String(),
            'void_reason' => $outcome->void_reason,
        ];
    }

    /**
     * The wedding day, refused rather than coerced when it cannot be true.
     *
     * Three refusals, all about the DATE and none about the reporter — who typed it is the next
     * method's question:
     *
     *  - NO DATE AT ALL. This rule used to live only in the controller's `['required', 'date']`,
     *    and the service took a blank string straight to `Carbon::parse('')`, which answers NOW —
     *    so a caller that omitted the field recorded TODAY as the wedding day, a date nobody typed
     *    and the one M3 starts its clock from. A rule that only exists at one door is a rule the
     *    next door does not have, and this service is the door §6.2's row goes through.
     *  - A FUTURE date is not a marriage. §7.4's whole argument for why nothing is ever refunded is
     *    that every tranche is released by an event that has ALREADY HAPPENED; a wedding booked for
     *    next month releases the largest tranche in the system against a future that may not
     *    arrive, which is precisely the refund question D25 removes.
     *  - A date BEFORE the engagement existed cannot have been produced by it. §6.2 credits the
     *    engagement with the INTRODUCTION, so a wedding that predates the introduction belongs to
     *    somebody else's work, and crediting it here would hand a helper a share of a match he had
     *    no part in.
     */
    private function weddingDay(SuchakCollaborationRequest $collaboration, Carbon|string|null $marriedOn): Carbon
    {
        if (! $marriedOn instanceof Carbon && trim((string) $marriedOn) === '') {
            throw new InvalidArgumentException(
                'विवाहाची तारीख आवश्यक आहे — तारखेशिवाय नोंदवलेला विवाह म्हणजे ज्यावर M3 ची मुदत सुरूच होऊ शकत नाही असा विवाह.'
            );
        }

        try {
            $day = $marriedOn instanceof Carbon
                ? $marriedOn->copy()->startOfDay()
                : Carbon::parse(trim((string) $marriedOn))->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('विवाहाची तारीख वाचता आली नाही.');
        }

        if ($day->greaterThan(now()->endOfDay())) {
            throw new InvalidArgumentException(
                'विवाहाची तारीख भविष्यातील असू शकत नाही — जो विवाह अजून झालेला नाही त्याची नोंद होत नाही.'
            );
        }

        $engagementOpenedOn = $collaboration->requested_at?->copy()->startOfDay();
        if ($engagementOpenedOn !== null && $day->lessThan($engagementOpenedOn)) {
            throw new InvalidArgumentException(
                'विवाहाची तारीख हे सहकार्य सुरू होण्यापूर्वीची आहे, त्यामुळे या ओळखीचे श्रेय या सहकार्याला देता येणार नाही.'
            );
        }

        return $day;
    }

    /**
     * THE PAYEE MAY NOT CHOOSE THE DATE THAT STARTS HIS OWN CLOCK.
     *
     * `marriage` is CLAIMANT_EITHER_SUCHAK (D26) and M3 runs the cross-Suchak share from
     * `married_on + SHARE_DUE_DAYS_AFTER_MARRIAGE`. Put together, the HELPING Suchak — the party the
     * share is owed TO — could record the wedding himself, type a date already older than his own
     * deadline, and hold an OVERDUE claim against the other Suchak the second it was written. The
     * two existing refusals do not reach it: the date is not in the future and does not predate the
     * engagement. It is simply older than it was.
     *
     * WHAT IS ACTUALLY KNOWABLE. This platform did not attend the wedding and never learns the date
     * from anywhere but the person typing it (D23, §8 — there is no OTP on production). So the rule
     * cannot be "is this date true"; it can only be "does this date pay the person typing it".
     *
     *  - The CUSTOMER-OWNING Suchak is the party the share is owed BY. An old date he types runs
     *    HIS OWN clock out sooner — a statement against his own interest, which is the only
     *    backdating this platform has any reason to believe. He may name any past day inside the
     *    engagement's life, and M3's "suppressing the record must ACCELERATE the obligation" is
     *    exactly that door: reporting late with the true old date is how it accelerates.
     *  - The HELPING Suchak is the beneficiary. He may still record the wedding — refusing him would
     *    let the other side kill the obligation by silence, which is the half of M3's sentence that
     *    says "never kill it" — but the date he names must still leave his own window open. He can
     *    start his clock; he cannot hand himself a clock that already ran out.
     *
     * The window is `SHARE_DUE_DAYS_AFTER_MARRIAGE` and is READ from the constant, not re-typed, so
     * the deadline and the rule that protects it can never drift apart. What the helper loses is the
     * head start, never the share: the day after he records, his window is running. A wedding truly
     * older than that has an honest door of its own — the Suchak who owes the money records it.
     */
    private function assertRecorderMaySetThisDate(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $account,
        Carbon $weddingDay,
    ): void {
        if (! $collaboration->isHelpingSuchak((int) $account->id)) {
            return;
        }

        $earliestHeMayName = now()->startOfDay()->subDays(SuchakMarriageOutcome::SHARE_DUE_DAYS_AFTER_MARRIAGE);
        if ($weddingDay->greaterThanOrEqualTo($earliestHeMayName)) {
            return;
        }

        throw new InvalidArgumentException(
            'ही तारीख नोंदवल्यास तुमच्या स्वतःच्या वाट्याची मुदत नोंदीच्या दिवशीच संपलेली असेल, त्यामुळे मदत करणारा '
            .'सूचक इतकी जुनी तारीख नोंदवू शकत नाही. '
            .SuchakMarriageOutcome::SHARE_DUE_DAYS_AFTER_MARRIAGE
            .' दिवसांच्या आतील तारीख नोंदवा, किंवा त्याहून जुना विवाह असल्यास ती नोंद ग्राहकाच्या सूचकाने करावी.'
        );
    }

    /**
     * §6.2's ambiguity, closed at the level the database cannot express.
     *
     * "Two Suchaks can hold simultaneously valid representations, agreements and success-fee terms
     * on the same candidate." Both may therefore hold an engagement on that candidate and both may
     * legitimately claim a `marriage` rung on their own engagement — the ladder's unique index is
     * per (engagement, stage) and does not refuse the second one. Without this check two attribution
     * rows would exist and the largest sum in the system would have two owners.
     *
     * The four UNIQUE indexes on the table close the same-column half absolutely, and they are what
     * still holds if a second writer is ever added. Neither MySQL nor SQLite can express "this
     * profile appears in NEITHER column of any other row", so the cross-column half is asserted
     * here, under the engagement row lock the caller already holds. Both halves now read LIVE rows
     * only — a claim an admin has set aside is evidence, not an attribution, and must block nothing.
     *
     * THE REFUSAL NAMES THE DOOR. Being refused is still the right answer — one candidate, one live
     * attribution — but which refusal it is depends on the standing claim, and the difference is the
     * whole of blocker 3: a CONFIRMED claim is final and the message says so, while an UNCONFIRMED
     * one is a competing account of the same candidate that an admin can set aside
     * ({@see voidClaim()}). Saying "already recorded" and stopping is what made the first tap look
     * permanent to everybody who ever hit it.
     */
    private function assertNeitherCandidateIsAlreadyMarried(int $customerCandidateId, int $spouseCandidateId): void
    {
        foreach ([$customerCandidateId, $spouseCandidateId] as $profileId) {
            /** @var SuchakMarriageOutcome|null $existing */
            $existing = SuchakMarriageOutcome::query()
                ->forCandidate($profileId)
                ->with('stageEvent')
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                continue;
            }

            $name = MatrimonyProfile::query()->whereKey($profileId)->value('full_name');

            throw new InvalidArgumentException(
                ($name === null ? 'या उमेदवाराचा' : $name.' यांचा')
                .' विवाह आधीच नोंदवला आहे आणि त्याचे श्रेय दुसऱ्या सहकार्याला दिले आहे. '
                .($existing->isConfirmed()
                    ? 'त्या नोंदीला ग्राहकाचा दुजोरा मिळालेला आहे, त्यामुळे ती नोंद बदलता येणार नाही.'
                    : 'ती नोंद अजून दुजोरा न मिळालेला दावा आहे — तो चुकीचा असल्यास प्रशासकाकडे तक्रार करा, '
                        .'प्रशासक ती नोंद रद्द केल्यावर तुम्हाला नोंद करता येईल.')
            );
        }
    }

    /**
     * The `marriage` rung already standing on this engagement, or null. Read outside the write
     * transaction only to decide WHICH gate applies; the race is closed by the unique index on
     * `stage_event_id` and by `claimStage()`'s own locked duplicate check.
     */
    private function existingMarriageRung(SuchakCollaborationRequest $collaboration): ?SuchakCollaborationStageEvent
    {
        /** @var SuchakCollaborationStageEvent|null $event */
        $event = SuchakCollaborationStageEvent::query()
            ->where('collaboration_request_id', $collaboration->id)
            ->where('stage_key', SuchakMarriageOutcome::EVIDENCE_STAGE)
            ->first();

        return $event;
    }

    /**
     * Filed under the CUSTOMER-OWNING Suchak, not under whoever pressed the button: this row is
     * about whose customer married and whose success fee just became attributable, and the
     * recorder travels in the metadata. Same reasoning as
     * `ACTION_MARKETPLACE_PROPOSAL_RECEIVED`, where the requester is the helper.
     */
    private function recordActivity(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $account,
        User $actor,
        SuchakMarriageOutcome $outcome,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $collaboration->customerOwnerSuchakAccountId(),
            'actor_user_id' => $actor->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_MARRIAGE_OUTCOME_RECORDED,
            'target_type' => 'suchak_marriage_outcome',
            'target_id' => $outcome->id,
            'matrimony_profile_id' => $outcome->married_matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => [
                'context' => 'marriage_outcome_recorded',
                // The wedding, and the report — named apart so the trail cannot conflate them.
                'married_on' => $outcome->married_on->toDateString(),
                'reported_at' => now()->toIso8601String(),
                'collaboration_request_id' => (int) $collaboration->id,
                'customer_agreement_id' => (int) $outcome->customer_agreement_id,
                'stage_event_id' => (int) $outcome->stage_event_id,
                'recorded_by_suchak_account_id' => (int) $account->id,
                'customer_owner_suchak_account_id' => $collaboration->customerOwnerSuchakAccountId(),
                'helping_suchak_account_id' => $collaboration->helpingSuchakAccountId(),
                'married_matrimony_profile_id' => (int) $outcome->married_matrimony_profile_id,
                'spouse_matrimony_profile_id' => (int) $outcome->spouse_matrimony_profile_id,
                // D23 again: a Suchak's claim is a claim. Nothing here verifies the wedding.
                'customer_confirmed' => $outcome->isConfirmed(),
            ],
        ]);
    }

    /**
     * Filed under the SAME Suchak the recording was filed under — the customer-owning side — so the
     * withdrawal sits next to the claim it withdraws in one account's trail rather than under the
     * admin, who has no Suchak account at all. The admin is the actor, in the actor columns.
     */
    private function recordVoidActivity(
        SuchakMarriageOutcome $outcome,
        User $admin,
        AdminAuditLog $auditLog,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $outcome->loadMissing('collaborationRequest');
        $collaboration = $outcome->collaborationRequest;

        $this->activityLogger->record([
            'suchak_account_id' => $collaboration?->customerOwnerSuchakAccountId(),
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => SuchakActivityLog::ACTION_MARRIAGE_OUTCOME_VOIDED,
            'target_type' => 'suchak_marriage_outcome',
            'target_id' => $outcome->id,
            'matrimony_profile_id' => $outcome->married_matrimony_profile_id,
            'admin_audit_log_id' => $auditLog->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => [
                'context' => 'marriage_outcome_voided',
                // The claim being withdrawn, spelled out so the trail reads without the row.
                'married_on' => $outcome->married_on->toDateString(),
                'voided_at' => $outcome->voided_at?->toIso8601String(),
                'void_reason' => $outcome->void_reason,
                'collaboration_request_id' => (int) $outcome->collaboration_request_id,
                'customer_agreement_id' => (int) $outcome->customer_agreement_id,
                'stage_event_id' => (int) $outcome->stage_event_id,
                'married_matrimony_profile_id' => (int) $outcome->married_matrimony_profile_id,
                'spouse_matrimony_profile_id' => (int) $outcome->spouse_matrimony_profile_id,
                // Stated out loud: this door never opens on a confirmed claim, so nothing that had
                // released a tranche can reach here.
                'customer_confirmed' => false,
            ],
        ]);
    }
}
