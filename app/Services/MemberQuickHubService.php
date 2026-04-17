<?php

namespace App\Services;

use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MemberQuickHubService
{
    public function __construct(
        private readonly ChatListService $chatList,
        private readonly FeatureUsageService $featureUsage,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function buildChatDockForUser(?User $user): ?array
    {
        if (! $user || ! $user->matrimonyProfile) {
            return null;
        }

        $profile = $user->matrimonyProfile;
        $profileId = (int) $profile->id;
        $userId = (int) $user->id;

        $allConversations = $this->chatList->getAllConversations($profileId);
        $unreadConversations = $this->chatList->getUnreadConversations($profileId);
        $chatUnreadCount = $this->chatList->getUnreadMessageCount($profileId);
        $detailMap = $this->loadProfileDetailMap(
            $allConversations->pluck('other_id')->filter()->map(fn ($v) => (int) $v)->all()
        );
        $activeChats = $this->mapConversationsForDock($allConversations->take(10), $detailMap);
        $unreadChats = $this->mapConversationsForDock($unreadConversations->take(10), $detailMap);

        return [
            'unread_count' => $chatUnreadCount,
            'active' => $activeChats,
            'unread' => $unreadChats,
            'strip' => collect($activeChats)->take(7)->values()->all(),
            'all_url' => route('chat.index'),
            'can_read_incoming' => $this->featureUsage->canUse($userId, FeatureUsageService::FEATURE_CHAT_CAN_READ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildChatDockSnapshotForUser(?User $user): ?array
    {
        return $this->buildChatDockForUser($user);
    }

    /**
     * @param  iterable<int, mixed>  $conversations
     * @param  array<int, array<string, string>>  $detailMap
     * @return array<int, array<string, mixed>>
     */
    private function mapConversationsForDock(iterable $conversations, array $detailMap): array
    {
        return collect($conversations)->map(function ($conversation) use ($detailMap) {
            $last = $conversation->lastMessage;
            $preview = 'No messages yet';
            if ($last) {
                if (($last->message_type ?? 'text') === 'image') {
                    $preview = 'Image';
                } else {
                    $preview = Str::limit(trim((string) ($last->body_text ?? '')), 45);
                }
            }

            $otherId = (int) ($conversation->other_profile?->id ?? 0);
            $detail = $detailMap[$otherId] ?? [
                'title' => 'Profile summary',
                'subtitle' => 'Details are being updated',
                'location' => 'Location not specified',
            ];

            return [
                'conversation_id' => (int) $conversation->id,
                'name' => (string) ($conversation->other_profile?->full_name ?: 'Member'),
                'avatar_url' => (string) ($conversation->other_profile?->profile_photo_url ?: ''),
                'profile_url' => (int) ($conversation->other_profile?->id ?? 0) > 0
                    ? route('matrimony.profile.show', ['matrimony_profile_id' => (int) $conversation->other_profile?->id])
                    : route('chat.index'),
                'url' => route('chat.show', ['conversation' => $conversation->id]),
                'send_url' => route('chat.messages.text', ['conversation' => $conversation->id]),
                'preview' => $preview,
                'unread' => (int) ($conversation->unread_count ?? 0),
                'time' => $conversation->last_message_at?->diffForHumans(),
                'profile_title' => $detail['title'],
                'profile_subtitle' => $detail['subtitle'],
                'profile_location' => $detail['location'],
            ];
        })->values()->all();
    }

    /**
     * @param  list<int>  $profileIds
     * @return array<int, array<string, string>>
     */
    private function loadProfileDetailMap(array $profileIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $profileIds), fn (int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $profiles = MatrimonyProfile::query()
            ->with(['religion', 'caste'])
            ->whereIn('id', $ids)
            ->get([
                'id',
                'date_of_birth',
                'height_cm',
                'occupation_title',
                'highest_education',
                'city_id',
                'taluka_id',
                'district_id',
                'state_id',
                'religion_id',
                'caste_id',
            ]);

        $out = [];
        foreach ($profiles as $profile) {
            $age = $profile->date_of_birth ? (string) now()->diffInYears($profile->date_of_birth).' yrs' : null;
            $height = $this->formatHeightForChatSummary($profile->height_cm);
            $religion = trim((string) ($profile->religion?->name ?? ''));
            $caste = trim((string) ($profile->caste?->name ?? ''));
            $titleParts = array_values(array_filter([$age, $height, $religion, $caste]));
            $subtitleParts = array_values(array_filter([
                trim((string) ($profile->occupation_title ?? '')),
                trim((string) ($profile->highest_education ?? '')),
            ]));

            $out[(int) $profile->id] = [
                'title' => $titleParts ? implode(', ', $titleParts) : 'Profile summary',
                'subtitle' => $subtitleParts ? implode(', ', $subtitleParts) : 'Details are being updated',
                'location' => trim((string) $profile->residenceLocationDisplayLine()) ?: 'Location not specified',
            ];
        }

        return $out;
    }

    private function formatHeightForChatSummary(mixed $heightCm): ?string
    {
        if (! is_numeric($heightCm)) {
            return null;
        }
        $cm = (float) $heightCm;
        if ($cm <= 0) {
            return null;
        }

        $inches = $cm / 2.54;
        $feet = (int) floor($inches / 12);
        $remainingInches = (int) round($inches - ($feet * 12));
        if ($remainingInches === 12) {
            $feet++;
            $remainingInches = 0;
        }

        return $feet > 0 ? sprintf("%d'%d\"", $feet, $remainingInches) : null;
    }

    /**
     * @return array{interests_pending: int, who_viewed_count: int}
     */
    public function buildActivityCountsForUser(?User $user): array
    {
        if (! $user || ! $user->matrimonyProfile) {
            return [
                'interests_pending' => 0,
                'who_viewed_count' => 0,
            ];
        }

        $profileId = (int) $user->matrimonyProfile->id;
        $interestsPending = (int) Interest::query()
            ->where('receiver_profile_id', $profileId)
            ->where('status', 'pending')
            ->count();

        $whoViewedCount = $this->resolveWhoViewedCountForUser($user);

        return [
            'interests_pending' => $interestsPending,
            'who_viewed_count' => $whoViewedCount,
        ];
    }

    /**
     * @return array{chat_unread: int, interests_pending: int, who_viewed_count: int}
     */
    public function buildLiveCountsForUser(?User $user): array
    {
        if (! $user || ! $user->matrimonyProfile) {
            return [
                'chat_unread' => 0,
                'interests_pending' => 0,
                'who_viewed_count' => 0,
            ];
        }

        $profileId = (int) $user->matrimonyProfile->id;
        $activity = $this->buildActivityCountsForUser($user);

        return [
            'chat_unread' => (int) $this->chatList->getUnreadMessageCount($profileId),
            'interests_pending' => (int) $activity['interests_pending'],
            'who_viewed_count' => (int) $activity['who_viewed_count'],
        ];
    }

    private function resolveWhoViewedCountForUser(User $user): int
    {
        $profileId = (int) ($user->matrimonyProfile?->id ?? 0);
        if ($profileId <= 0) {
            return 0;
        }

        $userId = (int) $user->id;
        $canSeeWhoViewedNames = $this->featureUsage->canUse($userId, FeatureUsageService::FEATURE_WHO_VIEWED_ME_ACCESS)
            && $this->featureUsage->getWhoViewedMeWindowDays($userId) > 0;

        if (! $canSeeWhoViewedNames) {
            return ViewTrackingService::countEligibleDistinctViewersForTeaser($profileId);
        }

        $days = $this->featureUsage->getWhoViewedMeWindowDays($userId);
        $since = $days >= FeatureUsageService::WHO_VIEWED_UNLIMITED_DAYS_THRESHOLD
            ? null
            : now()->subDays($days);
        $blockedIds = ViewTrackingService::getBlockedProfileIds($profileId)->all();

        $query = DB::table('profile_views')
            ->join('matrimony_profiles as vp', 'vp.id', '=', 'profile_views.viewer_profile_id')
            ->join('users as u', 'u.id', '=', 'vp.user_id')
            ->where('profile_views.viewed_profile_id', $profileId)
            ->where(function ($q): void {
                $q->whereNull('u.is_admin')->orWhere('u.is_admin', false);
            })
            ->where(function ($q): void {
                $q->whereNull('vp.is_suspended')->orWhere('vp.is_suspended', false);
            });

        if ($since !== null) {
            $query->where('profile_views.created_at', '>=', $since);
        }
        if (! empty($blockedIds)) {
            $query->whereNotIn('profile_views.viewer_profile_id', $blockedIds);
        }

        return (int) $query->selectRaw('count(distinct profile_views.viewer_profile_id) as c')->value('c');
    }
}
