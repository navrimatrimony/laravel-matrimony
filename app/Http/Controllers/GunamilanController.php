<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Services\Gunamilan\GunamilanService;
use App\Services\ProfileLifecycleService;
use App\Services\ProfileVisibilityPolicyService;
use App\Services\ViewTrackingService;
use Illuminate\Http\Request;

class GunamilanController extends Controller
{
    public function show(int $matrimony_profile_id, Request $request, GunamilanService $gunamilan)
    {
        $user = $request->user();
        $viewerProfile = $user?->matrimonyProfile;

        if (! $viewerProfile) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('error', __('interest.create_profile_first'));
        }

        $profile = MatrimonyProfile::with([
            'user',
            'gender',
            'horoscope.rashi',
            'horoscope.nakshatra',
            'horoscope.gan',
            'horoscope.nadi',
            'horoscope.yoni',
        ])->findOrFail($matrimony_profile_id);

        if ((int) $viewerProfile->id === (int) $profile->id) {
            return redirect()
                ->route('matrimony.profile.show', $profile)
                ->with('info', __('profile.gunamilan_own_profile_unavailable'));
        }

        if (! ProfileLifecycleService::isVisibleToOthers($profile)) {
            abort(404, __('common.profile_not_found'));
        }

        if (ViewTrackingService::isBlocked((int) $viewerProfile->id, (int) $profile->id)) {
            abort(404, __('common.profile_not_found'));
        }

        if (! ProfileVisibilityPolicyService::canViewProfile($profile, $user)) {
            abort(404, __('common.profile_not_found'));
        }

        $viewerProfile->loadMissing([
            'gender',
            'horoscope.rashi',
            'horoscope.nakshatra',
            'horoscope.gan',
            'horoscope.nadi',
            'horoscope.yoni',
        ]);

        return view('matrimony.profile.gunamilan', [
            'profile' => $profile,
            'viewerProfile' => $viewerProfile,
            'result' => $gunamilan->calculate($viewerProfile, $profile),
        ]);
    }
}
