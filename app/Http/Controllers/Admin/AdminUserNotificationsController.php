<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Admin debug: view notifications for any user (R5). Read-only.
|--------------------------------------------------------------------------
*/
class AdminUserNotificationsController extends Controller
{
    /**
     * Admin debug: view notifications for any user (R5).
     * Form to enter user ID, then view that user's notifications (read-only).
     */
    public function userNotificationsIndex()
    {
        return view('admin.notifications.index');
    }

    /**
     * Admin debug: list notifications for user (user_id query). Read-only, no actions.
     */
    public function userNotificationsShow(Request $request)
    {
        $request->validate(['user_id' => 'required|integer|min:1']);
        $user = User::findOrFail($request->user_id);
        $notifications = $user->notifications()->orderByDesc('created_at')->paginate(50)->withQueryString();

        return view('admin.notifications.user', [
            'targetUser' => $user,
            'notifications' => $notifications,
        ]);
    }
}
