<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\MemberPasswordService;
use App\Services\Api\MobileOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class MobileAccountController extends Controller
{
    public function update(Request $request, MobileOtpService $otpService, MemberPasswordService $passwords): JsonResponse
    {
        $validated = $request->validate([
            'creator_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'locale' => ['nullable', 'string', Rule::in(['mr', 'en'])],
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            'whatsapp_alerts_opt_in' => ['nullable', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $updates = [
            'name' => trim((string) $validated['creator_name']),
        ];

        if (array_key_exists('locale', $validated)) {
            $updates['preferred_locale'] = $validated['locale'];
        }

        if ($request->has('email')) {
            $email = $validated['email'] ?? null;
            $email = $email !== null ? Str::lower(trim((string) $email)) : null;

            if ($email === null || $email === '') {
                if ($user->email_verified_at !== null && filled($user->email)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Verified email cannot be cleared.',
                        'errors' => [
                            'email' => ['Verified email cannot be cleared.'],
                        ],
                    ], 422);
                }

                $updates['email'] = null;
                $updates['email_verified_at'] = null;
            } else {
                $exists = User::query()
                    ->whereKeyNot($user->id)
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->exists();

                if ($exists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Email belongs to another account.',
                        'errors' => [
                            'email' => ['Email belongs to another account.'],
                        ],
                    ], 409);
                }

                if ($email !== Str::lower((string) ($user->email ?? ''))) {
                    $updates['email_verified_at'] = null;
                }
                $updates['email'] = $email;
            }
        }

        $user->forceFill($updates)->save();

        /*
        | Onboarding sets the FIRST password here; the security side effects that
        | belong to a later change (revoking other sessions, the alert) are not
        | run, because there is nothing yet to revoke and nothing happened that
        | the member did not just do. Only the hashing is shared — MemberPasswordService
        | stays the single writer of users.password on the member surface.
        | Changing an EXISTING password is POST /api/v1/account/password.
        */
        if (filled($validated['password'] ?? null)) {
            $passwords->set($user, (string) $validated['password']);
        }
        $otpService->persistAlertsOptIn($user, array_key_exists('whatsapp_alerts_opt_in', $validated)
            ? (bool) $validated['whatsapp_alerts_opt_in']
            : null);

        $user = $user->fresh('matrimonyProfile');

        return response()->json([
            'success' => true,
            'user' => $otpService->userPayload($user),
            'account_state' => $otpService->accountStateFor($user),
        ]);
    }
}
