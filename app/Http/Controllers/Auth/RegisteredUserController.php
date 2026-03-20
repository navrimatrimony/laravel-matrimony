<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\RedirectsUnprofiledUsers;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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

        if ($redirect = $this->redirectIfNoMatrimonyProfile($user, fromRegistration: true)) {
            return $redirect;
        }

        return redirect('/dashboard');


    }
}
