<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ChatListService
 *
 * SSOT for chat inbox list queries (no controllers/blades should query conversations/messages).
 *
 * Required output per conversation:
 * - lastMessage relation eager-loaded
 * - unread_count (per current profile)
 * - other_profile (joined from matrimony_profiles)
 */
final class ChatListService
{
    /**
     * All conversations for profile (sorted by last_message_at desc).
     *
     * @return Collection<int, Conversation>
     */
    public function getAllConversations(int $profileId): Collection
    {
        $rows = $this->baseListQuery($profileId)->get();
        return $this->hydrateListRows($rows);
    }

    /**
     * Conversations with unread messages for profile.
     *
     * Unread logic:
     * - receiver_profile_id = current_profile_id
     * - read_at is null
     *
     * @return Collection<int, Conversation>
     */
    public function getUnreadConversations(int $profileId): Collection
    {
        $rows = $this->baseListQuery($profileId)
            ->whereExists(function ($q) use ($profileId) {
                $q->select(DB::raw(1))
                    ->from('messages as mu')
                    ->whereColumn('mu.conversation_id', 'conversations.id')
                    ->where('mu.receiver_profile_id', $profileId)
                    ->whereNull('mu.read_at');
            })
            ->get();

        return $this->hydrateListRows($rows);
    }

    /**
     * Conversations that are "Requests" for profile.
     *
     * Request logic:
     * - current user has NOT replied yet
     * - i.e. no message exists with sender_profile_id = current_profile_id
     *
     * @return Collection<int, Conversation>
     */
    public function getRequestConversations(int $profileId): Collection
    {
        $rows = $this->baseListQuery($profileId)
            ->whereNotExists(function ($q) use ($profileId) {
                $q->select(DB::raw(1))
                    ->from('messages as mr')
                    ->whereColumn('mr.conversation_id', 'conversations.id')
                    ->where('mr.sender_profile_id', $profileId);
            })
            ->get();

        return $this->hydrateListRows($rows);
    }

    /**
     * Unread total count across all conversations (for badges/polling).
     */
    public function getUnreadMessageCount(int $profileId): int
    {
        return (int) DB::table('messages')
            ->where('receiver_profile_id', $profileId)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Base list query:
     * - only conversations where profile participates
     * - join other participant profile via CASE expression
     * - add unread_count via subquery
     * - eager-load lastMessage
     * - sort by last_message_at desc
     *
     * @return Builder<Conversation>
     */
    private function baseListQuery(int $profileId): Builder
    {
        $otherIdExpr = "CASE WHEN conversations.profile_one_id = ? THEN conversations.profile_two_id ELSE conversations.profile_one_id END";

        $query = Conversation::query()
            ->from('conversations')
            ->where(function ($q) use ($profileId) {
                $q->where('conversations.profile_one_id', $profileId)
                    ->orWhere('conversations.profile_two_id', $profileId);
            })
            ->leftJoin('matrimony_profiles as other', function ($join) use ($otherIdExpr, $profileId) {
                // join other.id = (profile_one == me ? profile_two : profile_one)
                $join->on('other.id', '=', DB::raw($otherIdExpr));
                $join->addBinding($profileId, 'join');
            })
            ->select([
                'conversations.*',
                DB::raw('(SELECT COUNT(*) FROM messages m1 WHERE m1.conversation_id = conversations.id AND m1.receiver_profile_id = ' . (int) $profileId . ' AND m1.read_at IS NULL) as unread_count'),
                'other.id as other_id',
                'other.full_name as other_full_name',
                'other.profile_photo as other_profile_photo',
                'other.photo_approved as other_photo_approved',
                'other.gender_id as other_gender_id',
                'other.user_id as other_user_id',
            ])
            ->with(['lastMessage'])
            ->orderByDesc('conversations.last_message_at')
            ->orderByDesc('conversations.id');

        return $query;
    }

    /**
     * Attach:
     * - unread_count as int
     * - other_profile relation as MatrimonyProfile|null (joined)
     *
     * @param  Collection<int, Conversation>  $rows
     * @return Collection<int, Conversation>
     */
    private function hydrateListRows(Collection $rows): Collection
    {
        /** @var Conversation $c */
        foreach ($rows as $c) {
            $c->setAttribute('unread_count', (int) ($c->getAttribute('unread_count') ?? 0));

            $other = null;
            $otherId = (int) ($c->getAttribute('other_id') ?? 0);
            if ($otherId > 0) {
                $other = new MatrimonyProfile([
                    'id' => $otherId,
                    'full_name' => $c->getAttribute('other_full_name'),
                    'profile_photo' => $c->getAttribute('other_profile_photo'),
                    'photo_approved' => $c->getAttribute('other_photo_approved'),
                    'gender_id' => $c->getAttribute('other_gender_id'),
                    'user_id' => $c->getAttribute('other_user_id'),
                ]);
                $other->exists = true;
            }

            $c->setRelation('other_profile', $other);
        }

        return $rows;
    }
}

