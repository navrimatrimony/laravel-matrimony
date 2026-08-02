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
 * Blueprint §6.2 — the marriage, and the engagement credited with it.
 *
 * "When a marriage is recorded, ONE ROW must name the engagement credited with the introduction,
 * referencing the agreement revision in force. Without it the largest sum in the system has no
 * owner and the first success becomes the first lawsuit."
 *
 * This is that row, and it is also the first record anywhere in this codebase that a candidate
 * married at all. See the migration docblock for what was checked and rejected first
 * (`SuchakPipeline::STATUS_CONVERTED` with no writer, `lifecycle_state`, `profile_marriages`,
 * `homepage_success_stories`, and a `stage_key = marriage` event, which is a claim on an
 * engagement rather than an outcome on a person).
 *
 * ── THE TWO CLOCKS, WHICH ARE NOT THE SAME CLOCK ─────────────────────────────────────────────
 *
 *   `married_on`                      the WEDDING. A day. Supplied by whoever records it.
 *   `created_at`                      when this row was written.
 *   `stageEvent->claimed_at`          when a Suchak reported it.
 *   `stageEvent->confirmed_at`        when the family (or an admin) confirmed the report.
 *
 * Only the first is the marriage. M3 keys the cross-Suchak share on "a fixed number of days after a
 * recorded Marriage", and running that clock from any of the other three would pay a helper later
 * the longer the claim was suppressed — the precise thing M3's own sentence forbids.
 *
 * ── CONFIRMATION IS READ, NEVER COPIED ───────────────────────────────────────────────────────
 *
 * This row carries no `confirmed_at` of its own. Whether the family confirmed is
 * `$outcome->stageEvent->isSettled()` and nothing else. Two consequences, both intended:
 *
 *  - D26's claim → confirm pattern keeps ONE home. A copy here would be a second answer to
 *    "did the customer agree", free to disagree with the first.
 *  - The row EXISTS from the moment the marriage is claimed, unconfirmed. That is deliberate and
 *    is the M3/M4 split: M4 gates the CUSTOMER's fee on the customer's confirmation, M3 gates the
 *    cross-Suchak share on the recorded marriage plus elapsed days, explicitly so that silence
 *    cannot kill a helper's share. A row that only appeared on confirmation would hand every
 *    customer-owning Suchak a way to erase a helper's obligation by never confirming — and for a
 *    family with no login (§2) nobody CAN confirm, so the obligation would never exist at all.
 *
 * ── LIVE BY DEFAULT, AND WHY THAT IS A GLOBAL SCOPE ──────────────────────────────────────────
 *
 * An UNCONFIRMED claim can be set aside by an admin ({@see SuchakMarriageOutcomeService::voidClaim}
 * — see the migration for why a first-write-wins index on an unconfirmed claim was the wrong
 * shape). A set-aside row is never deleted, so it stays queryable — and every existing reader of
 * this table asks a question about the marriage that COUNTS, not about every claim ever typed.
 *
 * The scope is global rather than a `->live()` each reader must remember precisely because the
 * readers are elsewhere: `SuchakCrossSuchakObligationService` asks
 * `where('collaboration_request_id', …)->first()`, and on an engagement that was corrected and
 * re-recorded an unscoped `first()` would hand M3's clock the row an admin had already voided. One
 * default, in the one place, instead of a rule every future reader has to be told. `includingVoided()`
 * is the deliberate opt-out, and the correction door is its only caller.
 */
class SuchakMarriageOutcome extends Model
{
    use HasFactory;

    /**
     * The one ladder rung that evidences a marriage. Read from the ladder, never spelled again:
     * `SuchakCollaborationStageEvent::STAGE_LADDER` is the single vocabulary and a second literal
     * `'marriage'` anywhere would be free to drift off it.
     */
    public const EVIDENCE_STAGE = SuchakCollaborationStageEvent::STAGE_MARRIAGE;

    /**
     * M3, the "or" half: *"A share falls due when the customer has paid — OR a fixed number of days
     * after a recorded Marriage, whichever is earlier."*
     *
     * The blueprint says "a fixed number" and does not name it. THIRTY DAYS is that number and this
     * constant is its only home — nothing else may re-type it. It is a product figure, not a
     * derivation, so moving it is a product decision and a one-line change here; every reader
     * (payout gates, owed-vs-paid, a Suchak's card) must come through {@see shareFallsDueAt()}.
     *
     * Thirty rather than seven because a wedding is not a meeting: §7.2's seven-day silence window
     * answers "did this event happen", which is contestable within a week, while this window is
     * simply how long a customer-owning Suchak may hold a helper's share after an event both
     * families attended. It is not a dispute window and must never be read as one.
     */
    public const SHARE_DUE_DAYS_AFTER_MARRIAGE = 30;

