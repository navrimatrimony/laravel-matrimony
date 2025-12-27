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
        // 1️⃣ आधी नियम तपासा (validation)
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'in:male,female'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // 2️⃣ User database मध्ये save करा
        $user = User::create([
            'name' => $request->name,
            'gender' => $request->gender,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // 3️⃣ Registered event fire करा
        event(new Registered($user));

        // 4️⃣ User ला login करा
        Auth::login($user);

        // 5️⃣ Dashboard कडे पाठवा
        return redirect('/dashboard');

    }
}
