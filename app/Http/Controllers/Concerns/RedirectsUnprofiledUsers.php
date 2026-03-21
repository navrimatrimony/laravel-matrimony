<?php

namespace App\Http\Controllers\Concerns;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

trait RedirectsUnprofiledUsers
{
    /**
     * Send users without a matrimony profile to mobile verification (when enabled) or the profile wizard.
     */
    protected function redirectIfNoMatrimonyProfile(User $user, bool $fromRegistration = false): ?RedirectResponse
    {
        if ($user->matrimonyProfile) {
            return null;
        }

        $wizardUrl = route('matrimony.onboarding.show', ['step' => 2]);
        $redirectToVerify = AdminSetting::getBool('redirect_to_mobile_verify_after_registration', true);
        $mobileMode = AdminSetting::getValue('mobile_verification_mode', 'off');

        session()->put('wizard_minimal', true);

        if ($redirectToVerify && $mobileMode !== 'off') {
            session()->put('intended_after_verify', $wizardUrl);
            if ($fromRegistration) {
                session()->put('from_registration', true);
            }

            return redirect()->route('mobile.verify');
        }

        return redirect($wizardUrl);
    }
}
