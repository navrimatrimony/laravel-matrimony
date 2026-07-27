<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRequest;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakChatAccessService;
use App\Modules\Suchak\Services\SuchakChatThreadService;
use App\Modules\Suchak\Services\SuchakRequestPipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The Suchak's READ side of the member↔candidate chat.
 *
 * Until now the pipeline was one-way: POST /suchak/profile-requests/{id}/reply
 * pushed the Suchak's words into the member↔candidate conversation, and nothing
 * ever brought the member's answer back. The Suchak was handling a match they
 * could not hear.
 *
 * Nothing here is a new messaging system. Reads are SuchakChatThreadService
 * over the member chat contract; sends are the EXISTING
 * SuchakRequestPipelineService::replyThroughExistingChat, i.e. literally the
 * same call the /reply endpoint makes — same prefix, same ChatPolicyService
 * quota and reply-gate path, same audit trail. No new gate, no exemption.
 *
 * Polling only (`?since_id=`), matching the member app. No realtime layer.
 */
class SuchakChatApiController extends Controller
{
    public function __construct(
        private readonly SuchakChatAccessService $access,
        private readonly SuchakChatThreadService $threads,
        private readonly SuchakRequestPipelineService $pipelineService,
    ) {
    }

    /**
     * GET /api/v1/suchak/chats
     */
    public function index(Request $request): JsonResponse
    {
        $account = $this->account($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        return response()->json([
            'success' => true,
            'message' => 'Chats loaded.',
            'data' => $this->threads->inbox($account),
        ]);
    }

    /**
     * GET /api/v1/suchak/chats/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $account = $this->account($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        return response()->json([
            'success' => true,
            'data' => ['unread_count' => $this->access->unreadCount($account)],
        ]);
    }

    /**
     * GET /api/v1/suchak/chats/{conversation}?since_id=
     */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $context = $this->threadContext($request, $conversation);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [, $profileRequest] = $context;
        $sinceId = max(0, (int) $request->query('since_id', 0));

        return response()->json([
            'success' => true,
            'message' => 'Chat loaded.',
            'data' => $this->threads->thread($profileRequest, $conversation, $sinceId),
        ]);
    }

    /**
     * POST /api/v1/suchak/chats/{conversation}/messages
     */
    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $context = $this->threadContext($request, $conversation);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$account, $profileRequest, $customer, $member] = $context;

        $validated = $request->validate([
            'body_text' => ['required', 'string', 'max:1600'],
        ]);

        try {
            $result = $this->pipelineService->replyThroughExistingChat(
                $profileRequest,
                $account,
                $request->user(),
                (string) $validated['body_text'],
                $request->ip(),
                (string) $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (ValidationException $exception) {
            // A ChatPolicyService denial (daily/weekly/monthly cap, reply-gate
            // cooldown, blocked pair, inactive profile) reaches the app with the
            // policy's own wording. It is the rule working, not a failure to
            // route around.
            return response()->json([
                'success' => false,
                'message' => collect($exception->errors())->flatten()->first() ?: $exception->getMessage(),
                'errors' => $exception->errors(),
            ], 422);
        }

        /** @var Message $message */
        $message = $result['message'];
        $message->loadMissing(['senderProfile', 'receiverProfile']);
        $conversation->refresh()->loadMissing(['lastMessage.senderProfile', 'lastMessage.receiverProfile']);
        $updatedRequest = $result['request'] instanceof SuchakProfileRequest ? $result['request'] : $profileRequest;
        $updatedRequest->setRelation('targetMatrimonyProfile', $customer);
        $updatedRequest->setRelation('requestingMatrimonyProfile', $member);

        return response()->json([
            'success' => true,
            'message' => 'Message sent.',
            'data' => [
                'profile_request_id' => (int) $updatedRequest->id,
                'request_status' => (string) $updatedRequest->request_status,
                'chat_message' => $this->threads->messagePayload($message, $customer, $member),
                'conversation' => $this->threads->conversationSummary($conversation, $updatedRequest, $member),
                'can_send' => $this->threads->canSendPayload($updatedRequest, $customer, $member, $conversation),
            ],
        ]);
    }

    /**
     * POST /api/v1/suchak/chats/{conversation}/read
     */
    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $context = $this->threadContext($request, $conversation);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$account, $profileRequest] = $context;

        $this->threads->thread($profileRequest, $conversation, 0, true);

        return response()->json([
            'success' => true,
            'message' => 'Chat marked as read.',
            'data' => ['unread_count' => $this->access->unreadCount($account)],
        ]);
    }

    /**
     * @return array{0: SuchakAccount, 1: SuchakProfileRequest, 2: MatrimonyProfile, 3: MatrimonyProfile}|JsonResponse
     */
    private function threadContext(Request $request, Conversation $conversation): array|JsonResponse
    {
        $account = $this->account($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        $profileRequest = $this->access->authorizedRequestForConversation($account, $conversation);
        if (! $profileRequest instanceof SuchakProfileRequest) {
            return $this->error((string) __('profile.suchak_request_not_yours'), 403);
        }

        $customer = $profileRequest->targetMatrimonyProfile;
        $member = $profileRequest->requestingMatrimonyProfile;
        if (! $customer instanceof MatrimonyProfile || ! $member instanceof MatrimonyProfile) {
            return $this->error('Chat participant not found.', 404);
        }

        return [$account, $profileRequest, $customer, $member];
    }

    private function account(Request $request): SuchakAccount|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $account = $user->suchakAccount;
        if (! $account instanceof SuchakAccount) {
            return $this->error('Suchak account is required to access this section.', 403);
        }

        return $account;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
