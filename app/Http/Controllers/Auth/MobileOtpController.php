<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MobileOtpController extends Controller
{
    private const OTP_TTL_SECONDS = 600; // 10 min
    private const CACHE_KEY_PREFIX = 'mobile_otp:';

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $mode = AdminSetting::getValue('mobile_verification_mode', 'dev_show');
        $intendedAfterVerify = $request->session()->get('intended_after_verify');
        $fromRegistration = (bool) $request->session()->get('from_registration', false);

        if ($mode === 'off') {
            $user = $request->user();
            $user?->loadMissing('matrimonyProfile');
            $fromRegistration = (bool) $request->session()->get('from_registration', false);
            $wizardMinimal = (bool) session('wizard_minimal', false);
            $onboardingUrl = route('matrimony.onboarding.show', ['step' => 2], absolute: false);

            // wizard_minimal: user is still in card onboarding (e.g. went back to OTP); draft profile may already exist.
            if ($wizardMinimal || $fromRegistration || ($user && ! $user->matrimonyProfile)) {
                session()->put('wizard_minimal', true);
                $redirectTo = $onboardingUrl;
            } elseif ($intendedAfterVerify) {
                $redirectTo = $intendedAfterVerify;
            } else {
                $redirectTo = route('dashboard', absolute: false);
            }
            $request->session()->forget(['intended_after_verify', 'from_registration']);

            return redirect()->to($redirectTo)->with('info', __('otp.mobile_verification_disabled'));
        }

        $otpDisplay = $request->session()->pull('otp_display');

        return view('auth.mobile-verify', [
            'user' => $user,
            'otpDisplay' => $otpDisplay,
            'fromRegistration' => $fromRegistration,
            'intendedAfterVerify' => $intendedAfterVerify,
        ]);
    }

    /**
     * Skip verification (e.g. after registration). Same destination rules as verifyOtp (onboarding for users without a profile).
     */
    public function skip(Request $request): RedirectResponse
    {
        $user = $request->user();
        $fromRegistration = (bool) $request->session()->pull('from_registration', false);
        $intended = $request->session()->pull('intended_after_verify');
        $user?->loadMissing('matrimonyProfile');

        $wizardMinimal = (bool) session('wizard_minimal', false);
        $onboardingUrl = route('matrimony.onboarding.show', ['step' => 2], absolute: false);
        if ($wizardMinimal || $fromRegistration || ($user && ! $user->matrimonyProfile)) {
            session()->put('wizard_minimal', true);
            $redirectTo = $onboardingUrl;
        } elseif ($intended) {
            $redirectTo = $intended;
        } else {
            $redirectTo = route('dashboard', absolute: false);
        }

        return redirect()->to($redirectTo)->with('info', __('otp.can_verify_later_from_dashboard'));
    }

    public function sendOtp(Request $request): RedirectResponse
    {
        $mode = AdminSetting::getValue('mobile_verification_mode', 'dev_show');
        if ($mode === 'off') {
            return redirect()->to(route('dashboard', absolute: false));
        }

        $request->validate([
            'mobile' => ['required', 'string', 'max:20'],
        ]);

        $user = $request->user();
        $mobile = preg_replace('/\D/', '', $request->input('mobile'));
        if (strlen($mobile) < 10) {
            throw ValidationException::withMessages(['mobile' => __('otp.enter_valid_10_digit_mobile')]);
        }

        $user->update(['mobile' => $request->input('mobile')]);

        $otp = (string) random_int(100000, 999999);
        Cache::put(self::CACHE_KEY_PREFIX . $user->id, $otp, self::OTP_TTL_SECONDS);

        if ($mode === 'dev_show') {
            // Store OTP in session so it reliably shows on next page load (flash can be lost on redirect in some setups)
            $request->session()->put('otp_display', $otp);
            return redirect()->route('mobile.verify')->with('status', __('otp.otp_generated_enter_below'));
        }

        // Future: send real SMS when mode === 'live'
        return redirect()->route('mobile.verify')->with('status', __('otp.otp_sent_to_mobile'));
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $request->validate([
            'otp' => ['required', 'string', 'size:6'],
        ]);

        $user = $request->user();
        $key = self::CACHE_KEY_PREFIX . $user->id;
        $cached = Cache::get($key);

        if ($cached === null || $cached !== $request->input('otp')) {
            throw ValidationException::withMessages(['otp' => __('otp.invalid_or_expired_request_new')]);
        }

        Cache::forget($key);
        $user->update(['mobile_verified_at' => now()]);

        $fromRegistration = (bool) $request->session()->pull('from_registration', false);
        $intended = $request->session()->pull('intended_after_verify');

        $user->loadMissing('matrimonyProfile');
        $wizardMinimal = (bool) session('wizard_minimal', false);
        $onboardingUrl = route('matrimony.onboarding.show', ['step' => 2], absolute: false);
        if ($wizardMinimal || $fromRegistration || ! $user->matrimonyProfile) {
            session()->put('wizard_minimal', true);
            $redirectTo = $onboardingUrl;
        } elseif ($intended) {
            $redirectTo = $intended;
        } else {
            $redirectTo = route('dashboard', absolute: false);
        }

        return redirect()->to($redirectTo)->with('status', __('otp.mobile_verified_successfully'));
    }
}
