<?php

namespace App\Modules\Suchak\Services;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRequest;
use App\Services\Chat\ChatApiPresenter;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatPolicyService;
use App\Services\Chat\PolicyDecision;
use App\Services\ChatListService;
use App\Services\CommunicationPolicyService;

/**
 * The Suchak's view of the EXISTING member↔candidate conversation.
 *
 * One service so every Suchak surface answers identically — the request detail
 * screen and the chat screen are the same thread, not two readings of it.
 *
 * Two things it adds on top of the member chat contract, and nothing else:
 *
 *  1. AUTHORSHIP. A Suchak's reply is stored as sent by the CANDIDATE's profile
 *     with the "सूचकांकडून संदेश (…)" prefix — that is how the pipeline relays
 *     it. Rendered raw, a Suchak sees their own words as the candidate's. Every
 *     message therefore carries author_role (member|suchak|candidate) and the
 *     prefix is stripped from the body once it has been turned into attribution.
 *  2. SCOPE. Only conversations pinned by a request this Suchak owns, with a
 *     consent that still stands (SuchakChatAccessService).
 *
 * Sending, quotas and the reply-gate cooldown are NOT re-implemented here: they
 * stay in ChatPolicyService, surfaced verbatim through can_send so a cooldown
 * reads as the rule working rather than as a broken screen.
 */
class SuchakChatThreadService
{
    public const AUTHOR_MEMBER = 'member';
    public const AUTHOR_SUCHAK = 'suchak';
    public const AUTHOR_CANDIDATE = 'candidate';

    public function __construct(
        private readonly SuchakChatAccessService $access,
        private readonly SuchakRequestPipelineService $pipelineService,
        private readonly ChatApiPresenter $presenter,
        private readonly ChatMessageService $messages,
        private readonly ChatPolicyService $policy,
        private readonly ChatListService $chatList,
    ) {
    }

