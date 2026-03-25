<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;

class ChatConversationService
{
    public function findConversationBetweenProfiles(int $aProfileId, int $bProfileId): ?Conversation
    {
        [$p1, $p2] = Conversation::normalizePairIds($aProfileId, $bProfileId);

        return Conversation::query()
            ->where('profile_one_id', $p1)
            ->where('profile_two_id', $p2)
            ->first();
    }

    public function findOrCreateConversationBetweenProfiles(MatrimonyProfile $sender, MatrimonyProfile $receiver): Conversation
    {
        [$p1, $p2] = Conversation::normalizePairIds($sender->id, $receiver->id);

        return Conversation::firstOrCreate(
            [
                'profile_one_id' => $p1,
                'profile_two_id' => $p2,
            ],
            [
                'created_by_profile_id' => $sender->id,
                'status' => Conversation::STATUS_ACTIVE,
            ]
        );
    }

    public function listConversationsForProfile(MatrimonyProfile $profile)
    {
        return Conversation::query()
            ->where(function ($q) use ($profile) {
                $q->where('profile_one_id', $profile->id)->orWhere('profile_two_id', $profile->id);
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();
    }

    public function getOtherParticipant(Conversation $conversation, MatrimonyProfile $me): ?MatrimonyProfile
    {
        if ((int) $conversation->profile_one_id === (int) $me->id) {
            return MatrimonyProfile::find($conversation->profile_two_id);
        }
        if ((int) $conversation->profile_two_id === (int) $me->id) {
            return MatrimonyProfile::find($conversation->profile_one_id);
        }
        return null;
    }
}

