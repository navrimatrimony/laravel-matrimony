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
