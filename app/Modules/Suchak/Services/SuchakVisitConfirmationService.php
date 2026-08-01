<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakDispute;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPipeline;
use App\Models\SuchakPipelineEvent;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakPayoutHold;
use App\Models\SuchakVisitConfirmation;
use App\Models\SuchakVisitConfirmationEvent;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakVisitConfirmationService
{
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
            $quote = $this->meetingQuote(
                (int) $lockedPipeline->selected_suchak_account_id,
                $paymentContext?->customer_context_id === null ? null : (int) $paymentContext->customer_context_id,
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
                'customer_context_id' => $paymentContext?->customer_context_id,
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
        return DB::transaction(function () use ($visit, $suchakUser, $attributes, $ipAddress, $userAgent): SuchakVisitConfirmation {
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
            $this->assertRequestingUserCanConfirm($locked, $user);
            $this->assertCompletedBeforeConfirmation($locked);
            $this->assertNotDisputedOrPayoutQualified($locked);

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

            if ($locked->visit_status === SuchakVisitConfirmation::STATUS_DISPUTED) {
                throw new InvalidArgumentException('Suchak visit confirmation is already disputed.');
            }

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

            $dispute = SuchakDispute::query()->create([
                'suchak_account_id' => $locked->suchak_account_id,
                'matrimony_profile_id' => $locked->target_matrimony_profile_id,
                'representation_id' => $locked->representation_id,
                'customer_context_id' => $locked->customer_context_id,
                'payment_context_id' => $locked->payment_context_id,
                'opened_by_user_id' => $actor->id,
                'assigned_admin_user_id' => $actorType === SuchakActivityLog::ACTOR_ADMIN ? $actor->id : null,
                'dispute_type' => SuchakDispute::TYPE_VISIT_CONFIRMATION,
                'status' => SuchakDispute::STATUS_OPEN,
                'priority' => SuchakDispute::PRIORITY_HIGH,
                'risk_source' => SuchakDispute::RISK_SOURCE_VISIT_CONFIRMATION_DISPUTE,
                'summary' => $reason,
                'evidence_summary' => 'Visit completion dispute recorded for structured Suchak visit confirmation #'.$locked->id.'.',
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
                'hold_reason' => 'Visit confirmation is disputed; platform visit payout is held.',
                'created_by_user_id' => $actor->id,
            ]);

            $fromStatus = $locked->visit_status;
            $locked->forceFill([
                'visit_status' => SuchakVisitConfirmation::STATUS_DISPUTED,
                'user_confirmation_status' => $actorType === SuchakActivityLog::ACTOR_USER
                    ? SuchakVisitConfirmation::CONFIRMATION_DISPUTED
                    : $locked->user_confirmation_status,
                'dispute_id' => $dispute->id,
                'payout_hold_id' => $hold->id,
                'refund_review_status' => SuchakVisitConfirmation::REFUND_PENDING_REVIEW,
                'refund_review_note' => 'Refund/dispute review required before payout qualification.',
            ])->save();

            $fresh = $locked->fresh($this->relations());
            $this->recordVisitEvent(
                $fresh,
                SuchakVisitConfirmationEvent::EVENT_DISPUTED,
                $actorType === SuchakActivityLog::ACTOR_ADMIN ? SuchakVisitConfirmationEvent::ACTOR_ADMIN : SuchakVisitConfirmationEvent::ACTOR_USER,
                $actor,
                $fromStatus,
                $fresh->visit_status,
                $reason,
                ['dispute_id' => $dispute->id, 'payout_hold_id' => $hold->id],
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
        });
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

            $paymentContext = $locked->paymentContext;
            if (! $paymentContext instanceof SuchakPaymentContext) {
                throw new InvalidArgumentException('Suchak visit payout qualification requires a platform payment context.');
            }

            $this->assertPlatformPaymentContext($paymentContext, $locked->pipeline);
            $amount = $this->requiredAmount($attributes['amount'] ?? null);
            // Defaults to the currency the meeting was QUOTED in, not to rupees.
            // A payout for a meeting frozen in USD that silently settles as INR
            // is the same defect as rendering it with a '₹'.
            $currency = $this->currency($attributes['currency'] ?? $locked->fee_currency ?? 'INR');
            $note = $this->requiredPrivateSafeText($attributes['qualification_note'] ?? null, 'Suchak visit payout qualification note is required.', 1000);
            $fromStatus = $locked->visit_status;

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

            $adminAuditLog = $this->writeAdminAuditLog(
                $admin,
                'suchak_visit_payout_qualified',
                $locked,
                $note,
                ['visit_status' => $fromStatus, 'platform_payout_id' => null],
                ['visit_status' => SuchakVisitConfirmation::STATUS_PAYOUT_QUALIFIED, 'platform_payout_id' => $payout->id],
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
                ['platform_payout_id' => $payout->id],
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

    private function assertRequestingUserCanConfirm(SuchakVisitConfirmation $visit, User $user): void
    {
        $visit->loadMissing('requestingMatrimonyProfile');
        if (! $visit->requestingMatrimonyProfile instanceof MatrimonyProfile
            || (int) $visit->requestingMatrimonyProfile->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('Only the requesting user can confirm this Suchak visit.');
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

    private function assertCompletedBeforeConfirmation(SuchakVisitConfirmation $visit): void
    {
        if ($visit->suchak_completion_status !== SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED) {
            throw new InvalidArgumentException('Suchak must mark the visit completed before confirmation.');
        }
    }

    private function assertNotDisputedOrPayoutQualified(SuchakVisitConfirmation $visit): void
    {
        $this->assertNotPayoutQualified($visit);
        if ($visit->visit_status === SuchakVisitConfirmation::STATUS_DISPUTED || $visit->dispute_id !== null) {
            throw new InvalidArgumentException('Disputed Suchak visit confirmations cannot be changed.');
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

        if ($visit->visit_status === SuchakVisitConfirmation::STATUS_DISPUTED || $visit->dispute_id !== null) {
            throw new InvalidArgumentException('Disputed Suchak visit confirmations cannot qualify platform payout.');
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

        if (! $this->confirmationPolicySatisfied($visit)) {
            throw new InvalidArgumentException('Suchak visit confirmation policy is not yet satisfied.');
        }
    }

    private function refreshVisitStatus(SuchakVisitConfirmation $visit): SuchakVisitConfirmation
    {
        $status = match (true) {
            $visit->platform_payout_id !== null => SuchakVisitConfirmation::STATUS_PAYOUT_QUALIFIED,
            $visit->dispute_id !== null || $visit->visit_status === SuchakVisitConfirmation::STATUS_DISPUTED => SuchakVisitConfirmation::STATUS_DISPUTED,
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
     */
    private function helperSuchakAccountId(mixed $value, SuchakPipeline $pipeline): ?int
    {
        if ($value === null || $value === '') {
            return null;
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

    private function visitDisputeActorType(SuchakVisitConfirmation $visit, User $actor): string
    {
        if ($this->accessService->isAdmin($actor)) {
            return SuchakActivityLog::ACTOR_ADMIN;
        }

        $this->assertRequestingUserCanConfirm($visit, $actor);

        return SuchakActivityLog::ACTOR_USER;
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
        User $actor,
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
            'actor_user_id' => $actor->id,
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
        User $actor,
        string $actorType,
        string $actionType,
        string $context,
        ?string $ipAddress,
        ?string $userAgent,
        ?AdminAuditLog $adminAuditLog = null,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $visit->suchak_account_id,
            'actor_user_id' => $actor->id,
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
