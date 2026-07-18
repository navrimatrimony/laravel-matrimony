<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\MobileNumber;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Throwable;

/**
 * Thin JSON transport over existing web password-reset flow.
 * No auth-model change — same Password broker / tokens as PasswordResetLinkController + NewPasswordController.
 */
class PasswordResetApiController extends Controller
{
    public function forgot(Request $request): JsonResponse
    {
        $request->validate([
            'login' => ['nullable', 'string', 'max:191', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:191', 'required_without:login'],
        ]);

        $loginInput = trim((string) ($request->input('login') ?? ''));
        if ($loginInput === '') {
            $loginInput = trim((string) ($request->input('email') ?? ''));
        }

        $resolvedEmail = null;
        $mobileDigits = MobileNumber::normalize($loginInput);
        if ($mobileDigits !== null) {
            $userId = User::query()
                ->where('mobile', $mobileDigits)
                ->orderByDesc('mobile_verified_at')
                ->orderByDesc('id')
                ->value('id');
            $resolvedEmail = $userId
                ? User::query()->whereKey($userId)->value('email')
                : null;
        } elseif (filter_var($loginInput, FILTER_VALIDATE_EMAIL)) {
            $resolvedEmail = Str::lower($loginInput);
        } else {
            $resolvedEmail = User::query()
                ->whereRaw('LOWER(name) = ?', [Str::lower($loginInput)])
                ->value('email');
        }

        if (! $resolvedEmail) {
            return response()->json([
                'success' => false,
                'message' => __('We could not find an account with a reset-enabled email for this login.'),
            ], 422);
        }

        try {
            $status = Password::sendResetLink(['email' => $resolvedEmail]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => __('auth.mail_send_failed'),
            ], 422);
        }

        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json([
                'success' => false,
                'message' => __($status),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __($status),
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'success' => false,
                'message' => __($status),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __($status),
        ]);
    }
}
