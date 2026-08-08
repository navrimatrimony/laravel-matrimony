<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Api\MobileEmailVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Web surface for verifying or changing the signed-in member's own email.
 *
 * This controller owns NO verification logic. It is the web adapter over
 * {@see MobileEmailVerificationService} — the same engine the two apps and the
 * bulk-intake registration already go through — so the OTP, its ten-minute
 * expiry, the five-attempt ceiling, the sixty-second resend cooldown, the
 * per-email/per-IP limits and the write onto `users.email` all stay in one
 * place. Nothing in this file or its Blade view generates, stores or counts an
 * OTP.
 *
 * The challenge lives in the session, never in the form, so the address the
 * code was sent to is the only address it can verify: a member who edits the
 * field after requesting a code cannot verify a different one with it.
 */
class MemberEmailVerificationController extends Controller
{
    private const SESSION_KEY = 'settings_email_otp_challenge';

    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('settings.email', [
            'user' => $user,
            'currentEmail' => $user->email,
            'emailVerified' => $user->email_verified_at !== null,
            'challenge' => $this->challenge($request),
        ]);
    }

    public function sendOtp(Request $request, MobileEmailVerificationService $emails): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $email = mb_strtolower(trim((string) $validated['email']));

        try {
            $result = $emails->sendOtp($user, $email, $request);
        } catch (HttpException $e) {
            return $this->backWithError($e, 'email');
        }

        $request->session()->put(self::SESSION_KEY, [
            'challenge_id' => $result['challenge_id'],
            'email' => $email,
            'resend_after' => $result['resend_after'],
            'expires_in' => $result['expires_in'],
            'debug_otp' => $result['debug_otp'],
        ]);

        return redirect()
            ->route('user.settings.email')
            ->with('status', 'email-otp-sent');
    }

    public function verifyOtp(Request $request, MobileEmailVerificationService $emails): RedirectResponse
    {
        $validated = $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $challenge = $this->challenge($request);
        if ($challenge === null) {
            return redirect()
                ->route('user.settings.email')
                ->withErrors(['otp' => __('settings_email.challenge_expired')]);
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $emails->verifyOtp(
                $user,
                (string) $challenge['challenge_id'],
                (string) $challenge['email'],
                (string) $validated['otp'],
                $request,
            );
        } catch (HttpException $e) {
            return $this->backWithError($e, 'otp');
        } catch (ValidationException $e) {
            return redirect()
                ->route('user.settings.email')
                ->withErrors($e->errors());
        }

        $request->session()->forget(self::SESSION_KEY);

        return redirect()
            ->route('user.settings.email')
            ->with('status', 'email-verified');
    }

    /** Abandon the pending challenge and go back to the plain state. */
    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('user.settings.email');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function challenge(Request $request): ?array
    {
        $challenge = $request->session()->get(self::SESSION_KEY);

        return is_array($challenge) ? $challenge : null;
    }

    private function backWithError(HttpException $e, string $field): RedirectResponse
    {
        return redirect()
            ->route('user.settings.email')
            ->withErrors([$field => $e->getMessage()]);
    }
}
