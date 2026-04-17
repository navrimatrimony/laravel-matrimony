<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\RedirectsUnprofiledUsers;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ReferralService;
use App\Support\MobileNumber;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    use RedirectsUnprofiledUsers;

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
            'mobile' => ['required', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'registering_for' => ['required', 'string', Rule::in(['self', 'parent_guardian', 'sibling', 'relative', 'friend', 'other'])],
            'invite_code' => ['nullable', 'string', 'max:16'],
        ]);

        $mobileDigits = MobileNumber::normalize($request->mobile);
        if ($mobileDigits === null) {
            return redirect()->back()->withInput()->withErrors(['mobile' => __('otp.enter_valid_10_digit_mobile')]);
        }

        Validator::make(
            ['mobile' => $mobileDigits],
            ['mobile' => ['required', Rule::unique('users', 'mobile')]],
            ['mobile.unique' => __('auth.mobile_duplicate_register')]
        )->validate();

        $user = User::create([
            'name' => $request->name,
            'email' => null,
            'mobile' => $mobileDigits,
            'password' => Hash::make($request->password),
            'registering_for' => $request->registering_for,
            // Legacy NOT NULL column; candidate gender is captured on matrimony_profiles in onboarding.
            'gender' => '',
        ]);

        app(ReferralService::class)->recordReferralIfEligible($user, $request->input('invite_code'));
        $user->forceFill(['referral_code' => User::generateUniqueReferralCode()])->saveQuietly();

        event(new Registered($user));

        Auth::login($user);

        if ($redirect = $this->redirectIfNoMatrimonyProfile($user, fromRegistration: true)) {
            return $redirect;
        }

        return redirect('/dashboard');
    }
}