    /**
     * The name of the live-rows-only global scope, so `withoutGlobalScope()` is never spelled with
     * a string literal that could drift from the registration below.
     */
    public const SCOPE_LIVE = 'live_marriage_outcome';

    protected $table = 'suchak_marriage_outcomes';

    protected $fillable = [
        'collaboration_request_id',
        'customer_agreement_id',
        'stage_event_id',
        'married_matrimony_profile_id',
        'spouse_matrimony_profile_id',
        'married_on',
    ];

    protected $casts = [
        'married_on' => 'date',
        'voided_at' => 'datetime',
    ];

    /**
     * The invariants of the ROW, on `saving`, so they hold for every writer that ever exists —
     * including one added later that forgets the service.
     *
     * They are what makes the two candidate columns safe to keep: they exist only to carry the
     * uniqueness a derived value cannot carry (see the migration), and a `saving` guard that
     * refuses any pair disagreeing with the engagement means they can never become a second,
     * drifting answer to "who is on this engagement".
     */
    protected static function booted(): void
    {
        static::addGlobalScope(self::SCOPE_LIVE, function (Builder $query): void {
            $query->where($query->getModel()->getTable().'.void_seq', 0);
        });

        static::saving(function (self $outcome): void {
            $outcome->assertVoidStateIsCoherent();
            $outcome->assertMatchesItsEngagement();
        });
    }

    /**
     * Every attribution row this platform holds, INCLUDING the ones an admin set aside. The
     * correction door is the only caller: a set-aside claim is evidence of what was claimed and by
     * whom, and it stays readable — it simply stops counting.
     *
     * @return Builder<self>
     */
    public static function includingVoided(): Builder
    {
        return self::query()->withoutGlobalScope(self::SCOPE_LIVE);
    }

    /**
     * Set aside by an admin, and therefore no longer the attribution of anything.
     */
    public function isVoided(): bool
    {
        return (int) $this->void_seq !== 0;
    }

    /**
     * `void_seq` is what the four unique indexes read; `voided_at` is what a human reads. They are
     * one fact in two forms, so a row where they disagree is refused rather than stored — otherwise
     * a writer could free the candidate indexes while the row still looked live, or the reverse.
     */
    public function assertVoidStateIsCoherent(): void
    {
        $seq = (int) ($this->void_seq ?? 0);
        $hasTimestamp = $this->voided_at !== null;

        if (($seq !== 0) !== $hasTimestamp) {
            throw new InvalidArgumentException(
                'A marriage outcome must be either live or voided in both `void_seq` and `voided_at`.'
            );
        }

        if ($seq !== 0 && $seq !== (int) $this->id) {
            throw new InvalidArgumentException(
                'A voided marriage outcome must carry its own id as `void_seq`.'
            );
        }
    }

