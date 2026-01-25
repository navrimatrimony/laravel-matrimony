<?php

namespace App\Http\Controllers;

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
    /**
     * List current user's notifications (all, paginated).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $notifications = $user->notifications()->paginate(20);
        return view('notifications.index', compact('notifications'));
    }

    /**
     * Open a notification. Auto-marks as read, then displays.
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $notification = $user->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();
        return view('notifications.show', compact('notification'));
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
