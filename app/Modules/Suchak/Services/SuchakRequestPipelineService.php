<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\Message;
use App\Models\SuchakPipeline;
use App\Models\SuchakPipelineEvent;
use App\Models\SuchakFeatureSuspension;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\User;
use App\Services\Chat\ChatConversationService;
use App\Services\Chat\ChatMessageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SuchakRequestPipelineService
{
    public const DECISION_INTERESTED = 'interested';
    public const DECISION_NOT_INTERESTED = 'not_interested';

    public const DECISIONS = [
        self::DECISION_INTERESTED,
        self::DECISION_NOT_INTERESTED,
    ];

    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakAccessService $accessService,
        private readonly ChatConversationService $chatConversationService,
        private readonly ChatMessageService $chatMessageService,
        private readonly SuchakLimitService $limitService,
        private readonly SuchakQualityControlService $qualityControlService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{request: SuchakProfileRequest, pipeline: SuchakPipeline, event: SuchakPipelineEvent}
     */
    public function createRequest(
        User $requestingUser,
        MatrimonyProfile $requestingProfile,
        SuchakProfileRepresentation $representation,
        array $attributes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $requestingProfile->refresh();
        $representation->refresh()->loadMissing(['suchakAccount', 'matrimonyProfile']);

        $this->assertRequestCanBeCreated($requestingUser, $requestingProfile, $representation);
        $this->qualityControlService->assertFeatureAvailable($representation->suchakAccount, SuchakFeatureSuspension::FEATURE_PUBLIC_REQUEST);
        $this->limitService->assertLeadRequestAllowed($representation->suchakAccount);

        return DB::transaction(function () use ($requestingUser, $requestingProfile, $representation, $attributes, $ipAddress, $userAgent): array {
            /** @var SuchakProfileRepresentation $lockedRepresentation */
            $lockedRepresentation = SuchakProfileRepresentation::query()
                ->whereKey($representation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRepresentation->loadMissing(['suchakAccount', 'matrimonyProfile']);
            /** @var SuchakAccount $lockedSuchakAccount */
            $lockedSuchakAccount = SuchakAccount::query()
                ->whereKey($lockedRepresentation->suchak_account_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedRepresentation->setRelation('suchakAccount', $lockedSuchakAccount);
            $this->assertRequestCanBeCreated($requestingUser, $requestingProfile, $lockedRepresentation);
            $this->qualityControlService->assertFeatureAvailable($lockedSuchakAccount, SuchakFeatureSuspension::FEATURE_PUBLIC_REQUEST);
            $this->limitService->assertLeadRequestAllowed($lockedSuchakAccount);
            $this->assertNoDuplicateOpenRequest($requestingProfile, $lockedRepresentation);

            $request = SuchakProfileRequest::query()->create([
                'requesting_user_id' => $requestingUser->id,
                'requesting_matrimony_profile_id' => $requestingProfile->id,
                'target_matrimony_profile_id' => $lockedRepresentation->matrimony_profile_id,
                'selected_suchak_account_id' => $lockedRepresentation->suchak_account_id,
                'representation_id' => $lockedRepresentation->id,
                'request_status' => SuchakProfileRequest::STATUS_PENDING,
                'request_reason' => $this->nullableLimitedString($attributes['request_reason'] ?? null, 255),
                'message' => $this->nullableLimitedString($attributes['message'] ?? null, 2000),
            ]);

            $lockedAt = now();

            $pipeline = SuchakPipeline::query()->create([
                'request_id' => $request->id,
                'target_matrimony_profile_id' => $request->target_matrimony_profile_id,
                'requesting_matrimony_profile_id' => $request->requesting_matrimony_profile_id,
                'selected_suchak_account_id' => $request->selected_suchak_account_id,
                'representation_id' => $request->representation_id,
                'pipeline_status' => SuchakPipeline::STATUS_PENDING,
                'attribution_locked_at' => $lockedAt,
                'lock_expires_at' => $lockedAt->copy()->addHours($this->requestSlaHours()),
                'sla_status' => SuchakPipeline::SLA_WITHIN,
            ]);

            $event = $this->recordPipelineEvent(
                $pipeline,
                SuchakPipelineEvent::EVENT_REQUEST_CREATED,
                SuchakPipelineEvent::ACTOR_USER,
                $requestingUser->id,
            );

            $this->activityLogger->record([
                'suchak_account_id' => $request->selected_suchak_account_id,
                'actor_user_id' => $requestingUser->id,
                'actor_type' => SuchakActivityLog::ACTOR_USER,
                'action_type' => SuchakActivityLog::ACTION_USER_REQUEST_CREATED,
                'target_type' => 'suchak_profile_request',
                'target_id' => $request->id,
                'matrimony_profile_id' => $request->target_matrimony_profile_id,
                'ip_address' => $ipAddress,
                'user_agent' => Str::limit((string) $userAgent, 512, ''),
                'metadata_json' => [
                    'context' => 'request_created',
                    'pipeline_id' => $pipeline->id,
                    'requesting_matrimony_profile_id' => $request->requesting_matrimony_profile_id,
                    'representation_id' => $request->representation_id,
                    'request_status' => $request->request_status,
                    'pipeline_status' => $pipeline->pipeline_status,
                    'sla_status' => $pipeline->sla_status,
                    'lock_expires_at' => $pipeline->lock_expires_at?->toIso8601String(),
                ],
            ]);

            $requestChatMessage = $this->storeInitialRequestMessageInExistingChat(
                $request,
                $requestingProfile,
                $lockedRepresentation->matrimonyProfile,
            );

            if ($requestChatMessage !== null) {
                $request = $request->fresh(['pipeline', 'requestChatMessage', 'chatConversation']);
            }

            return [
                'request' => $request->fresh(['pipeline', 'requestChatMessage', 'chatConversation']),
                'pipeline' => $pipeline->fresh(['request', 'events']),
                'event' => $event,
            ];
        });
    }

    public function expirePipelineIfPastSla(SuchakPipeline $pipeline): SuchakPipeline
    {
        $pipeline->refresh()->loadMissing('request');

        if ($pipeline->pipeline_status === SuchakPipeline::STATUS_EXPIRED) {
            return $pipeline;
        }

        if (! $pipeline->isPastSla()) {
            return $pipeline;
        }

        return DB::transaction(function () use ($pipeline): SuchakPipeline {
            /** @var SuchakPipeline $lockedPipeline */
            $lockedPipeline = SuchakPipeline::query()
                ->whereKey($pipeline->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPipeline->loadMissing('request');

            if ($lockedPipeline->pipeline_status === SuchakPipeline::STATUS_EXPIRED || ! $lockedPipeline->isPastSla()) {
                return $lockedPipeline;
            }

            SuchakPipeline::query()
                ->whereKey($lockedPipeline->id)
                ->update([
                    'pipeline_status' => SuchakPipeline::STATUS_EXPIRED,
                    'sla_status' => SuchakPipeline::SLA_EXPIRED,
                ]);

            SuchakProfileRequest::query()
                ->whereKey($lockedPipeline->request_id)
                ->whereIn('request_status', SuchakProfileRequest::OPEN_STATUSES)
                ->update(['request_status' => SuchakProfileRequest::STATUS_EXPIRED]);

            $expiredPipeline = $lockedPipeline->fresh(['request']);

            $this->recordPipelineEvent(
                $expiredPipeline,
                SuchakPipelineEvent::EVENT_EXPIRED,
                SuchakPipelineEvent::ACTOR_SYSTEM,
                null,
            );

            $this->activityLogger->record([
                'suchak_account_id' => $expiredPipeline->selected_suchak_account_id,
                'actor_user_id' => null,
                'actor_type' => SuchakActivityLog::ACTOR_SYSTEM,
                'action_type' => SuchakActivityLog::ACTION_PIPELINE_STATUS_CHANGED,
                'target_type' => 'suchak_pipeline',
                'target_id' => $expiredPipeline->id,
                'matrimony_profile_id' => $expiredPipeline->target_matrimony_profile_id,
                'metadata_json' => [
                    'context' => 'sla_expired',
                    'request_id' => $expiredPipeline->request_id,
                    'request_status' => $expiredPipeline->request?->request_status,
                    'pipeline_status' => $expiredPipeline->pipeline_status,
                    'sla_status' => $expiredPipeline->sla_status,
                    'lock_expires_at' => $expiredPipeline->lock_expires_at?->toIso8601String(),
                ],
            ]);

            return $expiredPipeline->fresh(['request', 'events']);
        });
    }

    /**
     * Route a Suchak reply through the existing profile-to-profile chat engine.
     *
     * @return array{request: SuchakProfileRequest, message: Message}
     */
    public function replyThroughExistingChat(
        SuchakProfileRequest $request,
        SuchakAccount $account,
        User $actor,
        string $replyText,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $replyText = $this->normalizeReplyText($replyText);

        return DB::transaction(function () use ($request, $account, $actor, $replyText, $ipAddress, $userAgent): array {
            /** @var SuchakProfileRequest $lockedRequest */
            $lockedRequest = SuchakProfileRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->with([
                    'pipeline',
                    'requestingMatrimonyProfile.user',
                    'targetMatrimonyProfile.user',
                ])
                ->firstOrFail();

            if ((int) $lockedRequest->selected_suchak_account_id !== (int) $account->id) {
                throw new InvalidArgumentException('Suchak request does not belong to this Suchak account.');
            }

            // Consent, and only consent, makes this Suchak the person's
            // representative. A revoked/expired consent must close the reply
            // path on EVERY surface (web + both apps), so the check lives here
            // rather than in either controller.
            $this->assertSuchakMayActOnRequest($lockedRequest);

            if (! $lockedRequest->isOpen()) {
                throw new InvalidArgumentException('Suchak request is not open.');
            }

            if (! $this->profileIsActive($lockedRequest->requestingMatrimonyProfile)) {
                throw new InvalidArgumentException('Requesting profile must be active before Suchak can reply.');
            }

            if (! $this->profileIsActive($lockedRequest->targetMatrimonyProfile)) {
                throw new InvalidArgumentException('Target profile must be active before Suchak can reply.');
            }

            $conversation = $this->chatConversationService->findOrCreateConversationBetweenProfiles(
                $lockedRequest->targetMatrimonyProfile,
                $lockedRequest->requestingMatrimonyProfile,
            );

            $message = $this->chatMessageService->sendTextMessage(
                $lockedRequest->targetMatrimonyProfile,
                $lockedRequest->requestingMatrimonyProfile,
                $conversation,
                $this->suchakReplyChatBody($account, $replyText),
            );

            $repliedAt = now();

            SuchakProfileRequest::query()
                ->whereKey($lockedRequest->id)
                ->update([
                    'request_status' => SuchakProfileRequest::STATUS_ACCEPTED_BY_SUCHAK,
                    'chat_conversation_id' => $conversation->id,
                    'chat_message_id' => $message->id,
                    'replied_by_user_id' => $actor->id,
                    'replied_at' => $repliedAt,
                    'updated_at' => $repliedAt,
                ]);

            $updatedRequest = $lockedRequest->fresh(['pipeline', 'chatConversation', 'chatMessage']);

            if ($updatedRequest?->pipeline) {
                $this->recordPipelineEvent(
                    $updatedRequest->pipeline,
                    SuchakPipelineEvent::EVENT_SUCHAK_REPLIED,
                    SuchakPipelineEvent::ACTOR_SUCHAK,
                    $actor->id,
                    'Reply routed through chat conversation #'.$conversation->id.' message #'.$message->id,
                );
            }

            $this->activityLogger->record([
                'suchak_account_id' => $account->id,
                'actor_user_id' => $actor->id,
                'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
                'action_type' => SuchakActivityLog::ACTION_USER_REQUEST_REPLIED,
                'target_type' => 'suchak_profile_request',
                'target_id' => $lockedRequest->id,
                'matrimony_profile_id' => $lockedRequest->target_matrimony_profile_id,
                'ip_address' => $ipAddress,
                'user_agent' => Str::limit((string) $userAgent, 512, ''),
                'metadata_json' => [
                    'context' => 'reply_routed_to_existing_chat',
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'requesting_matrimony_profile_id' => $lockedRequest->requesting_matrimony_profile_id,
                    'target_matrimony_profile_id' => $lockedRequest->target_matrimony_profile_id,
                    'representation_id' => $lockedRequest->representation_id,
                ],
            ]);

            return [
                'request' => $updatedRequest,
                'message' => $message,
            ];
        });
    }

    public function allowsAlternateSuchakSelection(SuchakProfileRequest $request): bool
    {
        $request->loadMissing('pipeline');

        return $request->request_status === SuchakProfileRequest::STATUS_EXPIRED
            || $request->pipeline?->pipeline_status === SuchakPipeline::STATUS_EXPIRED;
    }

    /**
     * Lazy SLA sweep. There is no cron for expirePipelineIfPastSla(), so every
     * read surface (member profile detail, member request list, Suchak inbox)
     * runs the sweep for its own narrow scope first. That is what makes
     * "no reply inside the SLA window closes the request" actually true, and it
     * reuses the existing expiry mechanism instead of adding a second timer.
     *
     * @param  callable(\Illuminate\Database\Eloquent\Builder): void  $scope
     */
    public function expireDuePipelines(callable $scope): int
    {
        $duePipelines = SuchakPipeline::query()
            ->where('pipeline_status', '!=', SuchakPipeline::STATUS_EXPIRED)
            ->where('lock_expires_at', '<=', now())
            ->whereHas('request', function ($query): void {
                $query->whereIn('request_status', SuchakProfileRequest::OPEN_STATUSES);
            })
            ->tap($scope)
            ->get();

        foreach ($duePipelines as $pipeline) {
            $this->expirePipelineIfPastSla($pipeline);
        }

        return $duePipelines->count();
    }

    public function expireDuePipelinesForRequestingProfile(MatrimonyProfile $requestingProfile): int
    {
        return $this->expireDuePipelines(
            fn ($query) => $query->where('requesting_matrimony_profile_id', $requestingProfile->id),
        );
    }

    public function expireDuePipelinesForTargetProfile(MatrimonyProfile $targetProfile): int
    {
        return $this->expireDuePipelines(
            fn ($query) => $query->where('target_matrimony_profile_id', $targetProfile->id),
        );
    }

    public function expireDuePipelinesForAccount(SuchakAccount $account): int
    {
        return $this->expireDuePipelines(
            fn ($query) => $query->where('selected_suchak_account_id', $account->id),
        );
    }

    /**
     * pending → viewed_by_suchak. Idempotent: any later state is left alone, so
     * opening the same request twice never rewinds the lifecycle.
     */
    public function markViewedBySuchak(
        SuchakProfileRequest $request,
        SuchakAccount $account,
        User $actor,
    ): SuchakProfileRequest {
        if ((int) $request->selected_suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException(__('profile.suchak_request_not_yours'));
        }

        if ($request->request_status !== SuchakProfileRequest::STATUS_PENDING) {
            return $request;
        }

        return DB::transaction(function () use ($request, $actor): SuchakProfileRequest {
            /** @var SuchakProfileRequest $lockedRequest */
            $lockedRequest = SuchakProfileRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->with('pipeline')
                ->firstOrFail();

            if ($lockedRequest->request_status !== SuchakProfileRequest::STATUS_PENDING) {
                return $lockedRequest;
            }

            SuchakProfileRequest::query()
                ->whereKey($lockedRequest->id)
                ->update([
                    'request_status' => SuchakProfileRequest::STATUS_VIEWED_BY_SUCHAK,
                    'updated_at' => now(),
                ]);

            if ($lockedRequest->pipeline) {
                $this->recordPipelineEvent(
                    $lockedRequest->pipeline,
                    SuchakPipelineEvent::EVENT_SUCHAK_VIEWED,
                    SuchakPipelineEvent::ACTOR_SUCHAK,
                    $actor->id,
                );
            }

            return $lockedRequest->fresh(['pipeline']);
        });
    }

    /**
     * accepted/viewed → forwarded_to_candidate. This is the Suchak telling the
     * platform "I have put this in front of the family"; the family's answer
     * then arrives through recordCandidateDecision().
     *
     * @return array{request: SuchakProfileRequest}
     */
    public function forwardToCandidate(
        SuchakProfileRequest $request,
        SuchakAccount $account,
        User $actor,
        ?string $note = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        return DB::transaction(function () use ($request, $account, $actor, $note, $ipAddress, $userAgent): array {
            /** @var SuchakProfileRequest $lockedRequest */
            $lockedRequest = SuchakProfileRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->with(['pipeline', 'representation'])
                ->firstOrFail();

            if ((int) $lockedRequest->selected_suchak_account_id !== (int) $account->id) {
                throw new InvalidArgumentException(__('profile.suchak_request_not_yours'));
            }

            $this->assertSuchakMayActOnRequest($lockedRequest);

            if (in_array($lockedRequest->request_status, [
                SuchakProfileRequest::STATUS_FORWARDED_TO_CANDIDATE,
                SuchakProfileRequest::STATUS_CANDIDATE_INTERESTED,
                SuchakProfileRequest::STATUS_CANDIDATE_NOT_INTERESTED,
            ], true)) {
                return ['request' => $lockedRequest];
            }

            SuchakProfileRequest::query()
                ->whereKey($lockedRequest->id)
                ->update([
                    'request_status' => SuchakProfileRequest::STATUS_FORWARDED_TO_CANDIDATE,
                    'updated_at' => now(),
                ]);

            if ($lockedRequest->pipeline) {
                $this->recordPipelineEvent(
                    $lockedRequest->pipeline,
                    SuchakPipelineEvent::EVENT_FORWARDED_TO_CANDIDATE,
                    SuchakPipelineEvent::ACTOR_SUCHAK,
                    $actor->id,
                    $this->nullableLimitedString($note, 500),
                );
            }

            $this->activityLogger->record([
                'suchak_account_id' => $account->id,
                'actor_user_id' => $actor->id,
                'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
                'action_type' => SuchakActivityLog::ACTION_PIPELINE_STATUS_CHANGED,
                'target_type' => 'suchak_profile_request',
                'target_id' => $lockedRequest->id,
                'matrimony_profile_id' => $lockedRequest->target_matrimony_profile_id,
                'ip_address' => $ipAddress,
                'user_agent' => Str::limit((string) $userAgent, 512, ''),
                'metadata_json' => [
                    'context' => 'forwarded_to_candidate',
                    'request_status' => SuchakProfileRequest::STATUS_FORWARDED_TO_CANDIDATE,
                    'representation_id' => $lockedRequest->representation_id,
                ],
            ]);

            return ['request' => $lockedRequest->fresh(['pipeline'])];
        });
    }

    /**
     * The "family said yes / no" half of the lifecycle, and the ONE place that
     * decides the both-can-answer race.
     *
     * PO decision: when the candidate holds their own account, the candidate AND
     * the Suchak both see the request and either may answer — first answer wins.
     * The race is settled by taking a row lock on the request inside the
     * transaction and re-reading the status under that lock; the loser is told
     * cleanly that it was already answered (and by whom) instead of getting an
     * error or silently overwriting the winner.
     *
     * @param  'interested'|'not_interested'  $decision
     * @return array{
     *   request: SuchakProfileRequest,
     *   already_answered: bool,
     *   answered_by: 'suchak'|'candidate'|null,
     *   answered_at: \Illuminate\Support\Carbon|null,
     *   message: Message|null
     * }
     */
    public function recordCandidateDecision(
        SuchakProfileRequest $request,
        User $actor,
        string $decision,
        ?string $note = null,
        ?SuchakAccount $account = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        if (! in_array($decision, [self::DECISION_INTERESTED, self::DECISION_NOT_INTERESTED], true)) {
            throw new InvalidArgumentException(__('profile.suchak_request_decision_invalid'));
        }

        return DB::transaction(function () use ($request, $actor, $decision, $note, $account, $ipAddress, $userAgent): array {
            /** @var SuchakProfileRequest $lockedRequest */
            $lockedRequest = SuchakProfileRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->with([
                    'pipeline',
                    'representation',
                    'requestingMatrimonyProfile.user',
                    'targetMatrimonyProfile.user',
                ])
                ->firstOrFail();

            $actorRole = $this->resolveDecisionActorRole($lockedRequest, $actor, $account);

            if ($actorRole === SuchakPipelineEvent::ACTOR_SUCHAK) {
                $this->assertSuchakMayActOnRequest($lockedRequest);
            }

            // First-answer-wins. Re-read under the lock: whoever got here second
            // sees the winner's status and writes nothing at all.
            if ($this->isAlreadyAnswered($lockedRequest)) {
                $decidedBy = $this->decisionActorFor($lockedRequest);

                return [
                    'request' => $lockedRequest,
                    'already_answered' => true,
                    'answered_by' => $decidedBy['role'] ?? null,
                    'answered_at' => $decidedBy['at'] ?? null,
                    'message' => null,
                ];
            }

            if (! $lockedRequest->isOpen()) {
                throw new InvalidArgumentException(__('profile.suchak_request_not_open'));
            }

            $status = $decision === self::DECISION_INTERESTED
                ? SuchakProfileRequest::STATUS_CANDIDATE_INTERESTED
                : SuchakProfileRequest::STATUS_CANDIDATE_NOT_INTERESTED;

            $decidedAt = now();

            SuchakProfileRequest::query()
                ->whereKey($lockedRequest->id)
                ->update([
                    'request_status' => $status,
                    'updated_at' => $decidedAt,
                ]);

            $chatMessage = $this->postDecisionToExistingChat(
                $lockedRequest,
                $actorRole,
                $decision,
                $note,
                $account,
            );

            if ($chatMessage !== null) {
                SuchakProfileRequest::query()
                    ->whereKey($lockedRequest->id)
                    ->update([
                        'chat_conversation_id' => $chatMessage->conversation_id,
                        'chat_message_id' => $chatMessage->id,
                        'updated_at' => $decidedAt,
                    ]);
            }

            if ($lockedRequest->pipeline) {
                $this->recordPipelineEvent(
                    $lockedRequest->pipeline,
                    $decision === self::DECISION_INTERESTED
                        ? SuchakPipelineEvent::EVENT_CANDIDATE_INTERESTED
                        : SuchakPipelineEvent::EVENT_CANDIDATE_NOT_INTERESTED,
                    $actorRole,
                    $actor->id,
                    $this->nullableLimitedString($note, 500),
                );
            }

            $this->activityLogger->record([
                'suchak_account_id' => $lockedRequest->selected_suchak_account_id,
                'actor_user_id' => $actor->id,
                'actor_type' => $actorRole === SuchakPipelineEvent::ACTOR_SUCHAK
                    ? SuchakActivityLog::ACTOR_SUCHAK
                    : SuchakActivityLog::ACTOR_USER,
                'action_type' => SuchakActivityLog::ACTION_PIPELINE_STATUS_CHANGED,
                'target_type' => 'suchak_profile_request',
                'target_id' => $lockedRequest->id,
                'matrimony_profile_id' => $lockedRequest->target_matrimony_profile_id,
                'ip_address' => $ipAddress,
                'user_agent' => Str::limit((string) $userAgent, 512, ''),
                'metadata_json' => [
                    'context' => 'candidate_decision',
                    'request_status' => $status,
                    'answered_by' => $actorRole,
                    'representation_id' => $lockedRequest->representation_id,
                    'chat_message_id' => $chatMessage?->id,
                ],
            ]);

            return [
                'request' => $lockedRequest->fresh(['pipeline', 'chatConversation', 'chatMessage']),
                'already_answered' => false,
                'answered_by' => $actorRole,
                'answered_at' => $decidedAt,
                'message' => $chatMessage,
            ];
        });
    }

    /**
     * Who settled the candidate decision, read back from the immutable pipeline
     * event trail. Deliberately derived rather than stored on a new column: the
     * event already records actor_type + actor_id + created_at, and a second
     * copy of that fact could drift from it.
     *
     * @return array{role: 'suchak'|'candidate', user_id: int|null, at: \Illuminate\Support\Carbon|null}|null
     */
    public function decisionActorFor(SuchakProfileRequest $request): ?array
    {
        $request->loadMissing('pipeline');

        if ($request->pipeline === null) {
            return null;
        }

        $event = SuchakPipelineEvent::query()
            ->where('pipeline_id', $request->pipeline->id)
            ->whereIn('event_type', [
                SuchakPipelineEvent::EVENT_CANDIDATE_INTERESTED,
                SuchakPipelineEvent::EVENT_CANDIDATE_NOT_INTERESTED,
            ])
            ->orderByDesc('id')
            ->first();

        if ($event === null) {
            return null;
        }

        return [
            'role' => $event->actor_type === SuchakPipelineEvent::ACTOR_CANDIDATE ? 'candidate' : 'suchak',
            'user_id' => $event->actor_id !== null ? (int) $event->actor_id : null,
            'at' => $event->created_at,
        ];
    }

    public function isAlreadyAnswered(SuchakProfileRequest $request): bool
    {
        return in_array($request->request_status, [
            SuchakProfileRequest::STATUS_CANDIDATE_INTERESTED,
            SuchakProfileRequest::STATUS_CANDIDATE_NOT_INTERESTED,
        ], true);
    }

    /**
     * True when the candidate is a real platform member who can answer for
     * themselves. When false the Suchak is the only possible answerer, so there
     * is no race to settle.
     */
    public function candidateCanAnswer(SuchakProfileRequest $request): bool
    {
        $request->loadMissing('targetMatrimonyProfile.user');

        return $request->targetMatrimonyProfile?->user !== null;
    }

    /**
     * Consent is what makes a Suchak the person's representative. Once it is
     * gone the Suchak may not answer for them — reuses the SAME predicate
     * (hasValidConsent) every other Suchak surface uses.
     */
    private function assertSuchakMayActOnRequest(SuchakProfileRequest $request): void
    {
        $request->loadMissing('representation');
        $representation = $request->representation;

        if ($representation === null
            || $representation->representation_status !== SuchakProfileRepresentation::STATUS_ACTIVE
            || ! $representation->hasValidConsent()) {
            throw new InvalidArgumentException(__('profile.suchak_request_consent_required'));
        }
    }

    /**
     * @return 'suchak'|'candidate'
     */
    private function resolveDecisionActorRole(
        SuchakProfileRequest $request,
        User $actor,
        ?SuchakAccount $account,
    ): string {
        if ($account !== null) {
            if ((int) $request->selected_suchak_account_id !== (int) $account->id) {
                throw new InvalidArgumentException(__('profile.suchak_request_not_yours'));
            }

            return SuchakPipelineEvent::ACTOR_SUCHAK;
        }

        $request->loadMissing('targetMatrimonyProfile');

        if ((int) ($request->targetMatrimonyProfile?->user_id ?? 0) === (int) $actor->id) {
            return SuchakPipelineEvent::ACTOR_CANDIDATE;
        }

        throw new InvalidArgumentException(__('profile.suchak_request_decision_not_allowed'));
    }

    /**
     * Notify the member of the outcome through the SAME member↔candidate chat
     * the Suchak reply uses. Best-effort by design: a chat policy block must
     * never lose the family's answer, which is already committed above.
     */
    private function postDecisionToExistingChat(
        SuchakProfileRequest $request,
        string $actorRole,
        string $decision,
        ?string $note,
        ?SuchakAccount $account,
    ): ?Message {
        $requestingProfile = $request->requestingMatrimonyProfile;
        $targetProfile = $request->targetMatrimonyProfile;

        if ($requestingProfile === null
            || $targetProfile === null
            || ! $this->profileIsActive($requestingProfile)
            || ! $this->profileIsActive($targetProfile)) {
            return null;
        }

        $line = $decision === self::DECISION_INTERESTED
            ? __('profile.suchak_request_decision_chat_interested')
            : __('profile.suchak_request_decision_chat_not_interested');

        $note = $this->nullableLimitedString($note, 500);
        if ($note !== null) {
            $line .= "\n".$note;
        }

        $body = $actorRole === SuchakPipelineEvent::ACTOR_SUCHAK && $account !== null
            ? $this->suchakReplyChatBody($account, $line)
            : $line;

        try {
            $conversation = $this->chatConversationService->findOrCreateConversationBetweenProfiles(
                $targetProfile,
                $requestingProfile,
            );

            return $this->chatMessageService->sendTextMessage(
                $targetProfile,
                $requestingProfile,
                $conversation,
                $body,
            );
        } catch (ValidationException) {
            return null;
        }
    }

    private function assertRequestCanBeCreated(
        User $requestingUser,
        MatrimonyProfile $requestingProfile,
        SuchakProfileRepresentation $representation,
    ): void {
        if ((int) $requestingProfile->user_id !== (int) $requestingUser->id) {
            throw new InvalidArgumentException('Requesting user must own the requesting matrimony profile.');
        }

        if ((int) $requestingProfile->id === (int) $representation->matrimony_profile_id) {
            throw new InvalidArgumentException('Requesting and target profiles must be different.');
        }

        if (! $this->profileIsActive($requestingProfile)) {
            throw new InvalidArgumentException('Requesting profile must be active to create a Suchak request.');
        }

        if (! $this->profileIsActive($representation->matrimonyProfile)) {
            throw new InvalidArgumentException('Target profile must be active to create a Suchak request.');
        }

        if ((int) $representation->suchak_account_id !== (int) $representation->suchakAccount?->id) {
            throw new InvalidArgumentException('Selected representation does not belong to the selected Suchak account.');
        }

        if (! $this->accessService->canPubliclyRoute($representation->suchakAccount)) {
            throw new InvalidArgumentException('Selected Suchak must be verified and publicly active.');
        }

        if ($representation->representation_status !== SuchakProfileRepresentation::STATUS_ACTIVE || ! $representation->hasValidConsent()) {
            throw new InvalidArgumentException('Suchak request requires active representation with valid consent.');
        }
    }

    private function assertNoDuplicateOpenRequest(
        MatrimonyProfile $requestingProfile,
        SuchakProfileRepresentation $representation,
    ): void {
        $duplicate = SuchakProfileRequest::query()
            ->where('requesting_matrimony_profile_id', $requestingProfile->id)
            ->where('target_matrimony_profile_id', $representation->matrimony_profile_id)
            ->where('selected_suchak_account_id', $representation->suchak_account_id)
            ->whereIn('request_status', SuchakProfileRequest::OPEN_STATUSES)
            ->exists();

        if ($duplicate) {
            throw new InvalidArgumentException('An open Suchak request already exists for this selected Suchak.');
        }
    }

    private function requestSlaHours(): int
    {
        return $this->limitService->requestActionSlaHours();
    }

    private function profileIsActive(?MatrimonyProfile $profile): bool
    {
        return $profile !== null
            && ($profile->lifecycle_state ?? null) === 'active'
            && (bool) ($profile->is_suspended ?? false) === false;
    }

    private function recordPipelineEvent(
        SuchakPipeline $pipeline,
        string $eventType,
        string $actorType,
        ?int $actorId,
        ?string $eventNote = null,
    ): SuchakPipelineEvent {
        return SuchakPipelineEvent::query()->create([
            'pipeline_id' => $pipeline->id,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'event_note' => $eventNote,
            'created_at' => now(),
        ]);
    }

    private function nullableLimitedString(mixed $value, int $limit): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === ''
            ? null
            : Str::limit($normalized, $limit, '');
    }

    private function storeInitialRequestMessageInExistingChat(
        SuchakProfileRequest $request,
        MatrimonyProfile $requestingProfile,
        ?MatrimonyProfile $targetProfile,
    ): ?Message {
        $messageText = trim((string) ($request->message ?? ''));
        if ($messageText === '' || $targetProfile === null) {
            return null;
        }

        $conversation = $this->chatConversationService->findOrCreateConversationBetweenProfiles(
            $requestingProfile,
            $targetProfile,
        );

        try {
            $message = $this->chatMessageService->sendTextMessage(
                $requestingProfile,
                $targetProfile,
                $conversation,
                $messageText,
            );
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $message = $errors['policy'][0] ?? $errors['body_text'][0] ?? $e->getMessage();

            throw new InvalidArgumentException($message);
        }

        SuchakProfileRequest::query()
            ->whereKey($request->id)
            ->update([
                'chat_conversation_id' => $conversation->id,
                'request_chat_message_id' => $message->id,
                'updated_at' => now(),
            ]);

        return $message;
    }

    private function normalizeReplyText(string $replyText): string
    {
        $normalized = trim($replyText);
        if ($normalized === '') {
            throw new InvalidArgumentException('Reply message cannot be empty.');
        }
        if (mb_strlen($normalized) > 1600) {
            throw new InvalidArgumentException('Reply message must not be greater than 1600 characters.');
        }

        return $normalized;
    }

    private function suchakReplyChatBody(SuchakAccount $account, string $replyText): string
    {
        $displayName = trim((string) (
            $account->office_name_mr
            ?: $account->office_name
            ?: $account->suchak_name_mr
            ?: $account->suchak_name
            ?: 'Suchak'
        ));

        return "सूचकांकडून संदेश ({$displayName}):\n".$replyText;
    }
}
