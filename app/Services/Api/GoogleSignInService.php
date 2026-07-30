<?php

namespace App\Services\Api;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Signs a member in — or up — with a Google ID token.
 *
 * There is deliberately one door for both cases. Google hands the app the same
 * token whether the member has been here before or not, and the app cannot know
 * which it is: the account may have been created earlier by mobile OTP with the
 * same address. Splitting this into separate login and register endpoints would
 * force the client to guess, and a wrong guess is a failed sign-in on a screen
 * where the member did nothing wrong. So the server decides, using the one fact
 * that settles it — whether that verified address already belongs to an account.
 *
 * Token verification is not repeated here. It lives in
 * {@see MobileEmailVerificationService::verifiedGoogleClaims()}, which is also
 * what the signed-in email verification flow uses, so "is this Google token
 * real" has exactly one implementation.
 */
class GoogleSignInService
{
    public function __construct(
        private readonly MobileEmailVerificationService $emailVerification,
    ) {}

    /**
     * @return array{user: User, is_new_user: bool}
     */
    public function authenticate(string $idToken): array
    {
        $claims = $this->emailVerification->verifiedGoogleClaims($idToken);
        $email = (string) ($claims['email'] ?? '');

        if ($email === '') {
            throw new HttpException(422, 'Google sign-in failed.');
        }

        return DB::transaction(function () use ($claims, $email): array {
            $existing = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // Google has just vouched for this address. An account that
                // reached us another way (mobile OTP, admin creation) may still
                // carry it unverified, and this is proof enough to settle that.
                if ($existing->email_verified_at === null) {
                    $existing->forceFill(['email_verified_at' => now()])->save();
                }

                return ['user' => $existing->fresh(), 'is_new_user' => false];
            }

            // forceFill, not create(): `email_verified_at` is guarded on the
            // model, and mass assignment drops it silently — which would leave a
            // brand new Google account looking unverified even though Google
            // just vouched for it.
            $user = new User;
            $user->forceFill([
                'name' => $this->displayName($claims, $email),
                'email' => $email,
                // The column is NOT NULL and this account has no password of its
                // own. A random one is never shown or used: signing in happens
                // through Google, and anyone wanting a password sets one via the
                // ordinary reset flow, which mails this same verified address.
                'password' => bcrypt(Str::random(48)),
                'email_verified_at' => now(),
            ])->save();

            return ['user' => $user, 'is_new_user' => true];
        });
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function displayName(array $claims, string $email): string
    {
        $name = trim((string) ($claims['name'] ?? ''));
        if ($name !== '') {
            return Str::limit($name, 255, '');
        }

        // Google omits the name when the member has not set one on their
        // account. The local part is a better placeholder than an empty field,
        // and onboarding asks for the real name straight afterwards.
        $local = trim((string) Str::before($email, '@'));

        return $local !== '' ? Str::limit($local, 255, '') : 'Member';
    }
}
