<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Models\ProfileVisibilitySetting;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

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

        $visibilitySettings = ProfileVisibilitySetting::query()
            ->where('profile_id', $profile->id)
            ->first();

        $contactVisibilityResolved = $visibilitySettings?->resolvedContactVisibility()
            ?? ProfileVisibilitySetting::defaultResolvedContactVisibility();

        return view('settings.privacy', [
            'profile' => $profile,
            'visibilitySettings' => $visibilitySettings,
            'contactVisibilityResolved' => $contactVisibilityResolved,
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
            'contact_visibility_rule' => 'required|in:anyone,interest,matching,none',
            'contact_visibility_strictness' => 'required|in:relaxed,balanced,strict',
            'contact_visibility_id_verified_only' => 'nullable|boolean',
            'contact_visibility_photo_only' => 'nullable|boolean',
            'contact_visibility_require_contact_request' => 'nullable|boolean',
            'contact_visibility_approval_required' => 'nullable|boolean',
        ]);

        $showContactTo = self::deriveLegacyShowContactTo(
            $validated['contact_visibility_rule'],
            $request->boolean('contact_visibility_require_contact_request'),
        );

        $contactVisibilityJson = [
            'rule' => $validated['contact_visibility_rule'],
            'strictness' => $validated['contact_visibility_strictness'],
            'filters' => [
                'id_verified_only' => $request->boolean('contact_visibility_id_verified_only'),
                'photo_only' => $request->boolean('contact_visibility_photo_only'),
            ],
            'approval_required' => $request->boolean('contact_visibility_approval_required'),
            'require_contact_request' => $request->boolean('contact_visibility_require_contact_request'),
        ];

        ProfileVisibilitySetting::query()->updateOrCreate(
            ['profile_id' => $profile->id],
            [
                'visibility_scope' => $validated['visibility_scope'],
                'show_photo_to' => $validated['show_photo_to'],
                'show_contact_to' => $showContactTo,
                'hide_from_blocked_users' => true,
                'contact_visibility_json' => $contactVisibilityJson,
            ]
        );

        return redirect()
            ->route('user.settings.privacy')
            ->with('status', 'privacy-updated');
    }

    /**
     * Keeps {@see \App\Services\ContactRevealPolicyService} in sync without exposing {@code show_contact_to} in the UI.
     */
    private static function deriveLegacyShowContactTo(string $rule, bool $requireContactRequest): string
    {
        if ($rule === 'none') {
            return 'no_one';
        }
        if ($requireContactRequest) {
            return 'unlock_only';
        }
        if ($rule === 'interest') {
            return 'accepted_interest';
        }

        return 'everyone';
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

