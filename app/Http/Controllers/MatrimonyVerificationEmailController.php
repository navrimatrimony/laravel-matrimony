<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Centralized email verification (signed link in email — Laravel default notification).
 */
class MatrimonyVerificationEmailController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('matrimony.verification.email', [
            'emailVerified' => $user->hasVerifiedEmail(),
            'email' => $user->email,
            'hasEmail' => self::userHasEmail($user),
        ]);
    }

    /**
     * If the account has no email yet, require one, save it, then send the verification mail.
     * Otherwise only resend the verification link.
     */
    public function sendVerificationLink(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        if (! self::userHasEmail($user)) {
            $validated = $request->validate([
                'email' => [
                    'required',
                    'string',
                    'lowercase',
                    'email',
                    'max:255',
                    Rule::unique(User::class)->ignore($user->id),
                ],
            ]);
            $normalized = strtolower(trim($validated['email']));

            DB::transaction(function () use ($user, $normalized): void {
                $user->forceFill([
                    'email' => $normalized,
                    'email_verified_at' => null,
                ]);
                $user->save();
            });
            $user->refresh();
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('matrimony.verification.email')
                ->withErrors(['email' => __('auth.mail_send_failed')]);
        }

        return redirect()
            ->route('matrimony.verification.email')
            ->with('status', 'verification-link-sent');
    }

    private static function userHasEmail(User $user): bool
    {
        return filled(trim((string) ($user->email ?? '')));
    }
}
