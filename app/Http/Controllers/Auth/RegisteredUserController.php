<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    /**
     * Handle an incoming registration request.
     */
	 public function create()
{
    return view('auth.register');
}

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'mobile' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);
        $mobileDigits = preg_replace('/\D/', '', $request->mobile);
        if (strlen($mobileDigits) !== 10) {
            return redirect()->back()->withInput()->withErrors(['mobile' => __('otp.enter_valid_10_digit_mobile')]);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'mobile' => $mobileDigits,
            'gender' => $request->gender ?? null,
            'password' => Hash::make($request->password),
        ]);

        // 3️⃣ Registered event fire करा
        event(new Registered($user));

        // 4️⃣ User ला login करा
        Auth::login($user);

        /*
        |--------------------------------------------------------------------------
        | Post-registration: OTP step or wizard (admin-controlled)
        |--------------------------------------------------------------------------
        | If admin enabled "redirect to mobile verify after registration" and
        | mobile verification mode is not 'off', send user to OTP page first.
        | They can Verify (then wizard) or Skip / Verify later (wizard). Else → wizard.
        */
        $wizardUrl = route('matrimony.profile.wizard.section', ['section' => 'basic-info']);
        $redirectToVerify = AdminSetting::getBool('redirect_to_mobile_verify_after_registration', true);
        $mobileMode = AdminSetting::getValue('mobile_verification_mode', 'off');

        if (! $user->matrimonyProfile && $redirectToVerify && $mobileMode !== 'off') {
            session()->put('intended_after_verify', $wizardUrl);
            session()->put('from_registration', true);
            session()->put('wizard_minimal', true); // Phase-5 Point 5: post-registration wizard shows fewer sections
            return redirect()->route('mobile.verify');
        }

        if (! $user->matrimonyProfile) {
            session()->put('wizard_minimal', true); // Phase-5 Point 5: post-registration minimal wizard
            return redirect($wizardUrl);
        }

        return redirect('/dashboard');


    }
}
