<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Services\WhoViewed\NotificationTeaserRenderer;
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
        protected NotificationTeaserRenderer $teaserRenderer,
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

        // REVEALED rows only. The blade reads `$actorProfiles` solely to draw the
        // named, tappable header on a row the member is allowed to see, so the
        // locked-type fallback that used to widen this list was fetching profiles
        // nothing ever rendered — and it read the very keys a locked payload omits.
        $actorProfileIds = [];
        foreach ($notifications as $n) {
            $id = $this->extractActorProfileId(is_array($n->data) ? $n->data : []);
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

        // One renderer, shared with the mobile list. The loop that used to live here
        // looked the actor up under `viewer_profile_id` / `sender_profile_id`, which a
        // LOCKED payload deliberately omits — so it never once produced a teaser and
        // the blade silently fell through to the stored, frozen, wrong-language one.
        // It also hardcoded `viewer_view_count` to 1, which would have dropped the
        // "viewed your profile 7 times" line the moment it did start working.
        $localizedTeasers = $this->teaserRenderer->forPage($notifications->getCollection(), $ownerProfile);

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

        // Same renderer as the list, page of one — so opening a card cannot show a
        // different language, or a different time, than the card that was tapped.
        $localizedTeaser = $this->teaserRenderer
            ->forPage(collect([$notification]), $user->matrimonyProfile)[(string) $notification->id] ?? null;

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
