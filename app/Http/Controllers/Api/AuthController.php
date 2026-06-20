<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;
use Throwable;


class AuthController extends Controller
{
    /**
     * Mobile API Login (JSON + Sanctum Token)
     */
    public function login(Request $request)
    {
        // 1) Validate input (minimum required)
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

    

        // 2) Attempt login using web guard credentials
        if (!Auth::attempt($credentials)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        // 3) Authenticated user
        $user = $request->user();

        // 4) Create Sanctum token for mobile
        $token = $user->createToken('mobile-app')->plainTextToken;

        // 5) JSON success response (NO redirect)
        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
            ],
        ]);
    }
    /**
 * Mobile API Register (JSON + Sanctum Token)
 */
public function register(Request $request)
{
    // 1️⃣ Validate input
    $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
        'password' => ['required', Rules\Password::defaults()],
    ]);

    // 2️⃣ Create user
    $user = User::create([
        'name' => $request->name,
        'email' => $request->email,
        'password' => Hash::make($request->password),
    ]);

    // 3️⃣ Fire registered event without letting mail transport failures break mobile registration.
    $this->dispatchRegisteredEventForMobile($user);

    // 4️⃣ Create Sanctum token
    $token = $user->createToken('mobile-app')->plainTextToken;

    // 5️⃣ JSON response
    return response()->json([
        'success' => true,
        'message' => 'Registration successful',
        'token' => $token,
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ],
    ], 200);
}

    private function dispatchRegisteredEventForMobile(User $user): void
    {
        try {
            Event::dispatch(new Registered($user));
        } catch (Throwable $e) {
            Log::warning('Mobile API registration email verification notification failed', [
                'exception' => $e::class,
                'message' => $this->redactRegistrationEventFailureMessage($e->getMessage()),
            ]);
        }
    }

    private function redactRegistrationEventFailureMessage(string $message): string
    {
        return preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $message)
            ?? 'Registration event failed';
    }

    /**
     * Mobile API Logout (Revoke current token only)
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ], 200);
    }

}
