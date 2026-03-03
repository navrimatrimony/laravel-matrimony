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
            $redirectTo = $intendedAfterVerify ?: route('dashboard');
            $request->session()->forget(['intended_after_verify', 'from_registration']);
            return redirect($redirectTo)->with('info', 'Mobile verification is currently disabled.');
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
     * Skip verification (e.g. after registration). Redirect to wizard or intended URL.
     */
    public function skip(Request $request): RedirectResponse
    {
        $intended = $request->session()->pull('intended_after_verify');
        $request->session()->forget('from_registration');
        $redirectTo = $intended ?: route('matrimony.profile.wizard.section', ['section' => 'basic-info']);
        return redirect($redirectTo)->with('info', 'You can verify your mobile later from the dashboard.');
    }

    public function sendOtp(Request $request): RedirectResponse
    {
        $mode = AdminSetting::getValue('mobile_verification_mode', 'dev_show');
        if ($mode === 'off') {
            return redirect()->route('dashboard');
        }

        $request->validate([
            'mobile' => ['required', 'string', 'max:20'],
        ]);

        $user = $request->user();
        $mobile = preg_replace('/\D/', '', $request->input('mobile'));
        if (strlen($mobile) < 10) {
            throw ValidationException::withMessages(['mobile' => 'Enter a valid 10-digit mobile number.']);
        }

        $user->update(['mobile' => $request->input('mobile')]);

        $otp = (string) random_int(100000, 999999);
        Cache::put(self::CACHE_KEY_PREFIX . $user->id, $otp, self::OTP_TTL_SECONDS);

        if ($mode === 'dev_show') {
            // Store OTP in session so it reliably shows on next page load (flash can be lost on redirect in some setups)
            $request->session()->put('otp_display', $otp);
            return redirect()->route('mobile.verify')->with('status', 'OTP generated. Enter it below.');
        }

        // Future: send real SMS when mode === 'live'
        return redirect()->route('mobile.verify')->with('status', 'OTP sent to your mobile.');
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
            throw ValidationException::withMessages(['otp' => 'Invalid or expired OTP. Request a new one.']);
        }

        Cache::forget($key);
        $user->update(['mobile_verified_at' => now()]);

        $intended = $request->session()->pull('intended_after_verify');
        $request->session()->forget('from_registration');
        $redirectTo = $intended ?: route('dashboard');

        return redirect($redirectTo)->with('status', 'Mobile number verified successfully.');
    }
}
