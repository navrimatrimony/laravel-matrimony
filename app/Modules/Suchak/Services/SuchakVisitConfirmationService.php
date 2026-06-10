<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\MatrimonyProfile;
use App\Models\SuchakActivityLog;
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
            /** @var SuchakPipeline $lockedPipeline */
            $lockedPipeline = SuchakPipeline::query()
                ->with(['selectedSuchakAccount', 'request', 'representation'])
                ->whereKey($pipeline->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOwnerCanManagePipeline($lockedPipeline, $suchakUser);
            $this->assertOpenPipeline($lockedPipeline);

            $existing = SuchakVisitConfirmation::query()
                ->where('pipeline_id', $lockedPipeline->id)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof SuchakVisitConfirmation) {
                throw new InvalidArgumentException('Suchak visit confirmation already exists for this pipeline.');
            }

            $policyMode = $this->policyService->visitConfirmationPolicyMode();
            $paymentContext = $this->resolvePlatformPaymentContext($lockedPipeline, $attributes['payment_context_id'] ?? null);
            $scheduleNote = $this->privateSafeText($attributes['schedule_note'] ?? null, 1000);
            $scheduledFor = $this->nullableDateTime($attributes['scheduled_for'] ?? null, 'Suchak visit scheduled date is invalid.');

            $visit = SuchakVisitConfirmation::query()->create([
                'pipeline_id' => $lockedPipeline->id,
                'suchak_account_id' => $lockedPipeline->selected_suchak_account_id,
                'request_id' => $lockedPipeline->request_id,
                'representation_id' => $lockedPipeline->representation_id,
                'target_matrimony_profile_id' => $lockedPipeline->target_matrimony_profile_id,
                'requesting_matrimony_profile_id' => $lockedPipeline->requesting_matrimony_profile_id,
                'payment_context_id' => $paymentContext?->id,
                'customer_context_id' => $paymentContext?->customer_context_id,
                'visit_status' => SuchakVisitConfirmation::STATUS_SCHEDULED,
                'confirmation_policy_mode' => $policyMode,
                'scheduled_for' => $scheduledFor,
                'scheduled_by_user_id' => $suchakUser->id,
                'scheduled_at' => now(),
                'schedule_note' => $scheduleNote,
                'user_confirmation_status' => $policyMode === SuchakVisitConfirmation::POLICY_ADMIN_ONLY
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
            $currency = $this->currency($attributes['currency'] ?? 'INR');
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
            'request',
            'representation',
            'targetMatrimonyProfile',
            'requestingMatrimonyProfile',
            'paymentContext',
            'customerContext',
            'platformPayout',
            'dispute',
            'payoutHold',
            'events',
        ];
    }

    private function lockedVisit(SuchakVisitConfirmation $visit): SuchakVisitConfirmation
    {
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

    private function confirmationPolicySatisfied(SuchakVisitConfirmation $visit): bool
    {
        if ($visit->suchak_completion_status !== SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED) {
            return false;
        }

        return match ($visit->confirmation_policy_mode) {
            SuchakVisitConfirmation::POLICY_ADMIN_ONLY => $visit->admin_confirmation_status === SuchakVisitConfirmation::CONFIRMATION_CONFIRMED,
            SuchakVisitConfirmation::POLICY_USER_ONLY => $visit->user_confirmation_status === SuchakVisitConfirmation::CONFIRMATION_CONFIRMED,
            default => $visit->user_confirmation_status === SuchakVisitConfirmation::CONFIRMATION_CONFIRMED
                && $visit->admin_confirmation_status === SuchakVisitConfirmation::CONFIRMATION_CONFIRMED,
        };
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
