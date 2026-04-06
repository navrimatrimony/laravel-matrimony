<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class UserSettingsController extends Controller
{
    private function requireMatrimonyProfile(Request $request): MatrimonyProfile|RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $profile = $user?->matrimonyProfile;

        if ($profile) {
            return $profile;
        }

        return redirect()
            ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
            ->with('warning', 'Please complete your profile to manage settings.');
    }

    public function index(Request $request)
    {
        $hasProfile = (bool) $request->user()?->matrimonyProfile;

        return view('settings.index', [
            'hasProfile' => $hasProfile,
        ]);
    }

    public function privacy(Request $request)
    {
        $profile = $this->requireMatrimonyProfile($request);
        if ($profile instanceof \Illuminate\Http\RedirectResponse) {
            return $profile;
        }

        $visibilitySettings = DB::table('profile_visibility_settings')
            ->where('profile_id', $profile->id)
            ->first();

        return view('settings.privacy', [
            'profile' => $profile,
            'visibilitySettings' => $visibilitySettings,
        ]);
    }

    public function updatePrivacy(Request $request)
    {
        $profile = $this->requireMatrimonyProfile($request);
        if ($profile instanceof \Illuminate\Http\RedirectResponse) {
            return $profile;
        }

        $validated = $request->validate([
            'visibility_scope' => 'required|in:public,premium_only,hidden',
            'show_photo_to' => 'required|in:all,premium,accepted_interest',
            'show_contact_to' => 'required|in:everyone,premium_only,accepted_interest,unlock_only,no_one',
            'hide_from_blocked_users' => 'required|boolean',
        ]);

        $hideFromBlocked = $request->boolean('hide_from_blocked_users');
        $payload = [
            'visibility_scope' => $validated['visibility_scope'],
            'show_photo_to' => $validated['show_photo_to'],
            'show_contact_to' => $validated['show_contact_to'],
            'hide_from_blocked_users' => $hideFromBlocked,
            'updated_at' => now(),
        ];

        $existing = DB::table('profile_visibility_settings')
            ->where('profile_id', $profile->id)
            ->first();

        if ($existing) {
            DB::table('profile_visibility_settings')
                ->where('profile_id', $profile->id)
                ->update($payload);
        } else {
            DB::table('profile_visibility_settings')->insert(array_merge($payload, [
                'profile_id' => $profile->id,
                'created_at' => now(),
            ]));
        }

        return redirect()
            ->route('user.settings.privacy')
            ->with('status', 'privacy-updated');
    }

    public function communication(Request $request)
    {
        $profile = $this->requireMatrimonyProfile($request);
        if ($profile instanceof \Illuminate\Http\RedirectResponse) {
            return $profile;
        }

        return view('settings.communication', [
            'profile' => $profile,
        ]);
    }

    public function updateCommunication(Request $request)
    {
        $profile = $this->requireMatrimonyProfile($request);
        if ($profile instanceof \Illuminate\Http\RedirectResponse) {
            return $profile;
        }

        $validated = $request->validate([
            'contact_unlock_mode' => 'required|in:after_interest_accepted,never,admin_only',
        ]);

        $profile->update([
            'contact_unlock_mode' => $validated['contact_unlock_mode'],
        ]);

        return redirect()
            ->route('user.settings.communication')
            ->with('status', 'communication-updated');
    }

    public function security(Request $request)
    {
        $user = $request->user();

        $mobileVerified = !empty($user->mobile_verified_at);
        $emailVerified = !empty($user->email_verified_at);

        return view('settings.security', [
            'user' => $user,
            'mobileVerified' => $mobileVerified,
            'emailVerified' => $emailVerified,
        ]);
    }
}

