<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\MemberAccountDeletionService;
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
        /*
        | `email` is prohibited here on purpose. An address only reaches
        | users.email once its holder has proved possession, and the single
        | place that proof is checked is MobileEmailVerificationService —
        | reached through /account/email-otp/verify or /account/email/google.
        | `prohibited` still allows a null or empty value, so clients that
        | send the key without a value keep working unchanged.
        */
        $validated = $request->validate([
            'creator_name' => ['required', 'string', 'max:255'],
            'email' => ['prohibited'],
            'locale' => ['nullable', 'string', Rule::in(['mr', 'en'])],
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            'whatsapp_alerts_opt_in' => ['nullable', 'boolean'],
        ], [
            'email.prohibited' => 'Email can only be changed by verifying it. Use the email OTP or Google verification flow.',
        ]);

        /** @var User $user */
        $user = $request->user();

        $updates = [
            'name' => trim((string) $validated['creator_name']),
        ];

        if (array_key_exists('locale', $validated)) {
            $updates['preferred_locale'] = $validated['locale'];
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

    /**
     * Where the member's own account stands: live, paused, or counting down to
     * erase. The app polls this to decide whether to show the cancel banner.
     */
    public function deletionStatus(Request $request, MemberAccountDeletionService $deletions): JsonResponse
    {
        return response()->json([
            'success' => true,
            'deletion' => $deletions->status($request->user()),
            'reasons' => MemberAccountDeletionService::REASONS,
            'grace_days' => MemberAccountDeletionService::GRACE_DAYS,
        ]);
    }

    /**
     * Starts the 30-day countdown.
     *
     * `confirmation` must be the literal word "delete", typed by the member.
     * It is checked server-side and not only in the app, so the destructive
     * call cannot be reached by a stray tap or a replayed request.
     */
    public function requestDeletion(Request $request, MemberAccountDeletionService $deletions): JsonResponse
    {
        $validated = $request->validate([
            'confirmation' => ['required', 'string'],
            'reason_key' => ['required', 'string', Rule::in(MemberAccountDeletionService::REASONS)],
            'reason_note' => ['nullable', 'string', 'max:500'],
        ]);

        if (Str::lower(trim($validated['confirmation'])) !== 'delete') {
            return response()->json([
                'success' => false,
                'message' => __('account.deletion_confirmation_mismatch'),
            ], 422);
        }

        $deletions->requestDeletion(
            $request->user(),
            $validated['reason_key'],
            $validated['reason_note'] ?? null
        );

        return response()->json([
            'success' => true,
            'deletion' => $deletions->status($request->user()->fresh()),
        ]);
    }

    /** Called when the member changes their mind inside the grace window. */
    public function cancelDeletion(Request $request, MemberAccountDeletionService $deletions): JsonResponse
    {
        $deletions->cancelDeletion($request->user());

        return response()->json([
            'success' => true,
            'deletion' => $deletions->status($request->user()->fresh()),
        ]);
    }

    /**
     * The softer option, offered before deletion: hide the profile, erase
     * nothing, reversible whenever they want.
     */
    public function pause(Request $request, MemberAccountDeletionService $deletions): JsonResponse
    {
        $deletions->pause($request->user());

        return response()->json([
            'success' => true,
            'deletion' => $deletions->status($request->user()->fresh()),
        ]);
    }

    public function resume(Request $request, MemberAccountDeletionService $deletions): JsonResponse
    {
        $deletions->resume($request->user());

        return response()->json([
            'success' => true,
            'deletion' => $deletions->status($request->user()->fresh()),
        ]);
    }
}
