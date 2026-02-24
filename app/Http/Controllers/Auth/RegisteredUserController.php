<?php

namespace App\Http\Controllers\Auth;

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
            'mobile' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'mobile' => $request->mobile ?: null,
            'gender' => $request->gender ?? null,
            'password' => Hash::make($request->password),
        ]);

        // 3️⃣ Registered event fire करा
        event(new Registered($user));

        // 4️⃣ User ला login करा
        Auth::login($user);

        // 5️⃣ Dashboard कडे पाठवा
               /*
        |--------------------------------------------------------------------------
        | Mandatory Matrimony Profile Check (SSOT v3.1)
        |--------------------------------------------------------------------------
        |
        | 👉 Registration नंतर profile असणं compulsory आहे
        | 👉 Profile नसल्यास user ला थेट create page वर पाठवा
        |
        */

        if (! $user->matrimonyProfile) {
            return redirect()->route('matrimony.profile.wizard');
        }

        return redirect('/dashboard');


    }
}
