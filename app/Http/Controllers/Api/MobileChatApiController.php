<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\ChatApiPresenter;
use App\Services\Chat\ChatConversationService;
use App\Services\Chat\ChatMessageModerationService;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatPolicyService;
use App\Services\Chat\PolicyDecision;
use App\Services\ChatListService;
use App\Services\FeatureUsageService;
use App\Services\QuotaEngineService;
use App\Services\ShowcaseChat\ShowcaseConversationTagService;
use App\Services\ShowcaseChat\ShowcaseOrchestrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MobileChatApiController extends Controller
{
    public function __construct(
        protected ChatPolicyService $policy,
        protected ChatConversationService $conversations,
        protected ChatMessageService $messages,
        protected ChatListService $chatList,
        protected FeatureUsageService $featureUsage,
        protected QuotaEngineService $quotaEngine,
        protected ChatMessageModerationService $moderation,
        protected ChatApiPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $context = $this->viewerContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$user, $me] = $context;
        $tab = (string) $request->query('tab', 'all');
        if (! in_array($tab, ['all', 'unread', 'requests'], true)) {
            $tab = 'all';
        }

        $conversations = match ($tab) {
            'unread' => $this->chatList->getUnreadConversations((int) $me->id),
            'requests' => $this->chatList->getRequestConversations((int) $me->id),
            default => $this->chatList->getAllConversations((int) $me->id),
        };

        $readLocked = ! $this->featureUsage->canUse((int) $user->id, FeatureUsageService::FEATURE_CHAT_CAN_READ);

        return response()->json([
            'success' => true,
            'message' => 'Chats loaded.',
            'tab' => $tab,
            'unread_count' => $this->chatList->getUnreadMessageCount((int) $me->id),
            'read_locked_for_incoming' => $readLocked,
            'conversations' => $conversations
                ->map(fn (Conversation $conversation): array => $this->conversationPayload($conversation, $me, $readLocked))
                ->values()
                ->all(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $context = $this->viewerContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [, $me] = $context;

        return response()->json([
            'success' => true,
            'unread_count' => $this->chatList->getUnreadMessageCount((int) $me->id),
        ]);
    }

    public function start(Request $request, int $id): JsonResponse
    {
        $context = $this->viewerContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [, $me] = $context;
        $target = MatrimonyProfile::query()->with(['user', 'gender', 'religion', 'caste', 'location'])->find($id);
        if (! $target instanceof MatrimonyProfile) {
            return $this->error('Profile not found.', 404);
        }

        $existing = $this->conversations->findConversationBetweenProfiles((int) $me->id, (int) $target->id);
        if (! $existing instanceof Conversation) {
            $decision = $this->policy->canStartConversation($me, $target);
            if (! $decision->allowed) {
                return $this->policyError($decision, 422);
            }
        } else {
            $decision = $this->policy->canAccessMessaging($me, $target);
            if (! $decision->allowed) {
                return $this->policyError($decision, 422);
            }
        }

        $conversation = $existing ?: $this->conversations->findOrCreateConversationBetweenProfiles($me, $target);
        $conversation->loadMissing(['lastMessage.senderProfile', 'lastMessage.receiverProfile']);
        $readLocked = $this->readLocked($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Chat is ready.',
            'conversation' => $this->conversationPayload($conversation, $me, $readLocked, $target),
            'can_send' => $this->policyPayload($this->policy->canSendMessage($me, $target, $conversation)),
        ]);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $context = $this->viewerContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$user, $me] = $context;
        if (! $this->isParticipant($conversation, $me)) {
            return $this->error('You cannot view this chat.', 403);
        }

        $other = $this->conversations->getOtherParticipant($conversation, $me);
        if (! $other instanceof MatrimonyProfile) {
            return $this->error('Chat participant not found.', 404);
        }

        $sinceId = max(0, (int) $request->query('since_id', 0));
        $readLocked = ! $this->featureUsage->canUse((int) $user->id, FeatureUsageService::FEATURE_CHAT_CAN_READ);
        $messages = $this->threadMessages($conversation, $sinceId);
        $messages->loadMissing(['senderProfile', 'receiverProfile']);

        if (! $readLocked) {
            $this->messages->markConversationRead($me, $conversation);
        }

        $showcase = $this->showcaseStatus($conversation, $me, $other);

        return response()->json([
            'success' => true,
            'message' => 'Chat loaded.',
            'conversation' => $this->conversationPayload($conversation->fresh() ?? $conversation, $me, $readLocked, $other),
            'messages' => $messages
                ->map(fn (Message $message): array => $this->messagePayload($message, $me, $readLocked))
                ->values()
                ->all(),
            'last_id' => $messages->last()?->id,
            'read_locked_for_incoming' => $readLocked,
            'can_send' => $this->policyPayload($this->policy->canSendMessage($me, $other, $conversation)),
            'showcase' => $showcase,
        ]);
    }

    public function sendText(Request $request, Conversation $conversation): JsonResponse
    {
        $context = $this->viewerContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$user, $me] = $context;
        if (! $this->isParticipant($conversation, $me)) {
            return $this->error('You cannot send messages in this chat.', 403);
        }

        $other = $this->conversations->getOtherParticipant($conversation, $me);
        if (! $other instanceof MatrimonyProfile) {
            return $this->error('Chat participant not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'body_text' => 'required|string|max:2000',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $body = (string) $validator->validated()['body_text'];
        $moderation = $this->moderation->moderate($body);
        if (in_array($moderation['severity'], ['block', 'warn'], true)) {
            return $this->validationError([
                'body_text' => [$moderation['user_safe_message']],
            ]);
        }

        if (! $this->quotaEngine->canAccessFeature($user, 'chat', [
            'conversation_id' => (int) $conversation->id,
            'sender_profile_id' => (int) $me->id,
        ])) {
            return $this->error((string) __('subscriptions.chat_limit_json'), 403, 'chat_limit');
        }

        try {
            $message = $this->messages->sendTextMessage($me, $other, $conversation, $body);
        } catch (ValidationException $exception) {
            return $this->validationException($exception);
        }

        $this->featureUsage->consumeChatSendAfterMessage((int) $user->id, (int) $conversation->id, (int) $me->id);
        $message->loadMissing(['senderProfile', 'receiverProfile']);
        $conversation->refresh()->loadMissing(['lastMessage.senderProfile', 'lastMessage.receiverProfile']);
        $readLocked = $this->readLocked($user);

        return response()->json([
            'success' => true,
            'message' => 'Message sent.',
            'chat_message' => $this->messagePayload($message, $me, $readLocked),
            'conversation' => $this->conversationPayload($conversation, $me, $readLocked, $other),
            'can_send' => $this->policyPayload($this->policy->canSendMessage($me, $other, $conversation)),
        ]);
    }

    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $context = $this->viewerContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$user, $me] = $context;
        if (! $this->isParticipant($conversation, $me)) {
            return $this->error('You cannot update this chat.', 403);
        }

        $canRead = $this->featureUsage->canUse((int) $user->id, FeatureUsageService::FEATURE_CHAT_CAN_READ);
        if ($canRead) {
            $this->messages->markConversationRead($me, $conversation);
        }

        return response()->json([
            'success' => true,
            'message' => $canRead ? 'Chat marked as read.' : 'Chat read access is locked for your current plan.',
            'read_locked_for_incoming' => ! $canRead,
            'unread_count' => $this->chatList->getUnreadMessageCount((int) $me->id),
        ]);
    }

    /**
     * @return array{0: User, 1: MatrimonyProfile}|JsonResponse
     */
    private function viewerContext(Request $request): array|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $profile = $user->matrimonyProfile;
        if (! $profile instanceof MatrimonyProfile) {
            return $this->error('Please create your profile first.', 422);
        }

        return [$user, $profile];
    }

    private function isParticipant(Conversation $conversation, MatrimonyProfile $profile): bool
    {
        return in_array((int) $profile->id, [
            (int) $conversation->profile_one_id,
            (int) $conversation->profile_two_id,
        ], true);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Message>
     */
    private function threadMessages(Conversation $conversation, int $sinceId)
    {
        return $this->presenter->threadMessages($conversation, $sinceId);
    }

    private function conversationPayload(
        Conversation $conversation,
        MatrimonyProfile $viewer,
        bool $readLockedForIncoming,
        ?MatrimonyProfile $other = null
    ): array {
        return $this->presenter->conversationPayload($conversation, $viewer, $readLockedForIncoming, $other);
    }

    private function messagePayload(Message $message, MatrimonyProfile $viewer, bool $readLockedForIncoming): array
    {
        return $this->presenter->messagePayload($message, $viewer, $readLockedForIncoming);
    }

    private function policyPayload(PolicyDecision $decision): array
    {
        return $this->presenter->policyPayload($decision);
    }

    private function showcaseStatus(Conversation $conversation, MatrimonyProfile $viewer, MatrimonyProfile $other): ?array
    {
        try {
            $showTag = app(ShowcaseConversationTagService::class)
                ->shouldShowTagForConversation($conversation, $viewer, $other);
            if (! $other->isShowcaseProfile() || ! $showTag) {
                return null;
            }

            return app(ShowcaseOrchestrationService::class)->tickConversation($conversation, $other, $viewer);
        } catch (\Throwable) {
            return ['online' => false, 'typing' => false];
        }
    }

    private function readLocked(?User $user): bool
    {
        return ! $user instanceof User
            || ! $this->featureUsage->canUse((int) $user->id, FeatureUsageService::FEATURE_CHAT_CAN_READ);
    }

    private function dateValue(mixed $value): ?string
    {
        return $this->presenter->dateValue($value);
    }

    private function policyError(PolicyDecision $decision, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $decision->humanMessage !== '' ? $decision->humanMessage : 'Chat is not available.',
            'reason' => $decision->code,
            'locked_until' => $this->dateValue($decision->lockedUntil),
            'meta' => $decision->meta,
        ], $status);
    }

    private function validationException(ValidationException $exception): JsonResponse
    {
        return $this->validationError($exception->errors(), $exception->getMessage());
    }

    private function validationError(array $errors, ?string $fallbackMessage = null): JsonResponse
    {
        $message = collect($errors)->flatten()->first() ?: $fallbackMessage ?: 'The given data was invalid.';

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], 422);
    }

    private function error(string $message, int $status, ?string $reason = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        return response()->json($payload, $status);
    }
}
