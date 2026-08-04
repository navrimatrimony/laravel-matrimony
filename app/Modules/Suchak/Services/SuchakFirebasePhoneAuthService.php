<?php

namespace App\Modules\Suchak\Services;

use App\Models\MobileOtpChallenge;
use App\Models\SuchakAccount;
use App\Models\User;
use App\Services\Api\MobileOtpService;
use App\Services\Auth\FirebaseIdTokenException;
use App\Services\Auth\FirebaseIdTokenVerifier;
use App\Services\Auth\FirebasePhoneIdentity;
use App\Support\MobileNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Suchak registration AND login, both over one Firebase-verified number.
 *
 * The two entry points differ only in their predicate — login requires the
 * Suchak to exist, registration requires it not to. Everything that decides
 * *who the caller is* happens once, here, in {@see proveNumber()}. There is
 * deliberately no second verifier and no "client says it verified" branch:
 * the number is read out of a signed token or the request fails.
 */
class SuchakFirebasePhoneAuthService
{
    public const PURPOSE_LOGIN = 'suchak_firebase_login';

    public const PURPOSE_REGISTER = 'suchak_firebase_register';

    /** Recorded on the verification row so §8 can later tell channels apart. */
    public const CHANNEL = 'firebase';

    public function __construct(
        private readonly FirebaseIdTokenVerifier $verifier,
        private readonly SuchakRegistrationService $registrationService,
    ) {}

    /**
     * Sign an EXISTING Suchak in with a Firebase-verified number.
     *
     * @return array{user: User, account: SuchakAccount, token: string, identity: FirebasePhoneIdentity}
     *
     * @throws FirebaseIdTokenException token not provably good
     * @throws SuchakFirebaseAuthException token fine, but the account is not
     */
    public function login(string $idToken, ?string $claimedMobile, Request $request): array
    {
        $identity = $this->proveNumber($idToken, $claimedMobile);

        // The VERIFIED number is the only lookup key. Resolving the account
        // from a request field instead is exactly the account-takeover hole
        // this phase exists to close.
        $user = User::query()->where('mobile', $identity->mobile)->first();
        $account = $user?->suchakAccount;

        if ($user === null || $account === null) {
            throw SuchakFirebaseAuthException::suchakNotFound();
        }

        $this->recordVerification($user, $identity, self::PURPOSE_LOGIN, $request);

        return [
            'user' => $user->refresh(),
            'account' => $account,
            'token' => $user->createToken('suchak-app')->plainTextToken,
            'identity' => $identity,
        ];
    }

    /**
     * Start a NEW staged Suchak registration on a Firebase-verified number.
     *
     * The mobile-verification step of the wizard is already satisfied when the
     * account is created, so the Suchak lands on `identity` rather than on an
     * OTP screen. No OTP is issued on this path at all.
     *
     * @return array{user: User, account: SuchakAccount, token: string, identity: FirebasePhoneIdentity}
     */
    public function startRegistration(string $idToken, ?string $claimedMobile, Request $request): array
    {
        $identity = $this->proveNumber($idToken, $claimedMobile);

        // Reuses the one staged-registration writer. `mobileAlreadyVerified`
        // is not a client flag — it is set here, after the signature check.
        $result = $this->registrationService->startMobileRegistration(
            $identity->mobile,
            $request->ip(),
            $request->userAgent(),
            mobileAlreadyVerified: true,
        );

        /** @var User $user */
        $user = $result['user'];
        /** @var SuchakAccount $account */
        $account = $result['account'];

        $this->recordVerification($user, $identity, self::PURPOSE_REGISTER, $request);

        return [
            'user' => $user->refresh(),
            'account' => $account->refresh(),
            'token' => $user->createToken('suchak-app')->plainTextToken,
            'identity' => $identity,
        ];
    }

    /**
     * Verify the token, then reconcile it with whatever number the client says
     * it was verifying.
     *
     * The client field is a CROSS-CHECK, never a source. If the app sends a
     * number at all it must be the one Firebase signed for; a disagreement is
     * refused outright rather than silently resolved in either direction,
     * because "resolve to the token" would let a mis-wired screen register the
     * wrong number under the Suchak's nose, and "resolve to the request" would
     * hand an attacker any account they can name.
     */
    private function proveNumber(string $idToken, ?string $claimedMobile): FirebasePhoneIdentity
    {
        $identity = $this->verifier->verify($idToken);

        $claimed = $claimedMobile === null ? null : trim($claimedMobile);
        if ($claimed === null || $claimed === '') {
            return $identity;
        }

        if (MobileNumber::normalize($claimed) !== $identity->mobile) {
            throw SuchakFirebaseAuthException::mobileMismatch();
        }

        return $identity;
    }

    /**
     * Record, honestly, that this number was proven — and by what.
     *
     * `mobile_otp_challenges` already owns "a mobile verification event, its
     * channel, its purpose and when it completed", so a Firebase verification
     * is one more row on it with `channel = firebase`, not a new table. The row
     * is written already-verified and with a null `otp_hash`, which is the
     * truth (no code was ever sent) and also makes it unusable by the OTP
     * verifier, which requires an unverified row and a hash that matches.
     *
     * Deliberately NOT written here: any acceptance tier, `mobile_match`, or
     * any other §8 grade. This proves who logged in; it does not prove who
     * accepted an agreement, and the blueprint is explicit that no tier may
     * claim a verification that did not happen.
     */
    private function recordVerification(
        User $user,
        FirebasePhoneIdentity $identity,
        string $purpose,
        Request $request,
    ): void {
        DB::transaction(function () use ($user, $identity, $purpose, $request): void {
            // First-verified-at keeps its existing meaning. Every individual
            // verification is on the challenge row below.
            if ($user->mobile_verified_at === null) {
                $user->forceFill(['mobile_verified_at' => now()])->save();
            }

            $termsVersion = $this->version($request, 'terms_version');
            $privacyVersion = $this->version($request, 'privacy_version');

            $challenge = MobileOtpChallenge::query()->create([
                'challenge_id' => (string) Str::uuid(),
                'mobile' => $identity->mobile,
                'channel' => self::CHANNEL,
                'purpose' => $purpose,
                'otp_hash' => null,
                'provider_uid' => $identity->uid,
                'attempts' => 0,
                'max_attempts' => 0,
                'expires_at' => $identity->expiresAt,
                'verified_at' => now(),
                'last_sent_at' => null,
                'resend_available_at' => null,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
                'locale' => $this->locale($request),
                'terms_version' => $termsVersion,
                'privacy_version' => $privacyVersion,
            ]);

            // Consent-first is a design freeze rule, and the OTP path recorded
            // it. Recorded through MobileOtpService's OWN writer so there is
            // one place UserConsent rows are created, not two.
            if ($termsVersion !== null && $privacyVersion !== null) {
                app(MobileOtpService::class)->persistConsents($user, $challenge);
            }
        });
    }

    /**
     * A consent version the client actually sent, or null.
     */
    private function version(Request $request, string $key): ?string
    {
        $value = trim((string) $request->input($key, ''));

        return $value === '' ? null : Str::limit($value, 64, '');
    }

    private function locale(Request $request): ?string
    {
        $locale = strtolower(trim((string) $request->input('locale', '')));

        return in_array($locale, ['mr', 'en'], true) ? $locale : null;
    }
}
