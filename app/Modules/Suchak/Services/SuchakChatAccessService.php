<?php

namespace App\Modules\Suchak\Services;

use App\Models\Conversation;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRequest;
use App\Services\ChatListService;
use Illuminate\Support\Collection;

/**
 * Authorization + scoping for the Suchak's READ side of the existing chat.
 *
 * There is no Suchak inbox table and no Suchak conversation. A Suchak sees a
 * thread only because a SuchakProfileRequest they own (selected_suchak_account_id)
 * already pinned that thread via chat_conversation_id — the same column the
 * reply path writes. The viewer identity inside the thread is the *customer's*
 * profile (the candidate the Suchak represents), which is exactly the identity
 * the Suchak already writes as.
 *
 * Two independent gates, both reused rather than reinvented:
 *  - ownership: selected_suchak_account_id === this account
 *  - consent:   SuchakRequestPipelineService::suchakMayActOnRequest() — the same
 *               predicate that gates replying, so a revoked consent closes read
 *               and write together.
 */
class SuchakChatAccessService
{
    public function __construct(
        private readonly SuchakRequestPipelineService $pipelineService,
        private readonly ChatListService $chatList,
    ) {
    }

    /**
     * Every request of this Suchak that already has a live chat thread and a
     * consent that still stands. Newest thread first.
     *
     * @return Collection<int, SuchakProfileRequest>
     */
    public function authorizedRequests(SuchakAccount $account): Collection
    {
        return SuchakProfileRequest::query()
            ->whereNotNull('chat_conversation_id')
            ->where('selected_suchak_account_id', $account->id)
            ->with([
                'representation',
                'requestingMatrimonyProfile',
                'targetMatrimonyProfile',
            ])
            ->orderByDesc('id')
            ->get()
            // One conversation can carry more than one request over time
            // (same member re-approaching the same candidate). The newest
            // request owns the thread.
            ->unique(fn (SuchakProfileRequest $row): int => (int) $row->chat_conversation_id)
            ->filter(fn (SuchakProfileRequest $row): bool => $this->isReadable($row))
            ->values();
    }

    /**
     * The request that authorizes this Suchak to see this conversation, or null.
     */
    public function authorizedRequestForConversation(
        SuchakAccount $account,
        Conversation $conversation,
    ): ?SuchakProfileRequest {
        $request = SuchakProfileRequest::query()
            ->where('chat_conversation_id', $conversation->id)
            ->where('selected_suchak_account_id', $account->id)
            ->with([
                'pipeline',
                'representation',
                'requestingMatrimonyProfile.gender',
                'requestingMatrimonyProfile.religion',
                'requestingMatrimonyProfile.caste',
                'requestingMatrimonyProfile.location',
                'targetMatrimonyProfile.gender',
                'targetMatrimonyProfile.religion',
                'targetMatrimonyProfile.caste',
                'targetMatrimonyProfile.location',
            ])
            ->orderByDesc('id')
            ->first();

        if (! $request instanceof SuchakProfileRequest || ! $this->isReadable($request)) {
            return null;
        }

        return $request;
    }

    /**
     * Unread member messages waiting for this Suchak, across every thread they
     * are authorized on. Scoped per customer profile so a Suchak's badge never
     * leaks the customer's unrelated conversations.
     *
     * @param  Collection<int, SuchakProfileRequest>|null  $requests
     */
    public function unreadCount(SuchakAccount $account, ?Collection $requests = null): int
    {
        $requests ??= $this->authorizedRequests($account);

        $total = 0;
        foreach ($this->conversationIdsByCustomerProfile($requests) as $profileId => $conversationIds) {
            $total += $this->chatList->getUnreadMessageCount((int) $profileId, $conversationIds);
        }

        return $total;
    }

    /**
     * customer profile id => [conversation ids the Suchak may see for them]
     *
     * @param  Collection<int, SuchakProfileRequest>  $requests
     * @return array<int, list<int>>
     */
    public function conversationIdsByCustomerProfile(Collection $requests): array
    {
        $map = [];
        foreach ($requests as $request) {
            $profileId = (int) $request->target_matrimony_profile_id;
            $conversationId = (int) $request->chat_conversation_id;
            if ($profileId <= 0 || $conversationId <= 0) {
                continue;
            }

            $map[$profileId][] = $conversationId;
        }

        return $map;
    }

    private function isReadable(SuchakProfileRequest $request): bool
    {
        return $request->targetMatrimonyProfile !== null
            && $request->requestingMatrimonyProfile !== null
            && $this->pipelineService->suchakMayActOnRequest($request);
    }
}
