<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Api\GoogleSignInService;
use App\Support\MobileNumber;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class AuthController extends Controller
{
    /**
     * Mobile API Login (JSON + Sanctum Token)
     */
    public function login(Request $request)
    {
        // 1) Validate input. `email` stays accepted for older APK builds.
        $validated = $request->validate([
            'login' => ['nullable', 'string', 'max:191', 'required_without:email'],
            'email' => ['nullable', 'string', 'max:191', 'required_without:login'],
            'password' => ['required', 'string'],
        ]);

        $login = trim((string) ($validated['login'] ?? $validated['email'] ?? ''));
        $password = (string) $validated['password'];

        // 2) Resolve the same login inputs as the web login screen: mobile, email, or username.
        $user = $this->resolveMobileApiLoginUser($login, $password);
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        // 3) Create Sanctum token for mobile
        $token = $user->createToken('mobile-app')->plainTextToken;

        // 4) JSON success response (NO redirect)
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
     * Google sign-in and sign-up, over one route.
     *
     * The app sends the ID token Google handed it and nothing else — no email
     * alongside it, because a caller-supplied address would be an unverified
     * claim sitting next to a verified one. The address comes out of the token.
     *
     * `is_new_user` reports what happened, but the app does not have to trust it
     * to route: it asks for the profile afterwards exactly as it does after a
     * password login, and a member with no profile lands in onboarding either
     * way. The flag is there so the client can word the greeting correctly.
     */
    public function google(Request $request, GoogleSignInService $googleSignIn): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string', 'max:8192'],
        ]);

        try {
            $result = $googleSignIn->authenticate((string) $validated['id_token']);
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode() ?: 422);
        }

        /** @var User $user */
        $user = $result['user'];
        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => $result['is_new_user'] ? 'Registration successful' : 'Login successful',
            'token' => $token,
            'is_new_user' => $result['is_new_user'],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    private function resolveMobileApiLoginUser(string $login, string $password): ?User
    {
        if ($login === '') {
            return null;
        }

        $mobileDigits = MobileNumber::normalize($login);
        if ($mobileDigits !== null) {
            $users = User::query()
                ->where('mobile', $mobileDigits)
                ->orderByDesc('mobile_verified_at')
                ->orderByDesc('id')
                ->get();

            foreach ($users as $candidate) {
                if (filled($candidate->password) && Hash::check($password, $candidate->password)) {
                    return $candidate;
                }
            }

            return null;
        }

        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $candidate = User::query()
                ->whereRaw('LOWER(email) = ?', [Str::lower($login)])
                ->first();

            return $candidate && filled($candidate->password) && Hash::check($password, $candidate->password)
                ? $candidate
                : null;
        }

        $normalized = Str::lower($login);
        if ($normalized !== '' && ! str_contains($login, '@')) {
            $byLocalPart = User::query()
                ->whereNotNull('email')
                ->whereRaw('LOWER(email) LIKE ?', [$normalized.'@%'])
                ->limit(5)
                ->get();

            if ($byLocalPart->count() === 1) {
                $candidate = $byLocalPart->first();
                if ($candidate && filled($candidate->password) && Hash::check($password, $candidate->password)) {
                    return $candidate;
                }
            }
        }

        $candidates = User::query()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->limit(5)
            ->get();

        foreach ($candidates as $candidate) {
            if (filled($candidate->password) && Hash::check($password, $candidate->password)) {
                return $candidate;
            }
        }

        return null;
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
