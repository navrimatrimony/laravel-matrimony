<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakDispute;
use App\Models\SuchakGrowthRewardRule;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPipeline;
use App\Models\SuchakPipelineEvent;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakPayoutHold;
use App\Models\SuchakVisitConfirmation;
use App\Models\SuchakVisitConfirmationEvent;
use App\Models\User;
use App\Notifications\SuchakMeetingCompletionMarkedNotification;
use App\Services\AuditLogService;
use App\Support\MoneyFormat;
use App\Support\SafeNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakVisitConfirmationService
{
    /**
     * How a qualified visit payout got its figure. Recorded on the payout's
     * visit event and admin audit row so the answer survives the screen that
     * produced it — see {@see self::boundPayoutAmount()}.
     */
    public const PAYOUT_AMOUNT_SOURCE_PLATFORM_RULE = 'platform_visit_reward_rule';

    public const PAYOUT_AMOUNT_SOURCE_TYPED_UNDER_CEILING = 'admin_typed_under_platform_ceiling';

    public function __construct(
        private readonly SuchakAccessService $accessService,
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakPolicyService $policyService,
        private readonly SuchakPlatformPayoutService $platformPayoutService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function scheduleVisit(
        SuchakPipeline $pipeline,
        User $suchakUser,
        array $attributes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakVisitConfirmation {
        return DB::transaction(function () use ($pipeline, $suchakUser, $attributes, $ipAddress, $userAgent): SuchakVisitConfirmation {
            $lockedPipeline = $this->lockedPipeline((int) $pipeline->id);

            $this->assertOwnerCanManagePipeline($lockedPipeline, $suchakUser);
            $this->assertOpenPipeline($lockedPipeline);

            // D24 — a pair may meet again, and again, at the same rate. What is
            // refused is a SECOND meeting stacked on one that is still in flight:
            // scheduled, completed-but-unconfirmed, or disputed. M4/M5 are the
            // reason — no fee falls due without the customer's confirmation, and
            // arranging the next meeting before that one is answered would let a
            // Suchak run up unapproved meetings (blueprint 9a A4).
            $openVisit = SuchakVisitConfirmation::query()
                ->where('pipeline_id', $lockedPipeline->id)
                ->whereIn('visit_status', SuchakVisitConfirmation::OPEN_STATUSES)
                ->lockForUpdate()
                ->first();

            if ($openVisit instanceof SuchakVisitConfirmation) {
                throw new InvalidArgumentException('A Suchak meeting for this pipeline is still open; close it before scheduling the next one.');
            }

            // Assigned under the pipeline row lock taken above, so two concurrent
            // schedules for the same pipeline are serialized here rather than
            // racing. `unique(pipeline_id, meeting_sequence)` is the backstop that
            // makes the guarantee the database's, not this method's.
            $nextSequence = 1 + (int) SuchakVisitConfirmation::query()
                ->where('pipeline_id', $lockedPipeline->id)
                ->lockForUpdate()
                ->max('meeting_sequence');

            $policyMode = $this->policyService->visitConfirmationPolicyMode();
            $paymentContext = $this->resolvePlatformPaymentContext($lockedPipeline, $attributes['payment_context_id'] ?? null);
            $scheduleNote = $this->privateSafeText($attributes['schedule_note'] ?? null, 1000);
            $scheduledFor = $this->nullableDateTime($attributes['scheduled_for'] ?? null, 'Suchak visit scheduled date is invalid.');
            $meetingMode = $this->meetingMode($attributes['meeting_mode'] ?? null);
            $helperSuchakAccountId = $this->helperSuchakAccountId($attributes['helper_suchak_account_id'] ?? null, $lockedPipeline);
            $customerContextId = $paymentContext?->customer_context_id === null
                ? $this->pipelineCustomerContextId($lockedPipeline)
                : (int) $paymentContext->customer_context_id;
            $quote = $this->meetingQuote(
                (int) $lockedPipeline->selected_suchak_account_id,
                $customerContextId,
                $meetingMode,
                $attributes['customer_agreement_id'] ?? null,
            );

            // M4 — no fee falls due without the customer's confirmation. That is
            // a rule about MONEY, so a policy mode cannot waive the customer out
            // of a meeting somebody will be billed for. POLICY_ADMIN_ONLY still
            // does exactly what it says on an unpriced meeting; on a priced one
            // it now buys the admin's confirmation IN ADDITION to the family's,
            // never INSTEAD of it. Without this, an admin alone flipped the row
            // to `confirmed`, assertEligibleForPayout() passed, and money moved
            // with the family never asked — M5 says silence opens a dispute,
            // never an automatic yes.
            $feeBearing = $quote['fee_amount'] !== null && (float) $quote['fee_amount'] > 0;
            $waivesUserConfirmation = $policyMode === SuchakVisitConfirmation::POLICY_ADMIN_ONLY && ! $feeBearing;

            $visit = SuchakVisitConfirmation::query()->create([
                'pipeline_id' => $lockedPipeline->id,
                'suchak_account_id' => $lockedPipeline->selected_suchak_account_id,
                'helper_suchak_account_id' => $helperSuchakAccountId,
                'request_id' => $lockedPipeline->request_id,
                'representation_id' => $lockedPipeline->representation_id,
                'target_matrimony_profile_id' => $lockedPipeline->target_matrimony_profile_id,
                'requesting_matrimony_profile_id' => $lockedPipeline->requesting_matrimony_profile_id,
                'payment_context_id' => $paymentContext?->id,
                'customer_context_id' => $customerContextId,
                'customer_agreement_id' => $quote['customer_agreement_id'],
                'visit_status' => SuchakVisitConfirmation::STATUS_SCHEDULED,
                'confirmation_policy_mode' => $policyMode,
                'meeting_sequence' => $nextSequence,
                'meeting_mode' => $meetingMode,
                'fee_amount' => $quote['fee_amount'],
                'fee_currency' => $quote['fee_currency'],
                'scheduled_for' => $scheduledFor,
                'scheduled_by_user_id' => $suchakUser->id,
                'scheduled_at' => now(),
                'schedule_note' => $scheduleNote,
                'user_confirmation_status' => $waivesUserConfirmation
                    ? SuchakVisitConfirmation::CONFIRMATION_NOT_REQUIRED
                    : SuchakVisitConfirmation::CONFIRMATION_PENDING,
                'admin_confirmation_status' => $policyMode === SuchakVisitConfirmation::POLICY_USER_ONLY
                    ? SuchakVisitConfirmation::CONFIRMATION_NOT_REQUIRED
                    : SuchakVisitConfirmation::CONFIRMATION_PENDING,
            ]);

            $fresh = $visit->fresh($this->relations());
            $this->recordVisitEvent(
                $fresh,
                SuchakVisitConfirmationEvent::EVENT_SCHEDULED,
                SuchakVisitConfirmationEvent::ACTOR_SUCHAK,
                $suchakUser,
                null,
                $fresh->visit_status,
                $scheduleNote,
                ['policy_mode' => $fresh->confirmation_policy_mode],
            );
            $this->recordPipelineEvent($fresh->pipeline, SuchakPipelineEvent::EVENT_MEETING_SCHEDULED, SuchakPipelineEvent::ACTOR_SUCHAK, $suchakUser);
            $this->recordActivity(
                $fresh,
                $suchakUser,
                SuchakActivityLog::ACTOR_SUCHAK,
                SuchakActivityLog::ACTION_VISIT_SCHEDULED,
                'visit_scheduled',
                $ipAddress,
                $userAgent,
            );

            return $fresh->fresh($this->relations());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function markSuchakCompleted(
        SuchakVisitConfirmation $visit,
        User $suchakUser,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakVisitConfirmation {
        $fresh = DB::transaction(function () use ($visit, $suchakUser, $attributes, $ipAddress, $userAgent): SuchakVisitConfirmation {
            $locked = $this->lockedVisit($visit);
            $this->assertOwnerCanManageVisit($locked, $suchakUser);
            $this->assertNotDisputedOrPayoutQualified($locked);

            if ($locked->suchak_completion_status === SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED) {
                throw new InvalidArgumentException('Suchak visit completion is already marked.');
            }

            $note = $this->requiredPrivateSafeText($attributes['completion_note'] ?? null, 'Suchak visit completion note is required.', 1000);
            $fromStatus = $locked->visit_status;
            $locked->forceFill([
                'suchak_completion_status' => SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED,
                'suchak_completed_by_user_id' => $suchakUser->id,
                'suchak_completed_at' => now(),
                'suchak_completion_note' => $note,
                'visit_status' => SuchakVisitConfirmation::STATUS_COMPLETED,
            ])->save();

            $fresh = $locked->fresh($this->relations());
            $this->recordVisitEvent(
                $fresh,
                SuchakVisitConfirmationEvent::EVENT_SUCHAK_COMPLETED,
                SuchakVisitConfirmationEvent::ACTOR_SUCHAK,
                $suchakUser,
                $fromStatus,
                $fresh->visit_status,
                $note,
            );
            $this->recordPipelineEvent($fresh->pipeline, SuchakPipelineEvent::EVENT_MEETING_COMPLETED, SuchakPipelineEvent::ACTOR_SUCHAK, $suchakUser);
            $this->recordActivity(
                $fresh,
                $suchakUser,
                SuchakActivityLog::ACTOR_SUCHAK,
                SuchakActivityLog::ACTION_VISIT_COMPLETION_MARKED,
                'visit_completion_marked',
                $ipAddress,
                $userAgent,
            );

            return $fresh->fresh($this->relations());
        });

        // U8: fire after the visit_completion_marked trail is durable (RT-11).
        $this->notifyCustomerMeetingCompletionMarked($fresh);

        return $fresh;
    }

    /**
     * Call off a meeting that has not happened.
     *
     * Phase 1a made `scheduled` an OPEN status, so a pair may not arrange their
     * next meeting while one is still in flight. Without a way out, the first
     * meeting nobody turns up to blocks that pair FOREVER: it can never be
     * completed (it did not happen), so it can never be confirmed, so the guard
     * in scheduleVisit() refuses every later meeting. `cancelled` is the way out,
     * and it is deliberately NOT an open status.
     *
     * WHO. The Suchak who arranged the meeting, or an admin. Not the member: M5
     * gives the family `dispute` for a meeting they say went wrong, and a
     * cancellation is a scheduling fact the arranging side owns. A member who
     * wants a scheduled meeting called off asks the Suchak, exactly as they did
     * to arrange it.
     *
     * WHEN. Only while the row is still `scheduled`. Once the Suchak has marked
     * it completed, the claim that a fee is owed exists, and the answer to a
     * claim the family disagrees with is a dispute (M5) — not a quiet deletion
     * of the claim by the party who made it. `confirmed`, `disputed` and
     * `payout_qualified` are refused for the same reason.
     *
     * The reason and the actor are recorded on the append-only visit trail
     * rather than in new columns: `suchak_visit_confirmation_events` already
     * carries actor, note and the status transition for every other lifecycle
     * step, and a `cancelled_by_user_id` beside it would be a second home for
     * one fact. Deliberately NOT built here: the 7-day silence timer and the
     * payout lapse — that is Phase 3.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function cancelVisit(
        SuchakVisitConfirmation $visit,
        User $actor,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakVisitConfirmation {
        return DB::transaction(function () use ($visit, $actor, $attributes, $ipAddress, $userAgent): SuchakVisitConfirmation {
            $locked = $this->lockedVisit($visit);
            $actorType = $this->visitCancelActorType($locked, $actor);
            $this->assertNotDisputedOrPayoutQualified($locked);
            $this->assertCancellable($locked);

            $reason = $this->requiredPrivateSafeText($attributes['cancellation_reason'] ?? null, 'Suchak meeting cancellation reason is required.', 1000);
            $attendance = $this->requiredCancellationAttendance($attributes['attendance'] ?? null);
            $fromStatus = $locked->visit_status;
            $adminAuditLog = $actorType === SuchakActivityLog::ACTOR_ADMIN
                ? $this->writeAdminAuditLog(
                    $actor,
                    'suchak_visit_cancelled',
                    $locked,
                    $reason,
                    ['visit_status' => $fromStatus],
                    ['visit_status' => SuchakVisitConfirmation::STATUS_CANCELLED],
                )
                : null;

            // The confirmation columns are left exactly as they were. They record
            // what this meeting WOULD have needed, and rewriting them to
            // `not_required` would quietly restate history; `visit_status` alone
            // says the meeting is over, and a cancelled row can never satisfy
            // assertCompletedBeforeConfirmation() anyway.
            $locked->forceFill(['visit_status' => SuchakVisitConfirmation::STATUS_CANCELLED])->save();

            $fresh = $locked->fresh($this->relations());
            $this->recordVisitEvent(
                $fresh,
                SuchakVisitConfirmationEvent::EVENT_CANCELLED,
                $actorType === SuchakActivityLog::ACTOR_ADMIN
                    ? SuchakVisitConfirmationEvent::ACTOR_ADMIN
                    : SuchakVisitConfirmationEvent::ACTOR_SUCHAK,
                $actor,
                $fromStatus,
                $fresh->visit_status,
                $reason,
                // U7: reason + attendance live on the append-only event metadata
                // (no cancellation/attendance columns beside the visit row).
                [
                    'cancellation_reason' => $reason,
                    'attendance' => $attendance,
                ],
            );
            $this->recordActivity(
                $fresh,
                $actor,
                $actorType,
                SuchakActivityLog::ACTION_VISIT_CANCELLED,
                'visit_cancelled',
                $ipAddress,
                $userAgent,
                $adminAuditLog,
            );

            // Re-read, so the returned row carries the cancellation event it just
            // wrote: `$fresh` was loaded before recordVisitEvent() ran.
            return $fresh->fresh($this->relations());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function confirmByUser(
        SuchakVisitConfirmation $visit,
        User $user,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakVisitConfirmation {
        return DB::transaction(function () use ($visit, $user, $attributes, $ipAddress, $userAgent): SuchakVisitConfirmation {
            $locked = $this->lockedVisit($visit);
            $this->assertCustomerSideUserCanConfirm($locked, $user);
            $this->assertCompletedBeforeConfirmation($locked);
            $this->assertNotDisputedOrPayoutQualified($locked);

            // §7.2 clause 4 — before the answer is written, not after. This is the exact event
            // that used to erase the lapse: the confirmation landed, `isClaimAnswered()` went
            // true, and the claim stopped being lapsed retroactively. The family keeps the right
            // to answer late; what they no longer do is undo the 90 days by answering.
            $this->recordClaimLapseIfDue($locked);

            if ($locked->user_confirmation_status === SuchakVisitConfirmation::CONFIRMATION_NOT_REQUIRED) {
                throw new InvalidArgumentException('User confirmation is not required by the active visit confirmation policy.');
            }

            if ($locked->user_confirmation_status === SuchakVisitConfirmation::CONFIRMATION_CONFIRMED) {
                throw new InvalidArgumentException('User already confirmed this Suchak visit.');
            }

            $note = $this->requiredPrivateSafeText($attributes['confirmation_note'] ?? null, 'User visit confirmation note is required.', 1000);
            $fromStatus = $locked->visit_status;
            $locked->forceFill([
                'user_confirmation_status' => SuchakVisitConfirmation::CONFIRMATION_CONFIRMED,
                'user_confirmed_by_user_id' => $user->id,
                'user_confirmed_at' => now(),
                'user_confirmation_note' => $note,
            ])->save();

            $fresh = $this->refreshVisitStatus($locked);
            $this->recordVisitEvent(
                $fresh,
                SuchakVisitConfirmationEvent::EVENT_USER_CONFIRMED,
                SuchakVisitConfirmationEvent::ACTOR_USER,
                $user,
                $fromStatus,
                $fresh->visit_status,
                $note,
            );
            $this->recordActivity(
                $fresh,
                $user,
                SuchakActivityLog::ACTOR_USER,
                SuchakActivityLog::ACTION_VISIT_USER_CONFIRMED,
                'visit_user_confirmed',
                $ipAddress,
                $userAgent,
            );

            return $fresh->fresh($this->relations());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function confirmByAdmin(
        SuchakVisitConfirmation $visit,
        User $admin,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakVisitConfirmation {
        $this->accessService->assertAdmin($admin, 'Only admins can confirm Suchak visits.');

        return DB::transaction(function () use ($visit, $admin, $attributes, $ipAddress, $userAgent): SuchakVisitConfirmation {
            $locked = $this->lockedVisit($visit);
            $this->assertCompletedBeforeConfirmation($locked);
            $this->assertNotDisputedOrPayoutQualified($locked);

            if ($locked->admin_confirmation_status === SuchakVisitConfirmation::CONFIRMATION_NOT_REQUIRED) {
                throw new InvalidArgumentException('Admin confirmation is not required by the active visit confirmation policy.');
            }

            if ($locked->admin_confirmation_status === SuchakVisitConfirmation::CONFIRMATION_CONFIRMED) {
                throw new InvalidArgumentException('Admin already confirmed this Suchak visit.');
            }

            $note = $this->requiredPrivateSafeText($attributes['confirmation_note'] ?? null, 'Admin visit confirmation note is required.', 1000);
            $fromStatus = $locked->visit_status;
            $adminAuditLog = $this->writeAdminAuditLog(
                $admin,
                'suchak_visit_admin_confirmed',
                $locked,
                $note,
                ['visit_status' => $fromStatus, 'admin_confirmation_status' => $locked->admin_confirmation_status],
                ['admin_confirmation_status' => SuchakVisitConfirmation::CONFIRMATION_CONFIRMED],
            );
            $locked->forceFill([
                'admin_confirmation_status' => SuchakVisitConfirmation::CONFIRMATION_CONFIRMED,
                'admin_confirmed_by_user_id' => $admin->id,
                'admin_confirmed_at' => now(),
                'admin_confirmation_note' => $note,
            ])->save();

            $fresh = $this->refreshVisitStatus($locked);
            $this->recordVisitEvent(
                $fresh,
                SuchakVisitConfirmationEvent::EVENT_ADMIN_CONFIRMED,
                SuchakVisitConfirmationEvent::ACTOR_ADMIN,
                $admin,
                $fromStatus,
                $fresh->visit_status,
                $note,
            );
            $this->recordActivity(
                $fresh,
                $admin,
                SuchakActivityLog::ACTOR_ADMIN,
                SuchakActivityLog::ACTION_VISIT_ADMIN_CONFIRMED,
                'visit_admin_confirmed',
                $ipAddress,
                $userAgent,
                $adminAuditLog,
            );

            return $fresh->fresh($this->relations());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function disputeVisit(
        SuchakVisitConfirmation $visit,
        User $actor,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakVisitConfirmation {
        return DB::transaction(function () use ($visit, $actor, $attributes, $ipAddress, $userAgent): SuchakVisitConfirmation {
            $locked = $this->lockedVisit($visit);
            $actorType = $this->visitDisputeActorType($locked, $actor);
            $this->assertNotPayoutQualified($locked);

            if ($locked->hasOpenDispute() || $locked->visit_status === SuchakVisitConfirmation::STATUS_DISPUTED) {
                throw new InvalidArgumentException('Suchak visit confirmation is already disputed.');
            }

            // §7.2 clause 4 — a closed dispute is "never revivable". Unfreezing a
            // settled meeting (the fix above) would otherwise hand back the
            // permanent veto through the back door: contest, lose, contest again.
            // One meeting gets one contest, whichever way it went.
            if ($locked->refund_review_status !== SuchakVisitConfirmation::REFUND_NOT_REQUESTED) {
                throw new InvalidArgumentException('This Suchak visit was already disputed once and settled; a settled dispute cannot be reopened.');
            }

            // §7.2 clause 4 — a contest is an answer too, so it gets the same treatment as a late
            // confirmation: the lapse is written down first and survives it.
            $this->recordClaimLapseIfDue($locked);

            $reason = $this->requiredPrivateSafeText($attributes['dispute_reason'] ?? null, 'Suchak visit dispute reason is required.', 1000);
            $adminAuditLog = $actorType === SuchakActivityLog::ACTOR_ADMIN
                ? $this->writeAdminAuditLog(
                    $actor,
                    'suchak_visit_disputed',
                    $locked,
                    $reason,
                    ['visit_status' => $locked->visit_status],
                    ['visit_status' => SuchakVisitConfirmation::STATUS_DISPUTED],
                )
                : null;

            return $this->applyVisitDispute(
                $locked,
                $actorType,
                $actor,
                $reason,
                SuchakDispute::PRIORITY_HIGH,
                'Visit completion dispute recorded for structured Suchak visit confirmation #'.$locked->id.'.',
                'Visit confirmation is disputed; platform visit payout is held.',
                [],
                $adminAuditLog,
                $ipAddress,
                $userAgent,
            );
        });
    }

    /**
     * SEVEN SILENT DAYS OPEN A DISPUTE — §7.2, M4, M5, D26.
     *
     * The claim was made (the Suchak marked the meeting complete), the family did not answer
     * inside {@see SuchakVisitConfirmation::CLAIM_SILENCE_WINDOW_DAYS} days, and this is what
     * happens next. Not a zero, and not a payment: M5 is absolute, and both of the tempting
     * shortcuts are refused here by construction —
     *
     *  - the fee is NOT written off. `refund_review_status` goes to `pending_review`, which is
     *    open, not a finding, and clause 4's lapse later lands on `closed_no_finding` — also not
     *    a finding.
     *  - the fee is NOT granted. `user_confirmation_status` is left exactly as it was, PENDING.
     *    Stamping it `disputed` would be putting a refusal in the family's mouth that they never
     *    gave, exactly as writing a confirmation there on an admin's finding would put a yes in it
     *    ({@see SuchakVisitConfirmation::isComplaintDismissedByReview()}).
     *    `confirmationPolicySatisfied()` therefore still reads false, and no payout can qualify.
     *
     * THE ACTOR IS THE SYSTEM. Nobody acted; a date arrived — the actor vocabulary this domain
     * already uses for exactly that ({@see SuchakVisitConfirmationEvent::ACTOR_SYSTEM},
     * `SuchakMarketplaceChallengeService::expireDue()`). No user is fabricated: `opened_by_user_id`
     * on the dispute, `created_by_user_id` on the hold, `actor_user_id` on the trail and on the
     * activity log are all nullable and are all left null. A silence dispute is not somebody's
     * allegation and must not be recorded as one.
     *
     * PRIORITY IS NORMAL, not HIGH like a human's claim. Every unanswered meeting reaches this
     * path; if they all arrived HIGH the admin queue would be entirely HIGH inside a week and the
     * word would stop meaning anything. What gives this teeth is not the label, it is the payout
     * hold on the ARRANGING Suchak (§7.3) — the party who must chase the family for an answer is
     * the party whose money is frozen until he gets one.
     *
     * IDEMPOTENT. Re-entering on a row that already carries `claim_unanswered_since`, an open
     * dispute or a closed review returns the row untouched instead of throwing, because the two
     * callers are a daily sweep and a lazy read-path sweep that will both legitimately see the
     * same row twice.
     */
    public function openSilenceDispute(
        SuchakVisitConfirmation $visit,
        ?Carbon $at = null,
    ): SuchakVisitConfirmation {
        $at ??= now();

        return DB::transaction(function () use ($visit, $at): SuchakVisitConfirmation {
            $locked = $this->lockedVisit($visit);

            if (! $this->isSilenceDisputeDue($locked, $at)) {
                return $locked->fresh($this->relations());
            }

            $reason = 'भेट झाल्याचा दावा नोंदवला गेला आणि '
                .SuchakVisitConfirmation::CLAIM_SILENCE_WINDOW_DAYS
                .' दिवसांत कुटुंबाकडून उत्तर आले नाही. §7.2 प्रमाणे शुल्क आपोआप शून्य होत नाही आणि आपोआप देयही होत नाही — तक्रार उघडली आहे.';

            return $this->applyVisitDispute(
                $locked,
                SuchakActivityLog::ACTOR_SYSTEM,
                null,
                $reason,
                SuchakDispute::PRIORITY_NORMAL,
                'No customer answer within the '.SuchakVisitConfirmation::CLAIM_SILENCE_WINDOW_DAYS
                    .'-day confirmation window for Suchak visit confirmation #'.$locked->id.'.',
                'Meeting claim unanswered past its window; platform visit payout is held until it is answered.',
                [
                    // §7.2 clause 5 — the family's window and the originating Suchak's run in
                    // PARALLEL from delivery, so they expire together. One timestamp is therefore
                    // both "the family went silent" and "this claim now counts against the
                    // originating Suchak" (clause 3). Written once; never cleared.
                    'claim_unanswered_since' => $locked->claimSilenceDueAt(),
                ],
                null,
                null,
                null,
            );
        });
    }

    /**
     * Is this row's silence window closed, with nothing having happened since?
     *
     * Every clause is a refusal that matters:
     *  - a claim must EXIST (`claimDeliveredAt()`), and its window must have passed;
     *  - the family must not already have answered;
     *  - the money must be real. An unpriced meeting has nothing to dispute, and freezing a
     *    Suchak's payouts over a ₹0 row would be leverage applied to nothing (M4 is a rule about
     *    fees). `isFeeBearing()` is the existing owner of that question;
     *  - no dispute may already be open, and no review may already have run;
     *  - `claim_unanswered_since` must be null, which is what makes a second sweep a no-op.
     */
    private function isSilenceDisputeDue(SuchakVisitConfirmation $visit, Carbon $at): bool
    {
        $dueAt = $visit->claimSilenceDueAt();

        return $dueAt !== null
            && $dueAt->lessThanOrEqualTo($at)
            && $visit->claim_unanswered_since === null
            && $visit->isFeeBearing()
            && $visit->visit_status === SuchakVisitConfirmation::STATUS_COMPLETED
            && $visit->user_confirmation_status === SuchakVisitConfirmation::CONFIRMATION_PENDING
            && $visit->refund_review_status === SuchakVisitConfirmation::REFUND_NOT_REQUESTED
            && $visit->dispute_id === null;
    }

    /**
     * THE ONE WRITER of a visit dispute — the row, the SuchakDispute, the SuchakPayoutHold and
     * both trails, in one transaction.
     *
     * Extracted 2026-08-03 when the silence timer arrived, so the machine path and the three human
     * paths could not drift into two dialects of "a meeting is disputed". Everything that differs
     * between them is a parameter; nothing about the freeze itself is.
     *
     * Runs inside the caller's transaction and assumes the row is already locked and already
     * guarded — the guards differ per caller and belong with the caller.
     *
     * @param  array<string, mixed>  $extraColumns
     */
    private function applyVisitDispute(
        SuchakVisitConfirmation $locked,
        string $actorType,
        ?User $actor,
        string $reason,
        string $priority,
        string $evidenceSummary,
        string $holdReason,
        array $extraColumns,
        ?AdminAuditLog $adminAuditLog,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakVisitConfirmation {
        $dispute = SuchakDispute::query()->create([
            'suchak_account_id' => $locked->suchak_account_id,
            'matrimony_profile_id' => $locked->target_matrimony_profile_id,
            'representation_id' => $locked->representation_id,
            'customer_context_id' => $locked->customer_context_id,
            'payment_context_id' => $locked->payment_context_id,
            'opened_by_user_id' => $actor?->id,
            'assigned_admin_user_id' => $actorType === SuchakActivityLog::ACTOR_ADMIN ? $actor?->id : null,
            'dispute_type' => SuchakDispute::TYPE_VISIT_CONFIRMATION,
            'status' => SuchakDispute::STATUS_OPEN,
            'priority' => $priority,
            'risk_source' => SuchakDispute::RISK_SOURCE_VISIT_CONFIRMATION_DISPUTE,
            'summary' => $reason,
            'evidence_summary' => $evidenceSummary,
            'resolution_note' => null,
            'opened_at' => now(),
            'resolved_at' => null,
        ]);

        $hold = SuchakPayoutHold::query()->create([
            'suchak_dispute_id' => $dispute->id,
            'suchak_account_id' => $locked->suchak_account_id,
            'customer_context_id' => $locked->customer_context_id,
            'payment_context_id' => $locked->payment_context_id,
            'hold_scope' => SuchakPayoutHold::SCOPE_VISIT_CONFIRMATION_DISPUTE,
            'hold_status' => SuchakPayoutHold::STATUS_ACTIVE,
            'hold_reason' => $holdReason,
            'created_by_user_id' => $actor?->id,
        ]);

        $fromStatus = $locked->visit_status;
        $locked->forceFill(array_merge([
            'visit_status' => SuchakVisitConfirmation::STATUS_DISPUTED,
            // Only the FAMILY's own contest writes the family's own column. A Suchak's claim, an
            // admin's, and the silence timer's all leave it exactly as they found it.
            'user_confirmation_status' => $actorType === SuchakActivityLog::ACTOR_USER
                ? SuchakVisitConfirmation::CONFIRMATION_DISPUTED
                : $locked->user_confirmation_status,
            'dispute_id' => $dispute->id,
            'payout_hold_id' => $hold->id,
            'refund_review_status' => SuchakVisitConfirmation::REFUND_PENDING_REVIEW,
            'refund_review_note' => 'Refund/dispute review required before payout qualification.',
        ], $extraColumns))->save();

        $fresh = $locked->fresh($this->relations());
        $this->recordVisitEvent(
            $fresh,
            SuchakVisitConfirmationEvent::EVENT_DISPUTED,
            // Four actors now, so the old two-way ternary would have filed a helping Suchak's
            // claim — and the timer's — under `user`. The event trail is the only place a
            // stop-loss counter can ever learn WHO claimed.
            match ($actorType) {
                SuchakActivityLog::ACTOR_ADMIN => SuchakVisitConfirmationEvent::ACTOR_ADMIN,
                SuchakActivityLog::ACTOR_SUCHAK => SuchakVisitConfirmationEvent::ACTOR_SUCHAK,
                SuchakActivityLog::ACTOR_SYSTEM => SuchakVisitConfirmationEvent::ACTOR_SYSTEM,
                default => SuchakVisitConfirmationEvent::ACTOR_USER,
            },
            $actor,
            $fromStatus,
            $fresh->visit_status,
            $reason,
            [
                'dispute_id' => $dispute->id,
                'payout_hold_id' => $hold->id,
                'claim_unanswered_since' => $fresh->claim_unanswered_since?->toIso8601String(),
            ],
        );
        $this->recordActivity(
            $fresh,
            $actor,
            $actorType,
            SuchakActivityLog::ACTION_VISIT_DISPUTED,
            'visit_disputed',
            $ipAddress,
            $userAgent,
            $adminAuditLog,
        );

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function qualifyPayoutForVisit(
        SuchakVisitConfirmation $visit,
        User $admin,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakVisitConfirmation {
        $this->accessService->assertAdmin($admin, 'Only admins can qualify Suchak visit payouts.');

        return DB::transaction(function () use ($visit, $admin, $attributes, $ipAddress, $userAgent): SuchakVisitConfirmation {
            $locked = $this->lockedVisit($visit);
            $this->assertEligibleForPayout($locked);
            $this->assertPayoutActorAllowed($locked, $admin);

            $paymentContext = $locked->paymentContext;
            if (! $paymentContext instanceof SuchakPaymentContext) {
                throw new InvalidArgumentException('Suchak visit payout qualification requires a platform payment context.');
            }

            $this->assertPlatformPaymentContext($paymentContext, $locked->pipeline);
            $bound = $this->boundPayoutAmount($locked, $attributes);
            $amount = $bound['amount'];
            $currency = $bound['currency'];
            $note = $this->requiredPrivateSafeText($attributes['qualification_note'] ?? null, 'Suchak visit payout qualification note is required.', 1000);
            $fromStatus = $locked->visit_status;
            $singleActor = $this->isSingleActorQualification($locked, $admin);

            $payout = $this->platformPayoutService->qualifyFromPlatformEvent(
                $paymentContext,
                $admin,
                [
                    'platform_event_type' => SuchakPlatformPayout::EVENT_PLATFORM_VISIT_CONFIRMED,
                    'platform_event_key' => 'visit-confirmation-'.$locked->id,
                    'payout_reason' => SuchakPlatformPayout::REASON_PLATFORM_VISIT_REWARD,
                    'qualification_source' => SuchakPlatformPayout::SOURCE_PLATFORM_CONFIRMED_EVENT,
                    'amount' => $amount,
                    'currency' => $currency,
                    'qualification_note' => $note,
                    'payout_details' => is_array($attributes['payout_details'] ?? null) ? $attributes['payout_details'] : [],
                ],
                $ipAddress,
                $userAgent,
            );

            // WHERE THE FIGURE CAME FROM, AND WHETHER ONE PERSON DECIDED IT ALONE,
            // travel with the money on the permanent trail. Both were unanswerable
            // before: the payout recorded an amount with no source, and nothing
            // anywhere compared the qualifier against the admin who confirmed.
            $provenance = [
                'amount_source' => $bound['amount_source'],
                'reward_rule_key' => $bound['reward_rule_key'],
                'reward_rule_id' => $bound['reward_rule_id'],
                'typed_amount_ceiling' => $bound['typed_amount_ceiling'],
                'single_actor_qualification' => $singleActor,
                'admin_confirmed_by_user_id' => $locked->admin_confirmed_by_user_id,
                'payout_qualified_by_user_id' => $admin->id,
            ];

            $adminAuditLog = $this->writeAdminAuditLog(
                $admin,
                'suchak_visit_payout_qualified',
                $locked,
                $note,
                ['visit_status' => $fromStatus, 'platform_payout_id' => null],
                array_merge([
                    'visit_status' => SuchakVisitConfirmation::STATUS_PAYOUT_QUALIFIED,
                    'platform_payout_id' => $payout->id,
                    'amount' => $amount,
                    'currency' => $currency,
                ], $provenance),
            );

            $locked->forceFill([
                'platform_payout_id' => $payout->id,
                'payout_qualified_at' => now(),
                'visit_status' => SuchakVisitConfirmation::STATUS_PAYOUT_QUALIFIED,
            ])->save();

            $fresh = $locked->fresh($this->relations());
            $this->recordVisitEvent(
                $fresh,
                SuchakVisitConfirmationEvent::EVENT_PAYOUT_QUALIFIED,
                SuchakVisitConfirmationEvent::ACTOR_ADMIN,
                $admin,
                $fromStatus,
                $fresh->visit_status,
                $note,
                array_merge([
                    'platform_payout_id' => $payout->id,
                    'payout_amount' => $amount,
                    'payout_currency' => $currency,
                ], $provenance),
            );
            $this->recordActivity(
                $fresh,
                $admin,
                SuchakActivityLog::ACTOR_ADMIN,
                SuchakActivityLog::ACTION_VISIT_PAYOUT_QUALIFIED,
                'visit_payout_qualified',
                $ipAddress,
                $userAgent,
                $adminAuditLog,
            );

            return $fresh;
        });
    }

    /**
     * A dispute closed — write what that means for every meeting it froze.
     *
     * THE DOOR THIS CLOSES. `SuchakSafetyService::transitionDispute()` moved a
     * dispute to a closing status and stopped there; `refund_review_status` had
     * no writer in the whole application, so `pending_review` was terminal and
     * the meeting stayed frozen even when the case went the Suchak's way. This
     * is that writer, and it lives HERE because `suchak_visit_confirmations` has
     * exactly one owner (docs/FIELD-OWNERSHIP-MAP.md) — the safety service calls
     * in rather than reaching into the table.
     *
     * Pushed on close, not swept lazily on read: the money answer must exist the
     * moment the admin decides, and nothing else reads these rows often enough
     * to be a reliable sweep point. There is no timer and no queued job — a
     * Phase-3 timer written as a queued job would silently never fire on this
     * production (notifications/governance queues have had no worker since
     * 2026-06-17).
     *
     * Runs inside the caller's transaction. Returns how many meetings it moved.
     */
    public function settleDisputedVisits(
        SuchakDispute $dispute,
        User $admin,
        string $closingStatus,
        string $resolutionNote,
        ?AdminAuditLog $adminAuditLog = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): int {
        $this->accessService->assertAdmin($admin, 'Only admins can settle Suchak visit disputes.');

        $outcome = SuchakVisitConfirmation::DISPUTE_CLOSE_REFUND_OUTCOME[$closingStatus] ?? null;
        if ($outcome === null) {
            throw new InvalidArgumentException('Invalid Suchak dispute closing status for visit settlement.');
        }

        $note = $this->privateSafeText($resolutionNote, 1000);
        $settled = 0;

        $visitIds = SuchakVisitConfirmation::query()
            ->where('dispute_id', $dispute->id)
            ->whereNotIn('refund_review_status', SuchakVisitConfirmation::REFUND_REVIEW_CLOSED_STATUSES)
            ->orderBy('id')
            ->pluck('id');

        foreach ($visitIds as $visitId) {
            /** @var SuchakVisitConfirmation $locked */
            $locked = SuchakVisitConfirmation::query()
                ->whereKey($visitId)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-read under the lock: a concurrent close of the same dispute
            // must settle each meeting once, not twice.
            if (in_array($locked->refund_review_status, SuchakVisitConfirmation::REFUND_REVIEW_CLOSED_STATUSES, true)) {
                continue;
            }

            // §7.2 clause 4 — a finding is an answer, so the lapse is written down before it. An
            // adjudication landing on day 8 finds nothing to stamp and settles the case normally;
            // one landing on day 100 arrives after the claim already terminated, and "never
            // revivable" makes no exception for the adjudicator. The lapse sweep itself comes
            // through here too (as `closed` → `closed_no_finding`), which is how a swept claim
            // gets its fact recorded.
            $this->recordClaimLapseIfDue($locked);

            $fromStatus = $locked->visit_status;
            $locked->forceFill([
                'refund_review_status' => $outcome,
                'refund_review_note' => $note,
            ])->save();

            // `dispute_id` and `payout_hold_id` are deliberately left in place.
            // The trail has to outlive the case; what changed is whether the
            // case is still GOVERNING, and that is the review status alone.
            $fresh = $this->refreshVisitStatus($locked);

            $this->recordVisitEvent(
                $fresh,
                SuchakVisitConfirmationEvent::EVENT_DISPUTE_SETTLED,
                SuchakVisitConfirmationEvent::ACTOR_ADMIN,
                $admin,
                $fromStatus,
                $fresh->visit_status,
                $note,
                [
                    'dispute_id' => $dispute->id,
                    'dispute_status' => $closingStatus,
                    'refund_review_status' => $outcome,
                    // The money answer AFTER this settlement, not a claim that the settlement
                    // itself made the fee due. A dismissal leaves this false until the family
                    // confirms — M4.
                    'fee_payable' => $this->isFeeDueOnVisit($fresh),
                    'claim_lapsed_at' => $fresh->claim_lapsed_at?->toIso8601String(),
                ],
            );
            $this->recordActivity(
                $fresh,
                $admin,
                SuchakActivityLog::ACTOR_ADMIN,
                SuchakActivityLog::ACTION_VISIT_DISPUTE_SETTLED,
                'visit_dispute_settled',
                $ipAddress,
                $userAgent,
                $adminAuditLog,
            );

            $settled++;
        }

        return $settled;
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'pipeline',
            'suchakAccount',
            'helperSuchakAccount',
            'request',
            'representation',
            'targetMatrimonyProfile',
            'requestingMatrimonyProfile',
            'paymentContext',
            'customerContext',
            'customerAgreement',
            'platformPayout',
            'dispute',
            'payoutHold',
            'events',
        ];
    }

    /**
     * ONE lock order for every transaction in this service: `suchak_pipelines`
     * first, `suchak_visit_confirmations` second. Never the other way round.
     *
     * This is not defensive tidiness, it is the fix for a reproduced MySQL 1213.
     * scheduleVisit() takes X on the pipeline row and then X on the visit rows.
     * markSuchakCompleted() used to take X on the visit row FIRST and then reach
     * the same pipeline row implicitly: recordPipelineEvent() inserts into
     * `suchak_pipeline_events`, whose `pipeline_id` foreign key makes InnoDB take
     * a shared lock on the parent row. Opposed order on the same two rows is a
     * textbook ABBA, and a Suchak double-tapping "complete" then "schedule next"
     * hit it — DB::transaction() retries once by default, i.e. not at all, and
     * the controllers catch only InvalidArgumentException, so the user got a 500.
     *
     * Ordering is the fix rather than a retry count, because a retry only makes
     * the collision cheaper: it leaves the cycle in place, so the failure rate
     * merely drops out of sight instead of becoming impossible. No `attempts`
     * argument is added anywhere in this service for that reason.
     */
    private function lockedPipeline(int $pipelineId): SuchakPipeline
    {
        /** @var SuchakPipeline $locked */
        $locked = SuchakPipeline::query()
            ->with(['selectedSuchakAccount', 'request', 'representation'])
            ->whereKey($pipelineId)
            ->lockForUpdate()
            ->firstOrFail();

        return $locked;
    }

    private function lockedVisit(SuchakVisitConfirmation $visit): SuchakVisitConfirmation
    {
        // The pipeline first, always — see lockedPipeline(). A visit's
        // `pipeline_id` is written once at schedule time and never changes, so
        // reading it off the un-locked instance to decide WHICH row to lock is
        // safe; it is the locking order that has to be constant, not the read.
        $this->lockedPipeline((int) $visit->pipeline_id);

        /** @var SuchakVisitConfirmation $locked */
        $locked = SuchakVisitConfirmation::query()
            ->with($this->relations())
            ->whereKey($visit->id)
            ->lockForUpdate()
            ->firstOrFail();

        return $locked;
    }

    private function assertOwnerCanManagePipeline(SuchakPipeline $pipeline, User $actor): void
    {
        $account = $pipeline->selectedSuchakAccount;
        if ($account === null) {
            throw new InvalidArgumentException('Suchak visit pipeline must have a selected Suchak account.');
        }

        $this->accessService->assertOwnerCanOperate(
            $account,
            $actor,
            'Only the selected Suchak can manage this visit confirmation.',
            'Selected Suchak must be verified to manage visit confirmations.',
        );
    }

    private function assertOwnerCanManageVisit(SuchakVisitConfirmation $visit, User $actor): void
    {
        $visit->loadMissing('suchakAccount');
        $this->accessService->assertOwnerCanOperate(
            $visit->suchakAccount,
            $actor,
            'Only the selected Suchak can manage this visit confirmation.',
            'Selected Suchak must be verified to manage visit confirmations.',
        );
    }

    private function assertOpenPipeline(SuchakPipeline $pipeline): void
    {
        if (! in_array($pipeline->pipeline_status, [SuchakPipeline::STATUS_PENDING, SuchakPipeline::STATUS_CONVERTED], true)) {
            throw new InvalidArgumentException('Only open Suchak pipelines can schedule visit confirmations.');
        }
    }

    /**
     * WHICH candidate profile's own user is the CUSTOMER on this meeting — the fee-bearing side.
     *
     * Public because the HTTP door in front of confirmByUser() / disputeVisit() has to answer the
     * same question to decide whether a viewer may see the meeting at all, and two copies of "who
     * is the customer here" is exactly how the wrong family ends up confirming.
     *
     * ROLE FIRST, DIRECTION ONLY AS A FALLBACK. `customer_context_id` is written at schedule time
     * from the ARRANGING Suchak's own customer (`pipeline.selected_suchak_account_id`) — from the
     * pipeline-keyed payment context where one exists, otherwise from the pipeline's
     * representation, which owns at most one customer ({@see self::pipelineCustomerContextId()}).
     * Either way the customer context's candidate is by construction the family whose Suchak
     * arranged the meeting and whose agreement `fee_amount` was quoted from. That is the party M4
     * means by "the customer", and the party who confirms is therefore always the party who is
     * billed — the two are read off the SAME context, never off two different ones.
     *
     * `requesting_matrimony_profile_id` is a DIRECTION, and in the marketplace direction no longer
     * implies role: the Suchak answering a challenge becomes the requester (blueprint 5.2 direction
     * note), so on a marketplace meeting the requesting profile is the HELPER's candidate — the
     * other family entirely. Resolving off it named the wrong family, and the fee-bearing side
     * could not confirm at all.
     *
     * SECOND ROLE SOURCE, ADDED 2026-08-05 — the ENGAGEMENT itself, when the pipeline came from one
     * (`suchak_pipelines.collaboration_request_id`). Until an engagement could open a pipeline at
     * all, the directional fallback below was harmless: every meeting was member-born, so the
     * requesting profile WAS the member who asked. On a marketplace meeting it is the HELPER's
     * candidate, and a marketplace meeting reaches this fallback whenever the arranging Suchak's
     * own customer cannot be resolved at all — no payment context on the pipeline AND no customer
     * on its representation, i.e. a Suchak who never opened a customer record for the candidate he
     * represents. Without this the other family's own member login could
     * confirm a meeting arranged for the customer, and the row would record their answer as the
     * customer's. The engagement already names the customer-owning side as a recorded fact, so the
     * answer is read from there rather than guessed from a column that no longer means role.
     *
     * The directional fallback survives beneath it, unchanged, for member-born meetings. It is not
     * a loosening: when a meeting names neither a customer context nor an engagement, nobody is
     * being billed under an agreement through it and the requesting member remains the only party
     * the row identifies. Nothing here is widened at any step — the permitted set is always exactly
     * one profile's own user.
     */
    public function customerSideMatrimonyProfileId(SuchakVisitConfirmation $visit): ?int
    {
        $visit->loadMissing(['customerContext', 'pipeline.collaborationRequest.commissionAgreement']);

        $customerCandidateId = $visit->customerContext?->candidate_matrimony_profile_id;
        if ($customerCandidateId !== null) {
            return (int) $customerCandidateId;
        }

        $engagement = $visit->pipeline?->collaborationRequest;
        if ($engagement !== null && $engagement->hasRecordedCustomerOwner()) {
            $engagementCustomerId = $engagement->customerOwnerMatrimonyProfileId();
            if ($engagementCustomerId !== null) {
                return $engagementCustomerId;
            }
        }

        return $visit->requesting_matrimony_profile_id === null
            ? null
            : (int) $visit->requesting_matrimony_profile_id;
    }

    /**
     * Exactly one party may confirm or dispute a meeting, and it is the one who pays for it.
     * Re-pointed from direction to role — see customerSideMatrimonyProfileId(). Nothing was widened:
     * the set is still a single profile's own user.
     */
    private function assertCustomerSideUserCanConfirm(SuchakVisitConfirmation $visit, User $user): void
    {
        $profileId = $this->customerSideMatrimonyProfileId($visit);
        if ($profileId === null) {
            throw new InvalidArgumentException('This Suchak meeting names no customer, so nobody can confirm it.');
        }

        /** @var MatrimonyProfile|null $profile */
        $profile = MatrimonyProfile::query()->find($profileId);
        if (! $profile instanceof MatrimonyProfile || (int) $profile->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('Only the customer this meeting was arranged for can confirm it.');
        }
    }

    /**
     * The arranging Suchak or an admin — see cancelVisit() for why not the member.
     */
    private function visitCancelActorType(SuchakVisitConfirmation $visit, User $actor): string
    {
        if ($this->accessService->isAdmin($actor)) {
            return SuchakActivityLog::ACTOR_ADMIN;
        }

        $this->assertOwnerCanManageVisit($visit, $actor);

        return SuchakActivityLog::ACTOR_SUCHAK;
    }

    private function assertCancellable(SuchakVisitConfirmation $visit): void
    {
        if ($visit->visit_status !== SuchakVisitConfirmation::STATUS_SCHEDULED
            || $visit->suchak_completion_status === SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED) {
            throw new InvalidArgumentException('Only a meeting that has not been marked completed can be cancelled.');
        }
    }

    private function requiredCancellationAttendance(mixed $value): string
    {
        $attendance = is_string($value) ? trim($value) : '';
        if (! in_array($attendance, SuchakVisitConfirmation::ATTENDANCES, true)) {
            throw new InvalidArgumentException(
                'Suchak meeting cancellation attendance must be one of: '.implode(', ', SuchakVisitConfirmation::ATTENDANCES).'.'
            );
        }

        return $attendance;
    }

    /**
     * U8: tell the customer-side user a meeting awaits confirmation (RT-4/5/11/14).
     */
    private function notifyCustomerMeetingCompletionMarked(SuchakVisitConfirmation $visit): void
    {
        $profileId = $this->customerSideMatrimonyProfileId($visit);
        if ($profileId === null) {
            return;
        }

        /** @var MatrimonyProfile|null $profile */
        $profile = MatrimonyProfile::query()->find($profileId);
        $customer = $profile?->user;
        if (! $customer instanceof User) {
            return;
        }

        $scheduledDate = $visit->scheduled_for instanceof \Illuminate\Support\Carbon
            ? $visit->scheduled_for->toDateString()
            : (string) ($visit->scheduled_for ?? now()->toDateString());

        SafeNotifier::notify(
            $customer,
            new SuchakMeetingCompletionMarkedNotification((int) $visit->id, $scheduledDate),
        );
    }

    private function assertCompletedBeforeConfirmation(SuchakVisitConfirmation $visit): void
    {
        if ($visit->suchak_completion_status !== SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED) {
            throw new InvalidArgumentException('Suchak must mark the visit completed before confirmation.');
        }
    }

    /**
     * `dispute_id !== null` used to be the test here. It froze the row for
     * good: `SuchakSafetyService::transitionDispute()` closes a dispute and
     * never clears `dispute_id` (correctly — the trail must survive), so a
     * dispute settled IN THE SUCHAK'S FAVOUR left the meeting unchangeable and
     * unpayable forever. The question is whether a dispute is still OPEN, and
     * {@see SuchakVisitConfirmation::hasOpenDispute()} is where that is asked.
     */
    private function assertNotDisputedOrPayoutQualified(SuchakVisitConfirmation $visit): void
    {
        $this->assertNotPayoutQualified($visit);

        if ($visit->hasOpenDispute()) {
            throw new InvalidArgumentException('Disputed Suchak visit confirmations cannot be changed.');
        }

        // An upheld complaint is the end of this meeting, not a pause in it.
        // Without this a settled-against meeting sits at `cancelled` while
        // `suchak_completion_status` still reads `suchak_marked_completed`, and
        // confirmByUser()/confirmByAdmin() would happily confirm a meeting whose
        // fee was already refused.
        if ($visit->isFeeRefusedByReview()) {
            throw new InvalidArgumentException('This Suchak visit dispute was settled against the claim; the meeting is closed and cannot be changed.');
        }
    }

    private function assertNotPayoutQualified(SuchakVisitConfirmation $visit): void
    {
        if ($visit->platform_payout_id !== null || $visit->visit_status === SuchakVisitConfirmation::STATUS_PAYOUT_QUALIFIED) {
            throw new InvalidArgumentException('Payout-qualified Suchak visit confirmations cannot be changed.');
        }
    }

    private function assertEligibleForPayout(SuchakVisitConfirmation $visit): void
    {
        if ($visit->platform_payout_id !== null) {
            throw new InvalidArgumentException('Suchak visit payout is already qualified.');
        }

        if ($visit->hasOpenDispute()) {
            throw new InvalidArgumentException('Disputed Suchak visit confirmations cannot qualify platform payout.');
        }

        // Terminal, and deliberately separate from the open-dispute refusal
        // above: this one never becomes payable, whatever arrives later.
        if ($visit->isFeeRefusedByReview()) {
            throw new InvalidArgumentException('This Suchak visit dispute was settled against the claim; its fee can never qualify for platform payout.');
        }

        // §7.2 clause 4 — the claim lapsed at 90 days: "never revivable, never due". This is the
        // money boundary, and the lapse reaching it now survives a late answer:
        //
        //  - `isClaimLapsed()` reads the RECORDED FACT `claim_lapsed_at` first, so a confirmation
        //    arriving on day 99 cannot unmake it. It used to be a pure predicate over the answer
        //    columns, and a late answer therefore erased the lapse and paid the claim;
        //  - it falls back to arithmetic on `claim_unanswered_since`, so "never due" still holds
        //    on a production where `schedule:run` never fires and nothing ever stamped the row;
        //  - it sits here and not on `confirmByUser()`, so a family who finally answers on day 120
        //    is still allowed to put their answer on the record. History is not falsified to
        //    enforce a deadline; only the payout is refused.
        if ($visit->isClaimLapsed()) {
            throw new InvalidArgumentException('This Suchak meeting claim went unanswered past its lapse window; its fee can never qualify for platform payout.');
        }

        $hasActiveHold = SuchakPayoutHold::query()
            ->where('suchak_account_id', $visit->suchak_account_id)
            ->where('hold_status', SuchakPayoutHold::STATUS_ACTIVE)
            ->where(function ($query) use ($visit): void {
                $query->whereNull('customer_context_id')->whereNull('payment_context_id');

                if ($visit->customer_context_id !== null) {
                    $query->orWhere('customer_context_id', $visit->customer_context_id);
                }

                if ($visit->payment_context_id !== null) {
                    $query->orWhere('payment_context_id', $visit->payment_context_id);
                }
            })
            ->exists();

        if ($hasActiveHold) {
            throw new InvalidArgumentException('Suchak visit payout is held because an active payout hold exists.');
        }

        // M4, AND THE ONE LINE THAT USED TO BREAK IT. This read
        // `! confirmationPolicySatisfied($visit) && ! $visit->isFeeAllowedByReview()`, so a
        // dismissed complaint qualified the payout ON ITS OWN — reachable with one admin form
        // post to `POST admin/suchak/safety/disputes/{dispute}/close` carrying
        // `resolution_status = rejected`, with the customer nowhere in the transaction. M4 admits
        // no exception: *no fee falls due without the customer's confirmation*. Closing a dispute
        // is an admin deciding a dispute; it is not the customer confirming.
        //
        // The old branch existed to stop a dispute becoming a free permanent veto on the fee, and
        // that concern was real — but the answer to it is not to pay without the family. It is
        // that a dismissal UNFREEZES the meeting (see
        // {@see SuchakVisitConfirmation::isComplaintDismissedByReview()}): the hold is released,
        // the row leaves `disputed`, and `confirmByUser()` accepts a family whose column still
        // reads `disputed`. So the family that lost its contest can confirm, and their own act
        // moves the money. If they never answer, §7.2 clause 4 ends the claim at 90 days with
        // nothing owed — an outcome the blueprint states outright rather than an oversight.
        if (! $this->confirmationPolicySatisfied($visit)) {
            throw new InvalidArgumentException('Suchak visit confirmation policy is not yet satisfied.');
        }
    }

    /**
     * WHAT THE PAYOUT IS WORTH — and why the meeting's own fee is not the answer.
     *
     * `fee_amount` is the CUSTOMER-side quote the Suchak set for himself. This
     * payout is PLATFORM money paid TO that Suchak. Binding them would hand the
     * payee the platform's price list, which is the defect the empty form on
     * `admin/suchak/visits` was an interim guard against. That interim is now
     * over: `[required, numeric, min:0.01]` with no ceiling meant one admin
     * could type any figure at all, and Phase 4 puts the same shape in front of
     * an 80,000 - 1,00,000 success fee.
     *
     * TWO SOURCES, IN STRICT ORDER, AND THE ROW SAYS WHICH ONE RAN:
     *
     *  1. A PLATFORM-OWNED RULE. If a `platform_visit_confirmed` reward rule is
     *     in force, the amount and the currency ARE the rule's. Nobody types
     *     anything — exactly how `SuchakGrowthRewardService` has always sourced
     *     `reward_amount`, reusing that engine rather than adding a second one.
     *     A submitted figure that DISAGREES is refused rather than ignored: a
     *     stale form and a deliberate override look identical from here, and
     *     silence would let the second one through.
     *
     *  2. NO RULE PUBLISHED YET — the typed figure survives, under a
     *     platform-owned ceiling ({@see SuchakPolicyService::visitPayoutMaxAmount()}).
     *     Refusing outright instead would have been the tighter rule and it is
     *     the wrong trade here: it would deadlock every meeting payout on a
     *     production that has no rule row, to close a hole the ceiling already
     *     bounds. The ceiling is beaten by a rule, never applied on top of one.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{amount: string, currency: string, amount_source: string, reward_rule_key: ?string, reward_rule_id: ?int, typed_amount_ceiling: ?int}
     */
    private function boundPayoutAmount(SuchakVisitConfirmation $visit, array $attributes): array
    {
        $rule = SuchakGrowthRewardRule::visitRewardInForce();

        if ($rule instanceof SuchakGrowthRewardRule) {
            $amount = $this->requiredAmount($rule->reward_amount);
            $currency = $this->currency($rule->reward_currency);
            $submitted = $attributes['amount'] ?? null;
            $submittedCurrency = $attributes['currency'] ?? null;

            if ($submitted !== null && trim((string) $submitted) !== '' && $this->requiredAmount($submitted) !== $amount) {
                throw new InvalidArgumentException(sprintf(
                    'The platform visit reward is set by rule "%s" at %s; a different amount cannot be qualified.',
                    $rule->rule_key,
                    (string) MoneyFormat::amount($amount, $currency),
                ));
            }

            if ($submittedCurrency !== null && trim((string) $submittedCurrency) !== '' && $this->currency($submittedCurrency) !== $currency) {
                throw new InvalidArgumentException(sprintf(
                    'The platform visit reward is set by rule "%s" in %s; a different currency cannot be qualified.',
                    $rule->rule_key,
                    $currency,
                ));
            }

            return [
                'amount' => $amount,
                'currency' => $currency,
                'amount_source' => self::PAYOUT_AMOUNT_SOURCE_PLATFORM_RULE,
                'reward_rule_key' => $rule->rule_key,
                'reward_rule_id' => (int) $rule->id,
                'typed_amount_ceiling' => null,
            ];
        }

        $amount = $this->requiredAmount($attributes['amount'] ?? null);
        // Defaults to the currency the meeting was QUOTED in, not to rupees.
        // A payout for a meeting frozen in USD that silently settles as INR
        // is the same defect as rendering it with a '₹'.
        $currency = $this->currency($attributes['currency'] ?? $visit->fee_currency ?? 'INR');
        $ceiling = $this->policyService->visitPayoutMaxAmount();

        // Compared as a bare number in whatever currency was frozen. The ceiling
        // is INR-shaped and there is no conversion in this codebase, so a foreign
        // currency is held to the same figure — which errs towards refusing, and
        // refusing is the safe direction for an unpublished amount.
        if ((float) $amount > (float) $ceiling) {
            throw new InvalidArgumentException(sprintf(
                'No platform visit reward rule is in force, so a typed payout cannot exceed %s. Publish a platform visit reward rule to qualify more than that.',
                (string) MoneyFormat::amount($ceiling, $currency),
            ));
        }

        return [
            'amount' => $amount,
            'currency' => $currency,
            'amount_source' => self::PAYOUT_AMOUNT_SOURCE_TYPED_UNDER_CEILING,
            'reward_rule_key' => null,
            'reward_rule_id' => null,
            'typed_amount_ceiling' => $ceiling,
        ];
    }

    /**
     * MAY THE ADMIN WHO CONFIRMED THE MEETING ALSO QUALIFY ITS PAYOUT?
     *
     * CHOSEN: yes by default, and never silently. Four-eyes is the obvious
     * answer and it is available one policy row away
     * (`suchak_visit_payout_requires_second_admin`), but defaulting it ON would
     * deadlock this deployment outright — under the default `user_and_admin`
     * policy an admin confirmation is REQUIRED before a payout can qualify, and
     * there is one admin. The engine would refuse every meeting payout with no
     * second person in existence to unblock it.
     *
     * WHAT MAKES THAT ACCEPTABLE IS THE OTHER HALF OF THIS CHANGE, not tolerance
     * of the hole. With the amount bound to a platform-owned rule
     * ({@see self::boundPayoutAmount()}), one admin doing both steps no longer
     * gets to choose the sum — the residual risk is a fabricated confirmation,
     * not an inflated figure, and a fabricated confirmation is what the
     * customer-side confirmation and the 7.2 dispute clock already answer.
     *
     * THE COMPENSATING RECORD, so single-actor is auditable rather than
     * invisible: every qualification writes `single_actor_qualification`,
     * `admin_confirmed_by_user_id` and `payout_qualified_by_user_id` into BOTH
     * the append-only `suchak_visit_confirmation_events` metadata and the
     * `admin_audit_logs` new-values. `true` is the finding; `false` is the proof
     * the check ran, which is why it is written either way. The meetings screen
     * renders the flag on the row, so it is not a fact that only exists if
     * somebody thinks to query for it.
     *
     * REJECTED: "require a second admin only when a second admin exists". It
     * reads as free safety and is worse than nothing — the control disappears
     * exactly when a deployment shrinks to one person, and no record says it
     * did.
     */
    private function assertPayoutActorAllowed(SuchakVisitConfirmation $visit, User $admin): void
    {
        if (! $this->policyService->visitPayoutRequiresSecondAdmin()) {
            return;
        }

        if ($this->isSingleActorQualification($visit, $admin)) {
            throw new InvalidArgumentException('This meeting was admin-confirmed by you; platform policy requires a different admin to qualify its payout.');
        }
    }

    private function isSingleActorQualification(SuchakVisitConfirmation $visit, User $admin): bool
    {
        return $visit->admin_confirmed_by_user_id !== null
            && (int) $visit->admin_confirmed_by_user_id === (int) $admin->id;
    }

    /**
     * The money answer for ONE meeting, as a boolean.
     *
     * Mirrors the meeting-side half of {@see assertEligibleForPayout()} — deliberately the same
     * four questions in the same order, four lines above, because the guard has to name WHICH
     * refusal it hit and a boolean cannot. What it excludes is the payout-side conditions (a
     * payout already qualified, an active hold elsewhere on the account), which are not facts
     * about this meeting.
     *
     * It exists so the settlement audit row reports the truth. That row used to record
     * `fee_payable => isFeeAllowedByReview()`, which asserted a fee was due the moment a dispute
     * was dismissed — the same false equation as the guard above, written into the permanent
     * trail.
     */
    private function isFeeDueOnVisit(SuchakVisitConfirmation $visit): bool
    {
        return ! $visit->hasOpenDispute()
            && ! $visit->isFeeRefusedByReview()
            && ! $visit->isClaimLapsed()
            && $this->confirmationPolicySatisfied($visit);
    }

    /**
     * §7.2 CLAUSE 4 — WRITE THE LAPSE DOWN BEFORE ANYTHING CAN ERASE IT.
     *
     * The lapse is a thing that happened, not a shape the row currently has. Every path that can
     * record an ANSWER calls this first, so the fact is on the row before the answer that would
     * otherwise have unmade it. Called from `confirmByUser()` (a late confirmation),
     * `disputeVisit()` (a late contest) and `settleDisputedVisits()` (a late finding) — the three
     * writers that can move {@see SuchakVisitConfirmation::isClaimAnswered()}. `confirmByAdmin()`
     * is not among them because an admin's column is not an answer to the family's claim.
     *
     * Stamped with `claimLapsesAt()`, the instant the window actually closed — never `now()`. A
     * fact noticed late is still a fact that happened on time, and dating it by observation would
     * let the record drift by however long nobody looked.
     *
     * Nothing about the money depends on this having run: `isClaimLapsed()` falls back to
     * arithmetic. This is what makes the fact SURVIVE, not what makes it true.
     */
    private function recordClaimLapseIfDue(SuchakVisitConfirmation $locked): void
    {
        if ($locked->claim_lapsed_at !== null || ! $locked->isClaimLapsed()) {
            return;
        }

        $locked->forceFill(['claim_lapsed_at' => $locked->claimLapsesAt()])->save();
    }

    private function refreshVisitStatus(SuchakVisitConfirmation $visit): SuchakVisitConfirmation
    {
        $status = match (true) {
            $visit->platform_payout_id !== null => SuchakVisitConfirmation::STATUS_PAYOUT_QUALIFIED,
            $visit->hasOpenDispute() => SuchakVisitConfirmation::STATUS_DISPUTED,
            // An upheld complaint has to land on a status OUTSIDE
            // `OPEN_STATUSES`, or the meeting whose fee was just refused would
            // block the pair from ever meeting again — a settled case cannot go
            // on holding the pipeline hostage. `cancelled` is the existing
            // terminal value and reads correctly: the claimed meeting does not
            // stand. `dispute_id` and `refund_review_status` are still on the
            // row, so why it was cancelled is never lost.
            $visit->isFeeRefusedByReview() => SuchakVisitConfirmation::STATUS_CANCELLED,
            // §7.2 clause 4 — a lapsed claim is over, and it has to land somewhere TERMINAL. On
            // `completed` (which is where a lapsed row otherwise falls, since nobody confirmed) it
            // would sit inside `OPEN_STATUSES` forever and block the pair from ever meeting again:
            // the claim can never be answered into payability and can never be disputed again, so
            // nothing would ever move it. `cancelled` is the existing terminal value and reads
            // correctly — the claimed meeting does not stand. `claim_unanswered_since`,
            // `claim_lapsed_at`, `dispute_id` and the family's own column all stay on the row, so
            // a late confirmation is still recorded and why it ended is never lost.
            //
            // Ordered ABOVE the confirmation check on purpose: a family answering on day 99 must
            // not produce a row that reads `confirmed`, which is the status a payable meeting has.
            $visit->isClaimLapsed() => SuchakVisitConfirmation::STATUS_CANCELLED,
            $this->confirmationPolicySatisfied($visit) => SuchakVisitConfirmation::STATUS_CONFIRMED,
            $visit->suchak_completion_status === SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED => SuchakVisitConfirmation::STATUS_COMPLETED,
            default => SuchakVisitConfirmation::STATUS_SCHEDULED,
        };

        if ($visit->visit_status !== $status) {
            $visit->forceFill(['visit_status' => $status])->save();
        }

        return $visit->fresh($this->relations());
    }

    /**
     * Read off the ROW, not off the policy mode.
     *
     * The two confirmation columns already say who this meeting needs: the mode
     * stamped them at schedule time, and `CONFIRMATION_NOT_REQUIRED` is exactly
     * how "this side does not have to answer" is written down. Re-deciding it
     * here from `confirmation_policy_mode` made the mode outrank the row, so a
     * fee-bearing admin-only meeting — whose user column scheduleVisit()
     * deliberately leaves PENDING for M4 — would still have been declared
     * satisfied on the admin's confirmation alone, and the money would have
     * moved with the family never asked.
     *
     * For every row the old code could produce this is the same answer: admin
     * only left user NOT_REQUIRED, user only left admin NOT_REQUIRED, and the
     * default left both PENDING. Nothing is widened and nothing is narrowed —
     * a DISPUTED confirmation is still neither confirmed nor waived, so it still
     * fails. `confirmation_policy_mode` stays on the row as the record of which
     * policy was in force when the meeting was arranged.
     */
    private function confirmationPolicySatisfied(SuchakVisitConfirmation $visit): bool
    {
        if ($visit->suchak_completion_status !== SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED) {
            return false;
        }

        $answered = static fn (?string $status): bool => $status === SuchakVisitConfirmation::CONFIRMATION_CONFIRMED
            || $status === SuchakVisitConfirmation::CONFIRMATION_NOT_REQUIRED;

        return $answered($visit->user_confirmation_status)
            && $answered($visit->admin_confirmation_status);
    }

    private function resolvePlatformPaymentContext(SuchakPipeline $pipeline, mixed $paymentContextId): ?SuchakPaymentContext
    {
        $query = SuchakPaymentContext::query()
            ->with(['suchakAccount', 'customerContext', 'pipeline'])
            ->where('pipeline_id', $pipeline->id)
            ->where('suchak_account_id', $pipeline->selected_suchak_account_id);

        if ($paymentContextId !== null && $paymentContextId !== '') {
            $query->whereKey((int) $paymentContextId);
        }

        $context = $query->first();
        if (! $context instanceof SuchakPaymentContext) {
            if ($paymentContextId !== null && $paymentContextId !== '') {
                throw new InvalidArgumentException('Suchak visit payment context must belong to this pipeline and Suchak account.');
            }

            return null;
        }

        $this->assertPlatformPaymentContext($context, $pipeline);

        return $context;
    }

    private function assertPlatformPaymentContext(SuchakPaymentContext $paymentContext, SuchakPipeline $pipeline): void
    {
        if ((int) $paymentContext->suchak_account_id !== (int) $pipeline->selected_suchak_account_id
            || (int) $paymentContext->pipeline_id !== (int) $pipeline->id) {
            throw new InvalidArgumentException('Suchak visit payment context must belong to this pipeline and Suchak account.');
        }

        if ($paymentContext->context_status !== SuchakPaymentContext::STATUS_ACTIVE
            || $paymentContext->source_owner !== SuchakPaymentContext::SOURCE_PLATFORM
            || $paymentContext->payment_collector !== SuchakPaymentContext::COLLECTOR_PLATFORM) {
            throw new InvalidArgumentException('Suchak visit payout requires an active platform-collected payment context.');
        }
    }

    /**
     * WHICH CUSTOMER THIS PIPELINE IS ABOUT, when no payment context answers.
     *
     * ── THE HOLE THIS CLOSES ─────────────────────────────────────────────────────────────────
     *
     * A meeting is priced from the customer's accepted agreement, and the ONLY route to that
     * customer used to be `suchak_payment_contexts.customer_context_id` on a row keyed to this
     * pipeline. **Nothing in production writes such a row.** Neither pipeline creator makes one
     * ({@see SuchakRequestPipelineService::createRequest()} and `openPipelineForEngagement()`
     * write no payment context at all), `SuchakPaymentCollectorResolver` only creates one when a
     * Suchak records a manual ledger payment, and `SuchakLeadAllocationService` creates one with
     * a NULL `pipeline_id`, which this pipeline-keyed lookup can never see.
     *
     * So every meeting scheduled through the app froze `fee_amount = NULL`: the Suchak could
     * schedule it, complete it and have the family confirm it, and D17's approval screen would
     * show no figure, no fee would fall due under M4/M5, and the work would have earned nothing.
     * A meeting that silently earns nothing is worse than no meeting, and the app is the only
     * door a first meeting has — so the gap had to close in the same change that opened the door.
     *
     * ── WHY THE REPRESENTATION, AND WHY IT CANNOT BE AMBIGUOUS ───────────────────────────────
     *
     * `suchak_pipelines.representation_id` is the arranging Suchak's mandate over the candidate
     * this pair is about, and `suchak_customer_contexts.representation_id` carries
     * `unique(representation_id)` (`suchak_customer_repr_unique`) — a representation has AT MOST
     * ONE customer. There is nothing to guess between, which is what makes this safe where
     * {@see self::soleAgreementInForce()} has to refuse: that one faces two agreed PLANS, and the
     * Suchak answers it with `customer_agreement_id`. Here the database has already answered.
     *
     * It is also the SAME context `GET /customers/{representation}/payment-request-options`
     * resolves, which is where the app reads the plans it offers the Suchak by name. Before this,
     * the app named a plan from the representation's customer while the server priced from the
     * payment context's — two different customers on a good day, and a flat refusal
     * ("agreement must belong to this Suchak account and customer") on a bad one.
     *
     * ── WHAT IS NOT WIDENED ──────────────────────────────────────────────────────────────────
     *
     * The account must match: a representation is owned by one Suchak account and the pipeline
     * names the account that arranges, so a mismatch means the pipeline's mandate moved and no
     * assumption may be made about who is owed. And a customer whose relationship has ENDED
     * ({@see SuchakCustomerContext::isClosedForPayment()}) prices nothing — the same refusal
     * `SuchakPaymentCollectorResolver` already makes before collecting money from that customer.
     * Both cases return null, which `meetingQuote()` already treats as "nothing was agreed for
     * this meeting" — a real answer that the app now states out loud rather than leaving blank.
     *
     * The payment context stays FIRST and is never overridden. This is a fallback, not a
     * replacement: where one exists it is the explicit, recorded answer.
     */
    private function pipelineCustomerContextId(SuchakPipeline $pipeline): ?int
    {
        $pipeline->loadMissing('representation.customerContext');
        $context = $pipeline->representation?->customerContext;

        if (! $context instanceof SuchakCustomerContext) {
            return null;
        }

        if ((int) $context->suchak_account_id !== (int) $pipeline->selected_suchak_account_id) {
            return null;
        }

        return $context->isClosedForPayment() ? null : (int) $context->id;
    }

    private function meetingMode(mixed $value): string
    {
        $mode = trim((string) ($value ?? ''));
        if ($mode === '') {
            return SuchakVisitConfirmation::MODE_OFFLINE;
        }

        if (! in_array($mode, SuchakVisitConfirmation::MEETING_MODES, true)) {
            throw new InvalidArgumentException('Suchak meeting mode must be offline or online.');
        }

        return $mode;
    }

    /**
     * Whose candidate was met. Null on an ordinary meeting; on a marketplace one
     * it names the OTHER Suchak, which is why it may not be the arranging
     * account — that value would make the column say something untrue.
     *
     * DERIVED WHEN NOTHING IS SENT, since 2026-08-06 — and this is a disclosure, because it is a
     * column the caller used to own outright. The app now schedules the FIRST meeting on an
     * engagement-born pipeline, and it sends no helper: it has no id to send, because the
     * `awaiting_first_meeting` payload deliberately publishes the other Suchak's NAME and not his
     * key. Left null, `visitDisputeActorType()` would match nobody, and §7.2's stop-loss — whose
     * entire premise is UNANSWERED HELPER CLAIMS — would attach to no marketplace meeting the app
     * can create. Round-tripping the id through the client to fix that would be a fact travelling
     * out and back for no reason; the engagement already records it.
     *
     * Nothing is widened: `visitDisputeActorType()` already admits the helper, and this only makes
     * the column it reads say who that is. An explicitly sent value still wins, and both routes
     * pass the same refusal below. `helpingSuchakAccountId()` is read off `customer_owner_side`,
     * which on any engagement-born pipeline is a RECORDED fact rather than the column default —
     * `openPipelineForEngagement()` opens no pipeline at all without `hasRecordedCustomerOwner()`.
     */
    private function helperSuchakAccountId(mixed $value, SuchakPipeline $pipeline): ?int
    {
        if ($value === null || $value === '') {
            return $this->engagementHelperSuchakAccountId($pipeline);
        }

        $accountId = (int) $value;
        if ($accountId === (int) $pipeline->selected_suchak_account_id) {
            throw new InvalidArgumentException('Helper Suchak account must be a different account from the Suchak arranging this meeting.');
        }

        if (! SuchakAccount::query()->whereKey($accountId)->exists()) {
            throw new InvalidArgumentException('Helper Suchak account was not found.');
        }

        return $accountId;
    }

    /**
     * The helping Suchak this pipeline's own engagement names, or null on a member-born pipeline.
     *
     * The equality guard is belt-and-braces rather than an expected case: `helpingSuchakAccountId()`
     * is by construction the side `customerOwnerSuchakAccountId()` is not, and the pipeline's
     * `selected_suchak_account_id` was filled from that same accessor. If they ever coincided the
     * engagement would be naming one account as both parties, and writing that into the column the
     * dispute door reads is worse than writing nothing.
     */
    private function engagementHelperSuchakAccountId(SuchakPipeline $pipeline): ?int
    {
        $pipeline->loadMissing('collaborationRequest');
        $engagement = $pipeline->collaborationRequest;

        if ($engagement === null) {
            return null;
        }

        $helperId = $engagement->helpingSuchakAccountId();

        return $helperId === (int) $pipeline->selected_suchak_account_id ? null : $helperId;
    }

    /**
     * What THIS meeting costs, in which currency, and under WHICH agreement —
     * all three frozen now, as one answer, because a quote that keeps its number
     * but loses its unit or its source is not frozen at all.
     *
     * M6/section 4 — the rate on the customer's currently accepted agreement
     * governs, and a later revision does not reach back into a meeting already
     * scheduled. D24 — a re-visit costs the same as a first visit: the sequence
     * number deliberately plays no part in this calculation, there is no
     * discount and no escalation.
     *
     * WHY THE AGREEMENT IS NAMED, NEVER RANKED. `agreement_revision` is numbered
     * per `service_package_id` (`unique(service_package_id, agreement_revision)`;
     * SuchakAgreementService::createRevisionForPackageChange() maxes it within
     * one package), so ordering by it ACROSS packages compares two different
     * counters. A customer holding Basic at revision 2 and Premium at revision 1
     * would be priced off Basic — the plan they left — and a family that upgrades
     * would be billed at the old rate forever with the audit trail asserting the
     * wrong figure as frozen truth.
     *
     * There is no column that says which plan a meeting is on: the meeting hangs
     * off `payment_context_id`, and `suchak_payment_contexts` carries neither
     * `service_package_id` nor `customer_agreement_id`, and neither does
     * `suchak_pipelines` or `suchak_customer_contexts`. The one table that does
     * tie a payment context to an agreement, `suchak_payment_requests`, cannot
     * exist here: sending one runs assertAllowsDirectSuchakCollection(), which
     * REFUSES a platform-owned platform-collected context — exactly the only kind
     * assertPlatformPaymentContext() accepts for a visit. So the caller names the
     * agreement, and what it named is stored on the row (`customer_agreement_id`).
     *
     * When the caller names nothing, one plan is answered and two are refused.
     * Guessing between two agreed plans is what misprices real money, and there
     * is no honest tie-break: "the newest", "the dearest", "the one that quotes
     * this mode" are all inventions. Refusing asks a question a Suchak can
     * answer in a second.
     *
     * Null is a real answer: no customer context, or no agreement in force, or a
     * rate the Suchak never quoted, all mean nothing was agreed for this meeting.
     * `fee_currency` is null exactly when `fee_amount` is — the currency of
     * nothing is nothing, never a defaulted INR.
     *
     * @return array{customer_agreement_id: int|null, fee_amount: string|null, fee_currency: string|null}
     */
    private function meetingQuote(
        int $suchakAccountId,
        ?int $customerContextId,
        string $meetingMode,
        mixed $requestedAgreementId,
    ): array {
        $empty = ['customer_agreement_id' => null, 'fee_amount' => null, 'fee_currency' => null];
        $requestedId = $requestedAgreementId === null || $requestedAgreementId === ''
            ? null
            : (int) $requestedAgreementId;

        if ($customerContextId === null) {
            if ($requestedId !== null) {
                throw new InvalidArgumentException('Suchak meeting agreement requires a payment context that names a customer.');
            }

            return $empty;
        }

        // isTermsSatisfied() is the codebase's single definition of "this
        // agreement is in force"; restating its status list here would be a
        // second copy of that rule.
        $agreements = SuchakCustomerAgreement::query()
            ->with('servicePackage')
            ->where('suchak_account_id', $suchakAccountId)
            ->where('customer_context_id', $customerContextId)
            ->orderByDesc('agreement_revision')
            ->orderByDesc('id')
            ->get();

        $agreement = $requestedId === null
            ? $this->soleAgreementInForce($agreements)
            : $this->namedAgreementInForce($agreements, $requestedId);

        if (! $agreement instanceof SuchakCustomerAgreement) {
            return $empty;
        }

        $package = $agreement->servicePackage;
        $rate = $package === null
            ? null
            : ($meetingMode === SuchakVisitConfirmation::MODE_ONLINE
                ? $package->per_meeting_online_fee_amount
                : $package->per_meeting_fee_amount);

        // The agreement is recorded even when it quotes no rate for this mode:
        // "priced under agreement X, which charges nothing for an online
        // meeting" is a fact, and a bare null would lose it.
        return [
            'customer_agreement_id' => (int) $agreement->id,
            'fee_amount' => $rate === null ? null : number_format((float) $rate, 2, '.', ''),
            // The agreement's own currency, not the package's: the customer
            // accepted that document, and the package underneath it can be
            // edited into a new revision at any time.
            'fee_currency' => $rate === null ? null : $this->currency($agreement->currency),
        ];
    }

    /**
     * The agreement in force when the caller named one.
     *
     * @param  \Illuminate\Support\Collection<int, SuchakCustomerAgreement>  $agreements
     */
    private function namedAgreementInForce($agreements, int $requestedId): SuchakCustomerAgreement
    {
        $agreement = $agreements->firstWhere('id', $requestedId);
        if (! $agreement instanceof SuchakCustomerAgreement) {
            throw new InvalidArgumentException('Suchak meeting agreement must belong to this Suchak account and customer.');
        }

        if (! $agreement->isTermsSatisfied()) {
            throw new InvalidArgumentException('Suchak meeting agreement is not in force; the customer has not accepted these terms.');
        }

        // A superseded revision may not price a NEW meeting. It is still what
        // priced the meetings scheduled while it stood — that is the point of
        // freezing — but section 4 says the accepted revision governs from now.
        if ($agreement->isNot($this->inForceForPackage($agreements, (int) $agreement->service_package_id))) {
            throw new InvalidArgumentException('Suchak meeting agreement is superseded; use the revision the customer currently accepts.');
        }

        return $agreement;
    }

    /**
     * One agreed plan is answered; two are refused rather than guessed between.
     *
     * @param  \Illuminate\Support\Collection<int, SuchakCustomerAgreement>  $agreements
     */
    private function soleAgreementInForce($agreements): ?SuchakCustomerAgreement
    {
        $inForce = $agreements
            ->pluck('service_package_id')
            ->unique()
            ->map(fn ($packageId): ?SuchakCustomerAgreement => $this->inForceForPackage($agreements, (int) $packageId))
            ->filter()
            ->values();

        if ($inForce->count() > 1) {
            throw new InvalidArgumentException('This customer has more than one agreed Suchak plan; name the plan this meeting is under before it can be priced.');
        }

        return $inForce->first();
    }

    /**
     * The newest revision of ONE package that the customer actually accepted.
     *
     * Newest-accepted, not newest: a pending revision 3 sitting above an accepted
     * revision 2 has not been agreed to, and section 4 says a rate change is a
     * new agreement the customer must accept before it governs.
     *
     * @param  \Illuminate\Support\Collection<int, SuchakCustomerAgreement>  $agreements
     */
    private function inForceForPackage($agreements, int $packageId): ?SuchakCustomerAgreement
    {
        return $agreements->first(
            static fn (SuchakCustomerAgreement $candidate): bool => (int) $candidate->service_package_id === $packageId
                && $candidate->isTermsSatisfied(),
        );
    }

    /**
     * WHO MAY CONTEST A MEETING.
     *
     * Three doors, and the order matters because the member check throws.
     *
     * 1. An admin.
     * 2. The HELPING Suchak — the one named in `helper_suchak_account_id`,
     *    i.e. whose candidate was met. Added 2026-08-03: §7.2's entire
     *    stop-loss premise is UNANSWERED HELPER CLAIMS, and the party that
     *    protects had no way to open a claim at all. The hold `disputeVisit()`
     *    raises sits on `suchak_account_id` — the ARRANGING Suchak — which is
     *    exactly the leverage §7.2 describes: the Suchak who must answer is the
     *    one whose payouts freeze while he does not.
     * 3. The requesting member (the customer side).
     *
     * The ARRANGING Suchak is still refused, deliberately. He is the claimant:
     * letting him contest his own fee claim is not a resolution route, it is a
     * claimant withdrawing a claim after the family was already asked to answer
     * it, and `cancelVisit()` already refuses that once the meeting is marked
     * complete for the same reason. D26's "either Suchak may raise the claim" is
     * about the STAGE LADDER, not about a visit dispute — see
     * docs/FIELD-OWNERSHIP-MAP.md.
     *
     * `helper_suchak_account_id` is the only link consulted. A visit row carries
     * no `collaboration_request_id`, and `suchak_collaboration_requests.customer_owner_side`
     * names a SIDE (requesting/target), not an account — resolving an account
     * through it would be a second authorisation path over a table this service
     * does not own. A Suchak with nothing to do with the meeting matches
     * neither column and falls through to the member check, which throws.
     */
    private function visitDisputeActorType(SuchakVisitConfirmation $visit, User $actor): string
    {
        if ($this->accessService->isAdmin($actor)) {
            return SuchakActivityLog::ACTOR_ADMIN;
        }

        if ($this->isHelperSuchakOnVisit($visit, $actor)) {
            $visit->loadMissing('helperSuchakAccount');
            $this->accessService->assertOwnerCanOperate(
                $visit->helperSuchakAccount,
                $actor,
                'Only the helping Suchak on this meeting can dispute it.',
                'Helping Suchak must be verified to dispute this meeting.',
            );

            return SuchakActivityLog::ACTOR_SUCHAK;
        }

        $this->assertCustomerSideUserCanConfirm($visit, $actor);

        return SuchakActivityLog::ACTOR_USER;
    }

    /**
     * Is this actor the helper named on THIS meeting?
     *
     * Cheap, non-throwing, and account-scoped rather than user-scoped, so a
     * Suchak with no account or with somebody else's account simply does not
     * match and goes on to the member check.
     */
    private function isHelperSuchakOnVisit(SuchakVisitConfirmation $visit, User $actor): bool
    {
        if ($visit->helper_suchak_account_id === null) {
            return false;
        }

        $actorAccount = $actor->suchakAccount;

        return $actorAccount instanceof SuchakAccount
            && (int) $actorAccount->id === (int) $visit->helper_suchak_account_id;
    }

    private function recordPipelineEvent(SuchakPipeline $pipeline, string $eventType, string $actorType, User $actor): void
    {
        SuchakPipelineEvent::query()->create([
            'pipeline_id' => $pipeline->id,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actor->id,
            'event_note' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordVisitEvent(
        SuchakVisitConfirmation $visit,
        string $eventType,
        string $actorType,
        ?User $actor,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $eventNote,
        array $metadata = [],
    ): void {
        SuchakVisitConfirmationEvent::query()->create([
            'visit_confirmation_id' => $visit->id,
            'pipeline_id' => $visit->pipeline_id,
            'suchak_account_id' => $visit->suchak_account_id,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            // NULL when the actor is the SYSTEM — nobody acted, a date arrived. The column is
            // nullable and `ACTOR_SYSTEM` already exists; inventing a user to fill it would put a
            // person's name on a machine's act.
            'actor_user_id' => $actor?->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'event_note' => $eventNote,
            'metadata_json' => array_merge([
                'request_id' => $visit->request_id,
                'representation_id' => $visit->representation_id,
                'payment_context_id' => $visit->payment_context_id,
                'confirmation_policy_mode' => $visit->confirmation_policy_mode,
                // Which meeting this was, how it was held, and what it cost —
                // the three facts a dispute a year later has to be able to read
                // back off the append-only trail.
                'meeting_sequence' => $visit->meeting_sequence,
                'meeting_mode' => $visit->meeting_mode,
                'fee_amount' => $visit->fee_amount,
                // The unit and the source travel WITH the figure. A frozen
                // amount alone cannot be read back correctly a year later:
                // nothing else on the trail says which currency it was quoted
                // in, or which agreement revision asserted it.
                'fee_currency' => $visit->fee_currency,
                'customer_agreement_id' => $visit->customer_agreement_id,
                'helper_suchak_account_id' => $visit->helper_suchak_account_id,
            ], $metadata),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function recordActivity(
        SuchakVisitConfirmation $visit,
        ?User $actor,
        string $actorType,
        string $actionType,
        string $context,
        ?string $ipAddress,
        ?string $userAgent,
        ?AdminAuditLog $adminAuditLog = null,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $visit->suchak_account_id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actorType,
            'action_type' => $actionType,
            'target_type' => 'suchak_visit_confirmation',
            'target_id' => $visit->id,
            'matrimony_profile_id' => $visit->target_matrimony_profile_id,
            'admin_audit_log_id' => $adminAuditLog?->id,
            'ip_address' => $ipAddress,
            'user_agent' => Str::limit((string) $userAgent, 512, ''),
            'metadata_json' => [
                'context' => $context,
                'pipeline_id' => $visit->pipeline_id,
                'request_id' => $visit->request_id,
                'representation_id' => $visit->representation_id,
                'payment_context_id' => $visit->payment_context_id,
                'visit_status' => $visit->visit_status,
                'confirmation_policy_mode' => $visit->confirmation_policy_mode,
                'refund_review_status' => $visit->refund_review_status,
                'platform_payout_id' => $visit->platform_payout_id,
                'dispute_id' => $visit->dispute_id,
                'payout_hold_id' => $visit->payout_hold_id,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $oldValue
     * @param  array<string, mixed>  $newValue
     */
    private function writeAdminAuditLog(
        User $admin,
        string $actionType,
        SuchakVisitConfirmation $visit,
        string $reason,
        array $oldValue,
        array $newValue,
    ): AdminAuditLog {
        return AuditLogService::log(
            $admin,
            $actionType,
            class_basename($visit),
            $visit->id,
            trim($reason).' | old='.json_encode($oldValue).' | new='.json_encode($newValue),
            false,
        );
    }

    private function nullableDateTime(mixed $value, string $message): ?Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw new InvalidArgumentException($message);
        }
    }

    private function requiredPrivateSafeText(mixed $value, string $message, int $limit): string
    {
        $text = $this->privateSafeText($value, $limit);
        if ($text === null) {
            throw new InvalidArgumentException($message);
        }

        return $text;
    }

    private function privateSafeText(mixed $value, int $limit): ?string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return null;
        }

        $normalized = Str::limit($normalized, $limit, '');
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $normalized) === 1
            || preg_match('/(?<!\d)(?:\+?91[\s-]*)?[6-9]\d(?:[\s-]?\d){8}(?!\d)/', $normalized) === 1) {
            throw new InvalidArgumentException('Suchak visit confirmation records must not store private contact details.');
        }

        return $normalized;
    }

    private function requiredAmount(mixed $value): string
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            throw new InvalidArgumentException('Suchak visit payout amount is invalid.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper(trim((string) ($value ?? 'INR')));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Suchak visit payout currency is invalid.');
        }

        return $currency;
    }
}
