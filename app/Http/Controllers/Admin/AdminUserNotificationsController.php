<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\MobileNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/*
|--------------------------------------------------------------------------
| Admin debug: view notifications for any user (R5). Read-only.
|--------------------------------------------------------------------------
*/
class AdminUserNotificationsController extends Controller
{
    /**
     * Debug inbox lookup form, or list when ?user_id= is present.
     */
    public function userNotificationsIndex(Request $request): View|RedirectResponse
    {
        if ($request->filled('user_id')) {
            return $this->userNotificationsShow($request);
        }

        return view('admin.notifications.index');
    }

    /**
     * Admin debug: list notifications for user. Read-only, no actions.
     *
     * Accepts internal users.id, 10-digit mobile (test logins), or email.
     */
    public function userNotificationsShow(Request $request): View|RedirectResponse
    {
        $request->validate([
            'user_id' => 'required|string|max:191',
        ]);

        $user = $this->resolveUserFromLookup((string) $request->input('user_id'));

        if (! $user) {
            return redirect()
                ->route('admin.notifications.index')
                ->withErrors(['user_id' => __('admin_notifications.debug_user_not_found')])
                ->withInput();
        }

        $notifications = $user->notifications()->orderByDesc('created_at')->paginate(50)->withQueryString();

        return view('admin.notifications.user', [
            'targetUser' => $user,
            'notifications' => $notifications,
        ]);
    }

    private function resolveUserFromLookup(string $lookup): ?User
    {
        $lookup = trim($lookup);
        if ($lookup === '') {
            return null;
        }

        $mobile = MobileNumber::normalize($lookup);
        if ($mobile !== null) {
            return User::query()
                ->where('mobile', $mobile)
                ->orderByDesc('id')
                ->first();
        }

        if (filter_var($lookup, FILTER_VALIDATE_EMAIL)) {
            return User::query()->where('email', $lookup)->first();
        }

        if (ctype_digit($lookup)) {
            return User::query()->find((int) $lookup);
        }

        return null;
    }
}
