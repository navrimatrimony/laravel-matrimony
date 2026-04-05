<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Services\ContactAccessService;
use App\Services\MediationRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileContactActionController extends Controller
{
    public function __construct(
        protected ContactAccessService $contactAccess,
        protected MediationRequestService $mediationRequestService,
    ) {}

    public function revealContact(Request $request, MatrimonyProfile $matrimony_profile): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }
        if ($user->matrimonyProfile->id === $matrimony_profile->id) {
            abort(403);
        }

        $visibilitySettings = DB::table('profile_visibility_settings')
            ->where('profile_id', $matrimony_profile->id)
            ->first();

        try {
            $this->contactAccess->consumePaidContactReveal($user, $matrimony_profile, $visibilitySettings);
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('matrimony.profile.show', $matrimony_profile)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('matrimony.profile.show', $matrimony_profile)
            ->with('success', __('contact_access.reveal_success'));
    }

    public function mediatorRequest(Request $request, MatrimonyProfile $matrimony_profile): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }
        if ($user->matrimonyProfile->id === $matrimony_profile->id) {
            abort(403);
        }

        try {
            $this->mediationRequestService->createFromProfile($user, $matrimony_profile);
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('matrimony.profile.show', $matrimony_profile)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('matrimony.profile.show', $matrimony_profile)
            ->with('success', __('contact_access.mediator_success'));
    }
}