    /**
     * Four things must agree, and each of them has already gone wrong somewhere in this domain:
     *
     *  1. The engagement exists and names a customer agreement revision. `customer_agreement_id` on
     *     `suchak_commission_agreements` is nullable and usually null; a §6.2 row whose terms are
     *     unnamed is the row with nothing to attribute.
     *  2. The revision named here IS the one on the engagement — not another revision of the same
     *     customer, and not another customer's.
     *  3. The two profiles are the engagement's two candidates, by ROLE and not by direction. On a
     *     marketplace engagement the responder is the requester, so reading
     *     `requesting_matrimony_profile_id` as "the customer's candidate" is wrong exactly when the
     *     money is largest.
     *  4. The evidence is a `marriage` rung ON THIS ENGAGEMENT. Attributing one engagement's
     *     marriage to another engagement's row is the whole failure §6.2 exists to prevent.
     */
    public function assertMatchesItsEngagement(): void
    {
        /** @var SuchakCollaborationRequest|null $collaboration */
        $collaboration = SuchakCollaborationRequest::query()
            ->with('commissionAgreement')
            ->find($this->collaboration_request_id);

        if ($collaboration === null) {
            throw new InvalidArgumentException('A marriage outcome must name the engagement credited with it.');
        }

        $agreementId = $collaboration->commissionAgreement?->customer_agreement_id;
        if ($agreementId === null) {
            throw new InvalidArgumentException(
                'या सहकार्यात ग्राहक करार जोडलेला नाही, त्यामुळे विवाहाचे श्रेय कोणत्या अटींखाली आहे हे सांगता येत नाही.'
            );
        }

        if ((int) $agreementId !== (int) $this->customer_agreement_id) {
            throw new InvalidArgumentException(
                'A marriage outcome must name the customer agreement revision in force on its engagement.'
            );
        }

        if ((int) $this->married_matrimony_profile_id !== (int) $collaboration->customerOwnerMatrimonyProfileId()
            || (int) $this->spouse_matrimony_profile_id !== (int) $collaboration->helpingMatrimonyProfileId()) {
            throw new InvalidArgumentException(
                'A marriage outcome must name the two candidates of its own engagement, customer side first.'
            );
        }

        /** @var SuchakCollaborationStageEvent|null $event */
        $event = SuchakCollaborationStageEvent::query()->find($this->stage_event_id);
        if ($event === null
            || (int) $event->collaboration_request_id !== (int) $collaboration->id
            || $event->stage_key !== self::EVIDENCE_STAGE) {
            throw new InvalidArgumentException(
                'A marriage outcome must be evidenced by the "'
                .SuchakCollaborationStageEvent::stageLabel(self::EVIDENCE_STAGE)
                .'" rung of its own engagement.'
            );
        }
    }

    /**
     * M3's deadline, by ARITHMETIC over the wedding date — never a stored column and never a job.
     *
     * Production may never invoke `schedule:run` and two queues have had no worker since
     * 2026-06-17, so nothing that decides money may wait for a timer. This is the same discipline
     * `SuchakVisitConfirmation::isClaimLapsed()` carries: the terminal fact (`married_on`, written
     * synchronously by the act that records the marriage) plus arithmetic that is correct on a
     * production where nothing has ever swept.
     */
    public function shareFallsDueAt(): Carbon
    {
        return $this->married_on->copy()->addDays(self::SHARE_DUE_DAYS_AFTER_MARRIAGE)->endOfDay();
    }

    /**
     * M3's "whichever is earlier", the elapsed-days half only. Whether the CUSTOMER has paid is the
     * payment ledger's question and is deliberately not answered here — this class owns the
     * marriage, not the money.
     */
    public function isShareDueByElapsedDays(?Carbon $at = null): bool
    {
        return $this->shareFallsDueAt()->lessThanOrEqualTo($at ?? now());
    }

    /**
     * Did the family (or an admin standing in) confirm the claim this row rests on? Read through
     * the stage event, which owns the answer — never copied onto this row.
     */
    public function isConfirmed(): bool
    {
        return $this->stageEvent?->confirmed_at !== null;
    }

    /**
     * Every marriage this platform has recorded for one candidate, whichever side of the engagement
     * they sat on. Both columns, because a wedding produces ONE row and the helping side's
     * candidate is named by `spouse_matrimony_profile_id` — asking only the first column would
     * report half of every marriage as "never married".
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCandidate(Builder $query, int $matrimonyProfileId): Builder
    {
        return $query->where(function (Builder $either) use ($matrimonyProfileId): void {
            $either
                ->where('married_matrimony_profile_id', $matrimonyProfileId)
                ->orWhere('spouse_matrimony_profile_id', $matrimonyProfileId);
        });
    }

    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(SuchakCollaborationRequest::class, 'collaboration_request_id');
    }

    public function customerAgreement(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerAgreement::class, 'customer_agreement_id');
    }

    public function stageEvent(): BelongsTo
    {
        return $this->belongsTo(SuchakCollaborationStageEvent::class, 'stage_event_id');
    }

    /**
     * The admin who set this claim aside, on the rows where anybody did.
     */
    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function marriedMatrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'married_matrimony_profile_id');
    }

    public function spouseMatrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'spouse_matrimony_profile_id');
    }

    /**
     * Undeletable, like every other evidentiary row in this domain (stage events, commission
     * agreements, visit confirmations). A marriage that produced an obligation cannot be made never
     * to have happened; a correction is a new fact recorded beside it, never an erasure.
     */
    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak marriage outcome records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak marriage outcome records cannot be deleted.');
    }
}
