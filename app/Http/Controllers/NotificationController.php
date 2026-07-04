<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Services\Interest\ReceivedInterestTeaserPolicy;
use App\Services\WhoViewed\WhoViewedTeaserPolicy;
use App\Services\WhoViewed\WhoViewedTeaserPresenter;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| NotificationController (SSOT Day-10 — Recovery-Day-R5)
|--------------------------------------------------------------------------
|
| List, open (auto-mark read), mark single read, mark all read.
| Notifications unread by default; read state persisted in DB.
|
*/
class NotificationController extends Controller
{
    public function __construct(
        protected WhoViewedTeaserPresenter $whoViewedTeaserPresenter,
    ) {}

    private function extractActorProfileId(array $data): ?int
    {
        if (($data['revealed'] ?? true) === false) {
            return null;
        }

        $keys = [
            'viewer_profile_id',
            'sender_profile_id',
            'accepter_profile_id',
            'rejecter_profile_id',
            'receiver_profile_id',
        ];

        foreach ($keys as $key) {
            $id = (int) ($data[$key] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        return null;
    }

    private function extractActorProfileIdAny(array $data): ?int
    {
        $keys = [
            'viewer_profile_id',
            'sender_profile_id',
            'accepter_profile_id',
            'rejecter_profile_id',
            'receiver_profile_id',
        ];

        foreach ($keys as $key) {
            $id = (int) ($data[$key] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        return null;
    }

    /**
     * List current user's notifications (all, paginated).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $notifications = $user->notifications()->paginate(20);
        $unreadNotifications = $user->unreadNotifications;
        $ownerProfile = $user->matrimonyProfile;

        $actorProfileIds = [];
        foreach ($notifications as $n) {
            $data = is_array($n->data) ? $n->data : [];
            $id = $this->extractActorProfileId($data);
            if (! $id && in_array((string) ($data['type'] ?? ''), ['interest_sent', 'profile_viewed'], true)) {
                $id = $this->extractActorProfileIdAny($data);
            }
            if ($id) {
                $actorProfileIds[] = $id;
            }
        }
        $actorProfileIds = array_values(array_unique($actorProfileIds));

        $actorProfiles = collect();
        if (! empty($actorProfileIds)) {
            $actorProfiles = MatrimonyProfile::query()
                ->whereIn('id', $actorProfileIds)
                ->get()
                ->keyBy('id');
        }

        $localizedTeasers = [];
        foreach ($notifications as $n) {
            $data = is_array($n->data) ? $n->data : [];
            $type = (string) ($data['type'] ?? '');
            $revealed = ($data['revealed'] ?? true) !== false;
            $hasTeaser = is_array($data['teaser'] ?? null);
            if (! $hasTeaser || $revealed || ! in_array($type, ['interest_sent', 'profile_viewed'], true)) {
                continue;
            }

            $actorProfileId = $this->extractActorProfileIdAny($data);
            $actor = ($actorProfileId && $actorProfiles->has($actorProfileId))
                ? $actorProfiles->get($actorProfileId)
                : null;
            if (! $actor) {
                continue;
            }

            if ($type === 'interest_sent') {
                $policy = ReceivedInterestTeaserPolicy::forLockedPresentation(ReceivedInterestTeaserPolicy::normalized());
                $timeLine = 'interest_received';
            } else {
                $policy = WhoViewedTeaserPolicy::forWhoViewedLockedTeasers(WhoViewedTeaserPolicy::normalized());
                $timeLine = 'profile_view';
            }

            $localizedTeasers[$n->id] = $this->whoViewedTeaserPresenter->presentFromMatrimonyProfile(
                $actor,
                $n->created_at,
                $policy,
                [
                    'owner_profile' => $ownerProfile,
                    'viewer_view_count' => 1,
                    'teaser_time_line' => $timeLine,
                ]
            );
        }

        return view('notifications.index', compact('request', 'notifications', 'unreadNotifications', 'actorProfiles', 'localizedTeasers'));
    }

    /**
     * Open a notification. Auto-marks as read, then displays.
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $notification = $user->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();

        $data = is_array($notification->data) ? $notification->data : [];
        $actorProfileId = $this->extractActorProfileId($data) ?? $this->extractActorProfileIdAny($data);
        $actorProfile = null;
        if ($actorProfileId) {
            $actorProfile = MatrimonyProfile::query()->where('id', $actorProfileId)->first();
        }

        $localizedTeaser = null;
        $type = (string) ($data['type'] ?? '');
        $revealed = ($data['revealed'] ?? true) !== false;
        $hasTeaser = is_array($data['teaser'] ?? null);
        if ($actorProfile && $hasTeaser && ! $revealed && in_array($type, ['interest_sent', 'profile_viewed'], true)) {
            if ($type === 'interest_sent') {
                $policy = ReceivedInterestTeaserPolicy::forLockedPresentation(ReceivedInterestTeaserPolicy::normalized());
                $timeLine = 'interest_received';
            } else {
                $policy = WhoViewedTeaserPolicy::forWhoViewedLockedTeasers(WhoViewedTeaserPolicy::normalized());
                $timeLine = 'profile_view';
            }

            $localizedTeaser = $this->whoViewedTeaserPresenter->presentFromMatrimonyProfile(
                $actorProfile,
                $notification->created_at,
                $policy,
                [
                    'owner_profile' => $request->user()->matrimonyProfile,
                    'viewer_view_count' => 1,
                    'teaser_time_line' => $timeLine,
                ]
            );
        }

        return view('notifications.show', compact('notification', 'actorProfile', 'localizedTeaser'));
    }

    /**
     * Mark single notification as read.
     */
    public function markRead(Request $request, string $id)
    {
        $user = $request->user();
        $n = $user->unreadNotifications()->where('id', $id)->first();
        if ($n) {
            $n->markAsRead();
        }
        return back();
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();
        return back();
    }

    /**
     * Get unread notification count (JSON endpoint for polling).
     * No WebSockets, no push — simple polling-friendly endpoint.
     */
    public function unreadCount(Request $request)
    {
        $count = $request->user()->unreadNotifications()->count();
        return response()->json([
            'count' => $count,
        ]);
    }
}