    /**
     * Every authorized conversation, newest first.
     */
    public function inbox(SuchakAccount $account): array
    {
        $requests = $this->access->authorizedRequests($account);
        $requestByConversation = $requests
            ->keyBy(fn (SuchakProfileRequest $row): int => (int) $row->chat_conversation_id);

        $rows = [];
        foreach ($this->access->conversationIdsByCustomerProfile($requests) as $profileId => $conversationIds) {
            $conversations = $this->chatList->getAllConversations((int) $profileId, $conversationIds);
            foreach ($conversations as $conversation) {
                $linked = $requestByConversation->get((int) $conversation->id);
                if (! $linked instanceof SuchakProfileRequest) {
                    continue;
                }

                $rows[] = $this->conversationSummary($conversation, $linked);
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp(
            (string) ($b['last_message_at'] ?? ''),
            (string) ($a['last_message_at'] ?? ''),
        ));

        return [
            'count' => count($rows),
            'unread_count' => $this->access->unreadCount($account, $requests),
            'conversations' => $rows,
        ];
    }

    /**
     * The whole exchange for one request: the member's original message, the
     * Suchak's own replies, and everything since.
     *
     * Returns null when the request has no conversation yet (nothing has been
     * written) — the caller renders the request without a thread rather than an
     * error.
     */
    public function threadForRequest(
        SuchakAccount $account,
        SuchakProfileRequest $request,
        int $sinceId = 0,
        bool $markRead = true,
    ): ?array {
        $conversationId = (int) ($request->chat_conversation_id ?? 0);
        if ($conversationId <= 0) {
            return null;
        }

        $conversation = Conversation::query()->find($conversationId);
        if (! $conversation instanceof Conversation) {
            return null;
        }

        $authorized = $this->access->authorizedRequestForConversation($account, $conversation);
        if (! $authorized instanceof SuchakProfileRequest) {
            return null;
        }

        return $this->thread($authorized, $conversation, $sinceId, $markRead);
    }

    /**
     * @param  SuchakProfileRequest  $request  already authorized by the caller
     */
    public function thread(
        SuchakProfileRequest $request,
        Conversation $conversation,
        int $sinceId = 0,
        bool $markRead = true,
    ): array {
        $customer = $request->targetMatrimonyProfile;
        $member = $request->requestingMatrimonyProfile;

        $messages = $this->presenter->threadMessages($conversation, $sinceId);
        $messages->loadMissing(['senderProfile', 'receiverProfile']);

        if ($markRead) {
            // The same single read state the member chat uses. There is no
            // second, Suchak-only read pointer.
            $this->messages->markConversationReadForRepresentative($customer, $conversation);
        }

        return [
            'profile_request_id' => (int) $request->id,
            'request_status' => (string) $request->request_status,
            'customer' => $this->presenter->profilePayload($customer),
            'conversation' => $this->conversationSummary(
                $conversation->fresh() ?? $conversation,
                $request,
                $member,
            ),
            'messages' => $messages
                ->map(fn (Message $message): array => $this->messagePayload($message, $customer, $member))
                ->values()
                ->all(),
            'last_id' => $messages->last()?->id,
            'can_send' => $this->canSendPayload($request, $customer, $member, $conversation),
        ];
    }

    public function conversationSummary(
        Conversation $conversation,
        SuchakProfileRequest $request,
        ?MatrimonyProfile $member = null,
    ): array {
        $customer = $request->targetMatrimonyProfile;
        $member ??= $request->requestingMatrimonyProfile;

        $payload = $this->presenter->conversationPayload($conversation, $customer, false, $member);

        // The list preview shows the raw relayed body otherwise — "सूचकांकडून
        // संदेश (…): …" reads as noise where the Suchak expects their own words.
        if (is_array($payload['last_message'] ?? null) && $conversation->lastMessage instanceof Message) {
            $payload['last_message'] = $this->messagePayload(
                $conversation->lastMessage,
                $customer,
                $member,
            );
            $payload['preview'] = $payload['last_message']['body_text'] ?? $payload['preview'];
        }

        $payload['profile_request_id'] = (int) $request->id;
        $payload['request_status'] = (string) $request->request_status;
        $payload['customer'] = $this->presenter->profilePayload($customer);

        return $payload;
    }

    /**
     * The member contract, plus who actually wrote it.
     */
    public function messagePayload(
        Message $message,
        ?MatrimonyProfile $customer,
        ?MatrimonyProfile $member,
    ): array {
        $payload = $customer instanceof MatrimonyProfile
            ? $this->presenter->messagePayload($message, $customer, false)
            : [];

        $fromCustomerSide = $customer instanceof MatrimonyProfile
            && (int) $message->sender_profile_id === (int) $customer->id;
        $relay = $fromCustomerSide
            ? $this->pipelineService->parseSuchakRelayedChatBody($message->body_text)
            : null;

        if ($relay !== null) {
            // The Suchak's own words, wearing the candidate's profile because
            // that is how the pipeline sends them.
            $payload['body_text'] = $relay['text'];
            $payload['preview_text'] = $relay['text'];
            $payload['author_role'] = self::AUTHOR_SUCHAK;
            $payload['author_label'] = $relay['suchak_name'];

            return $payload;
        }

        $payload['author_role'] = $fromCustomerSide ? self::AUTHOR_CANDIDATE : self::AUTHOR_MEMBER;
        $payload['author_label'] = trim((string) ($fromCustomerSide
            ? $customer?->full_name
            : $member?->full_name));

        return $payload;
    }

    /**
     * A Suchak may write only while the request is still open — the same
     * condition replyThroughExistingChat enforces. Beyond that the answer is
     * ChatPolicyService's, verbatim: daily/weekly/monthly caps and the reply
     * gate cooldown reach the app with the policy's own wording and
     * locked_until, and are never worked around here.
     *
     * The acting role is passed for the same reason the send path passes it —
     * this preview must agree with what the send will actually do, and the
     * candidate's profile id alone cannot say who is writing.
     */
    public function canSendPayload(
        SuchakProfileRequest $request,
        ?MatrimonyProfile $customer,
        ?MatrimonyProfile $member,
        Conversation $conversation,
    ): array {
        if (! $request->isOpen()) {
            return $this->presenter->policyPayload(
                PolicyDecision::deny('request_closed', (string) __('profile.suchak_request_not_open'))
            );
        }

        if (! $customer instanceof MatrimonyProfile || ! $member instanceof MatrimonyProfile) {
            return $this->presenter->policyPayload(
                PolicyDecision::deny('not_participant', (string) __('profile.suchak_request_not_open'))
            );
        }

        return $this->presenter->policyPayload(
            $this->policy->canSendMessage(
                $customer,
                $member,
                $conversation,
                CommunicationPolicyService::ACTOR_SUCHAK,
            )
        );
    }
}
