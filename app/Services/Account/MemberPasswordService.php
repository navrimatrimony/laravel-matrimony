<?php

namespace App\Services\Account;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * THE writer of `users.password` on the member surface, and the only place the
 * security side effects of a password change are decided.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THE CURRENT PASSWORD IS NOT REQUIRED (product decision, 2026-07-28)
 * ─────────────────────────────────────────────────────────────────────────────
 * Most members register with a mobile number and never have an email, so the
 * emailed reset link (`POST /auth/password/forgot` → `/auth/password/reset`) can
 * never reach them. A member who forgets their password used to be permanently
 * locked out. The approved recovery path is: sign in with mobile OTP, then set a
 * new password from inside the app. Demanding the OLD password on that screen
 * would recreate the exact lockout it exists to remove.
 *
 * The authenticated session IS the proof of identity here — the same proof the
 * emailed link relies on, obtained through a channel a mobile-only member owns.
 *
 * Because that proof is weaker than "knows the old password", two compensating
 * controls are MANDATORY and must not be dropped for convenience:
 *
 *   1. Every OTHER credential the account has is destroyed (see revoke*). A
 *      stolen unlocked phone must not silently retain access after the real
 *      owner recovers the account. Only the caller's own token survives, so the
 *      member is not thrown out of the app mid-action.
 *   2. The member is told it happened ({@see PasswordChangedNotification}), so a
 *      change they did not make is visible on their other device rather than
 *      silent.
 *
 * Removing either control turns this endpoint into an account-takeover tool.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class MemberPasswordService
{
    /**
     * Write a new password. The ONLY place the member surface hashes one.
     *
     * `remember_token` is rotated in the same write because a stale one keeps
     * every "remember me" cookie valid — the web login would then walk straight
     * past the password that was just replaced. This mirrors what the emailed
     * reset already does in PasswordResetApiController::reset().
     */
    public function set(User $user, string $plainPassword): void
    {
        $user->forceFill([
            'password' => Hash::make($plainPassword),
            'remember_token' => Str::random(60),
        ])->save();
    }

    /**
     * Set a new password for a signed-in member and invalidate everything else
     * that could still open the account.
     *
     * @param  int|null  $keepTokenId  `personal_access_tokens.id` of the caller's
     *                                 own token — the one session deliberately
     *                                 kept alive. Null when the caller is not
     *                                 token-authenticated (stateful/session
     *                                 request), in which case no API token is
     *                                 spared.
     * @param  string|null  $keepSessionId  `sessions.id` of the caller's own web
     *                                      session, for the same reason.
     * @return array{revoked_api_tokens: int, revoked_web_sessions: int, cleared_reset_links: int}
     */
    public function changeForSignedInMember(
        User $user,
        string $plainPassword,
        ?int $keepTokenId = null,
        ?string $keepSessionId = null,
    ): array {
        $result = DB::transaction(function () use ($user, $plainPassword, $keepTokenId, $keepSessionId): array {
            $this->set($user, $plainPassword);

            return [
                'revoked_api_tokens' => $this->revokeOtherApiTokens($user, $keepTokenId),
                'revoked_web_sessions' => $this->revokeOtherWebSessions($user, $keepSessionId),
                'cleared_reset_links' => $this->clearPendingResetLinks($user),
            ];
        });

        // Outside the transaction on purpose: push delivery is SYNCHRONOUS (see
        // PushDispatchService) and an FCM round trip must never hold a write
        // lock open, nor be rolled back after the password has really changed.
        $this->notify($user);

        return $result;
    }

    /**
     * Every Sanctum token for this account except the caller's own.
     *
     * This is what actually signs the other phone out: both Flutter apps
     * authenticate with a `personal_access_tokens` row and nothing else.
     */
    private function revokeOtherApiTokens(User $user, ?int $keepTokenId): int
    {
        $query = $user->tokens();

        if ($keepTokenId !== null) {
            $query->whereKeyNot($keepTokenId);
        }

        return (int) $query->delete();
    }

    /**
     * Web sessions for this account except the caller's own.
     *
     * The session driver is `database`, so a live browser session survives a
     * token revocation entirely — it never touches `personal_access_tokens`.
     * Guarded by hasTable() because a non-database driver leaves no rows to
     * clear (and the table may then not exist at all).
     */
    private function revokeOtherWebSessions(User $user, ?string $keepSessionId): int
    {
        if (! Schema::hasTable('sessions')) {
            return 0;
        }

        $query = DB::table('sessions')->where('user_id', $user->getKey());

        if ($keepSessionId !== null && $keepSessionId !== '') {
            $query->where('id', '!=', $keepSessionId);
        }

        return (int) $query->delete();
    }

    /**
     * A reset link that was emailed BEFORE this change must not still work.
     *
     * Otherwise the sequence "member requests a reset link → recovers by OTP and
     * sets a new password → the old link is found in the inbox (or by whoever
     * else can read it) and used" quietly overwrites the password the member
     * just chose. Same table and same key the Password broker writes.
     */
    private function clearPendingResetLinks(User $user): int
    {
        $email = trim((string) ($user->email ?? ''));

        if ($email === '' || ! Schema::hasTable('password_reset_tokens')) {
            return 0;
        }

        return (int) DB::table('password_reset_tokens')->where('email', $email)->delete();
    }

    /**
     * Never lets a notification problem fail the password change — the password
     * is already committed by the time this runs, so throwing here would report
     * failure for an operation that succeeded and invite a confusing retry.
     */
    private function notify(User $user): void
    {
        try {
            $user->notify(new PasswordChangedNotification);
        } catch (Throwable $e) {
            Log::warning('account.password_changed_notification_failed', [
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
