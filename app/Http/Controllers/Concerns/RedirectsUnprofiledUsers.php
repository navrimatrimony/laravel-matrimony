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

        // Relative URL so redirect stays on the same host the user used (e.g. 127.0.0.1 vs localhost).
        $wizardUrl = route('matrimony.onboarding.show', ['step' => 2], absolute: false);
        $redirectToVerify = AdminSetting::getBool('redirect_to_mobile_verify_after_registration', true);
        // Must match MobileOtpController + AdminSettingSeeder default when no DB row exists ("off" skipped OTP entirely).
        $mobileMode = AdminSetting::getValue('mobile_verification_mode', 'dev_show');

        session()->put('wizard_minimal', true);

        if ($redirectToVerify && $mobileMode !== 'off') {
            session()->put('intended_after_verify', $wizardUrl);
            if ($fromRegistration) {
                session()->put('from_registration', true);
            }

            return redirect()->to(route('mobile.verify', absolute: false));
        }

        return redirect()->to($wizardUrl);
    }
}
