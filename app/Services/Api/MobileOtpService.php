<?php

namespace App\Services\Api;

use App\Models\AdminSetting;
use App\Models\MobileOtpChallenge;
use App\Models\User;
use App\Models\UserConsent;
use App\Services\Messaging\MetaWhatsAppCloudService;
use App\Support\MobileNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MobileOtpService
{
    public const OTP_TTL_SECONDS = 600;

    public const RESEND_AFTER_SECONDS = 60;

    public const MAX_ATTEMPTS = 5;

    private const SEND_MOBILE_LIMIT = 5;

    private const SEND_IP_LIMIT = 20;

    private const SEND_DECAY_SECONDS = 3600;

    private const VERIFY_IP_LIMIT = 60;

    /**
     * @return array{challenge: MobileOtpChallenge, expires_in: int, resend_after: int, delivery_channel: string, debug_otp: string|null}
     */
    public function sendChallenge(array $validated, Request $request): array
    {
        $mobile = MobileNumber::normalize((string) ($validated['mobile'] ?? ''));
        if ($mobile === null) {
            throw ValidationException::withMessages([
                'mobile' => 'Enter a valid 10 digit mobile number.',
            ]);
        }

        return $this->issueChallenge($mobile, [
            'purpose' => (string) ($validated['purpose'] ?? 'login_or_register'),
            'locale' => $this->normalizeLocale($validated['locale'] ?? null),
            'terms_version' => (string) $validated['terms_version'],
            'privacy_version' => (string) $validated['privacy_version'],
            'whatsapp_alerts_opt_in' => array_key_exists('whatsapp_alerts_opt_in', $validated)
                ? (bool) $validated['whatsapp_alerts_opt_in']
                : null,
        ], $request);
    }

    /**
     * Issue an OTP challenge for a signed-in member who is claiming a NEW
     * number for their own account.
     *
     * Deliberately NOT a second OTP implementation: it is the same delivery,
     * the same challenge row, the same limits and the same cooldown as
     * {@see sendChallenge()} — it only differs in that it records no consent
     * versions (the member already accepted them) and it refuses up-front a
     * number that is already on another account.
     *
     * @return array{challenge: MobileOtpChallenge, expires_in: int, resend_after: int, delivery_channel: string, debug_otp: string|null}
     */
    public function sendPossessionChallenge(User $user, string $mobile, Request $request): array
    {
        $mobile = $this->normalizeMobileOrFail($mobile);
        $this->assertMobileAvailable($user, $mobile);

        $userKey = $this->rateKey('account-mobile-otp-send:user', (string) $user->id);
        if (RateLimiter::tooManyAttempts($userKey, self::SEND_MOBILE_LIMIT)) {
            throw new HttpException(429, 'Too many OTP requests for this account.');
        }

        $result = $this->issueChallenge($mobile, [
            'purpose' => 'account_mobile_change',
            'locale' => $this->normalizeLocale($user->preferred_locale ?? null),
            'terms_version' => null,
            'privacy_version' => null,
            'whatsapp_alerts_opt_in' => null,
        ], $request);

        RateLimiter::hit($userKey, self::SEND_DECAY_SECONDS);

        return $result;
    }

    /**
     * Prove the signed-in member holds the number the challenge was sent to,
     * then — and only then — write it onto their account.
     *
     * No session is issued, no user is created, no consent row is written.
     * The OTP itself is checked by the SAME locked reader the login flow uses.
     */
    public function verifyPossession(User $user, string $challengeId, string $otp, Request $request): User
    {
        $this->assertVerifyIpLimit($request);

        $result = DB::transaction(function () use ($user, $challengeId, $otp): array {
            $challenge = MobileOtpChallenge::query()
                ->where('challenge_id', $challengeId)
                ->where('purpose', 'account_mobile_change')
                ->lockForUpdate()
                ->first();

            $outcome = $this->consumeChallengeOtp($challenge, $otp);
            if ($outcome !== null) {
                return $outcome;
            }

            /** @var MobileOtpChallenge $challenge */
            $mobile = (string) $challenge->mobile;

            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->assertMobileAvailable($locked, $mobile, true);

            $locked->forceFill([
                'mobile' => $mobile,
                'mobile_verified_at' => now(),
            ])->save();

            return ['user' => $locked->fresh('matrimonyProfile')];
        });

        $this->throwOtpError($result);

        return $result['user'];
    }

    /**
     * The single writer of an OTP challenge row + its delivery.
     *
     * @param  array{purpose: string, locale: string|null, terms_version: string|null, privacy_version: string|null, whatsapp_alerts_opt_in: bool|null}  $attributes
     * @return array{challenge: MobileOtpChallenge, expires_in: int, resend_after: int, delivery_channel: string, debug_otp: string|null}
     */
    private function issueChallenge(string $mobile, array $attributes, Request $request): array
    {
        $this->assertSendLimits($mobile, $request);
        $this->assertCooldownAvailable($mobile);

        $otp = (string) random_int(100000, 999999);

        $delivery = $this->deliverOtp($mobile, $otp);
        if ($delivery === null) {
            throw new HttpException(503, 'OTP provider is not configured. Configure WhatsApp Cloud OTP for production.');
        }

        $now = now();
        $challenge = MobileOtpChallenge::query()->create([
            'challenge_id' => (string) Str::uuid(),
            'mobile' => $mobile,
            'channel' => $delivery['channel'],
            'purpose' => $attributes['purpose'],
            'otp_hash' => Hash::make($otp),
            'attempts' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'expires_at' => $now->copy()->addSeconds(self::OTP_TTL_SECONDS),
            'last_sent_at' => $now,
            'resend_available_at' => $now->copy()->addSeconds(self::RESEND_AFTER_SECONDS),
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'locale' => $attributes['locale'],
            'terms_version' => $attributes['terms_version'],
            'privacy_version' => $attributes['privacy_version'],
            'whatsapp_alerts_opt_in' => $attributes['whatsapp_alerts_opt_in'],
        ]);

        $this->hitSendLimits($mobile, $request);

        return [
            'challenge' => $challenge,
            'expires_in' => self::OTP_TTL_SECONDS,
            'resend_after' => self::RESEND_AFTER_SECONDS,
            'delivery_channel' => $delivery['channel'],
            'debug_otp' => $this->shouldExposeDebugOtp() ? $otp : null,
        ];
    }

    /**
     * The single OTP checker. Must be called inside a transaction on a row
     * already locked for update.
     *
     * Returns null when the OTP was correct (the challenge is then marked
     * verified), or an `otp_error` array for the caller to surface.
     *
     * @return array{otp_error: string}|null
     */
    private function consumeChallengeOtp(?MobileOtpChallenge $challenge, string $otp): ?array
    {
        if (! $challenge || $challenge->verified_at !== null) {
            throw ValidationException::withMessages([
                'otp' => 'Invalid or expired OTP.',
            ]);
        }

        if ($challenge->expires_at === null || $challenge->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'otp' => 'Invalid or expired OTP.',
            ]);
        }

        if ((int) $challenge->attempts >= (int) $challenge->max_attempts) {
            throw new HttpException(429, 'OTP attempt limit exceeded.');
        }

        if (! Hash::check($otp, (string) $challenge->otp_hash)) {
            $attempts = (int) $challenge->attempts + 1;
            $challenge->forceFill(['attempts' => $attempts])->save();

            return ['otp_error' => $attempts >= (int) $challenge->max_attempts ? 'limit' : 'invalid'];
        }

        $challenge->forceFill(['verified_at' => now()])->save();

        return null;
    }

    private function normalizeMobileOrFail(string $mobile): string
    {
        $normalized = MobileNumber::normalize($mobile);
        if ($normalized === null) {
            throw ValidationException::withMessages([
                'mobile' => 'Enter a valid 10 digit mobile number.',
            ]);
        }

        return $normalized;
    }

    /**
     * Mirror of the email service's duplicate guard. `users.mobile` carries a
     * unique index, so a second account holding it is a hard 409, never a
     * silent overwrite.
     */
    private function assertMobileAvailable(User $user, string $mobile, bool $lock = false): void
    {
        $query = User::query()
            ->whereKeyNot($user->id)
            ->where('mobile', $mobile);

        if ($lock) {
            $query->lockForUpdate();
        }

        if ($query->exists()) {
            throw new HttpException(409, 'This mobile number is already used by another account.');
        }
    }

    private function assertVerifyIpLimit(Request $request): void
    {
        $verifyIpKey = $this->rateKey('mobile-otp-verify:ip', $request->ip() ?? 'unknown');
        if (RateLimiter::tooManyAttempts($verifyIpKey, self::VERIFY_IP_LIMIT)) {
            throw new HttpException(429, 'Too many OTP verification attempts.');
        }
        RateLimiter::hit($verifyIpKey, self::SEND_DECAY_SECONDS);
    }

    private function throwOtpError(array $result): void
    {
        if (($result['otp_error'] ?? null) === 'limit') {
            throw new HttpException(429, 'OTP attempt limit exceeded.');
        }

        if (($result['otp_error'] ?? null) === 'invalid') {
            throw ValidationException::withMessages([
                'otp' => 'Invalid or expired OTP.',
            ]);
        }
    }

    /**
     * @return array{user: User, token: string, is_new_account: bool}
     */
    /**
     * @param  User|null  $actor  Whoever is already signed in, when anyone is.
     *                            Their account keeps the session and simply
     *                            gains the verified number.
     */
    public function verifyChallenge(array $validated, Request $request, ?User $actor = null): array
    {
        $mobile = $this->normalizeMobileOrFail((string) ($validated['mobile'] ?? ''));

        $this->assertVerifyIpLimit($request);

        // Whoever is already signed in, if anyone. Passed in rather than read
        // here so the caller decides what counts as authenticated.
        $result = DB::transaction(function () use ($validated, $mobile, $actor): array {
            $challenge = MobileOtpChallenge::query()
                ->where('challenge_id', (string) $validated['challenge_id'])
                ->where('mobile', $mobile)
                ->lockForUpdate()
                ->first();

            $outcome = $this->consumeChallengeOtp($challenge, (string) $validated['otp']);
            if ($outcome !== null) {
                return $outcome;
            }

            /** @var MobileOtpChallenge $challenge */
            $owner = User::query()
                ->where('mobile', $mobile)
                ->lockForUpdate()
                ->first();

            // Someone is already signed in — they are verifying THEIR number,
            // not signing in with it.
            //
            // Resolving by mobile alone used to switch them onto whatever
            // account held it, and onto a brand new empty one when nothing did.
            // A member who had just finished onboarding was handed a session
            // for an account with no name and no profile, so the app sent them
            // back to the first question and their work sat orphaned on the
            // account they had actually filled it in on.
            if ($actor !== null) {
                if ($owner !== null && (int) $owner->id !== (int) $actor->id) {
                    // Never move a number off another account: that would let
                    // anyone claim a number they can receive one code on.
                    throw new HttpException(409, 'This mobile number is already used by another account.');
                }

                $actor->forceFill([
                    'mobile' => $mobile,
                    'mobile_verified_at' => now(),
                ])->save();

                $this->persistConsents($actor, $challenge);
                $this->persistAlertsOptIn($actor, $challenge->whatsapp_alerts_opt_in);

                return [
                    // No new token. The session they already hold stays valid,
                    // and issuing another would only invite the app to swap it.
                    'user' => $actor->fresh('matrimonyProfile'),
                    'token' => null,
                    'is_new_account' => false,
                ];
            }

            $user = $owner;

            $isNewAccount = false;
            if ($user === null) {
                $isNewAccount = true;
                $user = User::query()->create([
                    'name' => null,
                    'email' => null,
                    'mobile' => $mobile,
                    'mobile_verified_at' => now(),
                    'preferred_locale' => $this->normalizeLocale($challenge->locale) ?? 'mr',
                    'password' => null,
                ]);
            } else {
                $updates = [];
                if ($user->mobile_verified_at === null) {
                    $updates['mobile_verified_at'] = now();
                }
                if (($user->preferred_locale ?? null) === null && $challenge->locale) {
                    $updates['preferred_locale'] = $this->normalizeLocale($challenge->locale);
                }
                if ($updates !== []) {
                    $user->forceFill($updates)->save();
                }
            }

            $this->persistConsents($user, $challenge);
            $this->persistAlertsOptIn($user, $challenge->whatsapp_alerts_opt_in);

            $token = $user->createToken('mobile-app')->plainTextToken;

            return [
                'user' => $user->fresh('matrimonyProfile'),
                'token' => $token,
                'is_new_account' => $isNewAccount,
            ];
        });

        $this->throwOtpError($result);

        return $result;
    }

    public function accountStateFor(User $user, bool $isNewAccount = false): array
    {
        $user->loadMissing('matrimonyProfile');
        $hasProfile = $user->matrimonyProfile !== null;
        $creatorName = trim((string) ($user->name ?? ''));

        return [
            'is_new_account' => $isNewAccount,
            'has_profile' => $hasProfile,
            'next_action' => $creatorName === ''
                ? 'account_details'
                : ($hasProfile ? 'resume_onboarding' : 'start_onboarding'),
        ];
    }

    public function persistAlertsOptIn(User $user, ?bool $optIn): void
    {
        if ($optIn === null) {
            return;
        }

        $prefs = is_array($user->notification_preferences) ? $user->notification_preferences : [];
        $prefs['whatsapp_alerts_opt_in'] = $optIn;
        $prefs['profile_alerts_opt_in'] = $optIn;

        $user->forceFill(['notification_preferences' => $prefs])->save();
    }

    public function userPayload(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'creator_name' => $user->name,
            'mobile' => $user->mobile,
            'mobile_verified_at' => optional($user->mobile_verified_at)?->toISOString(),
            'email' => $user->email,
            'email_verified_at' => optional($user->email_verified_at)?->toISOString(),
            'preferred_locale' => $user->preferred_locale,
        ];
    }

    private function assertSendLimits(string $mobile, Request $request): void
    {
        $mobileKey = $this->rateKey('mobile-otp-send:mobile', $mobile);
        if (RateLimiter::tooManyAttempts($mobileKey, self::SEND_MOBILE_LIMIT)) {
            throw new HttpException(429, 'Too many OTP requests for this mobile.');
        }

        $ipKey = $this->rateKey('mobile-otp-send:ip', $request->ip() ?? 'unknown');
        if (RateLimiter::tooManyAttempts($ipKey, self::SEND_IP_LIMIT)) {
            throw new HttpException(429, 'Too many OTP requests from this IP address.');
        }
    }

    private function hitSendLimits(string $mobile, Request $request): void
    {
        RateLimiter::hit($this->rateKey('mobile-otp-send:mobile', $mobile), self::SEND_DECAY_SECONDS);
        RateLimiter::hit($this->rateKey('mobile-otp-send:ip', $request->ip() ?? 'unknown'), self::SEND_DECAY_SECONDS);
    }

    private function assertCooldownAvailable(string $mobile): void
    {
        $latest = MobileOtpChallenge::query()
            ->where('mobile', $mobile)
            ->whereNull('verified_at')
            ->orderByDesc('created_at')
            ->first();

        if ($latest?->resend_available_at && $latest->resend_available_at->isFuture()) {
            $seconds = max(1, now()->diffInSeconds($latest->resend_available_at, false));
            throw new HttpException(429, 'Please wait before requesting another OTP.', null, [
                'Retry-After' => (string) $seconds,
            ]);
        }
    }

    /**
     * Environment matrix:
     * - local / testing → Dev OTP (QA never blocked)
     * - staging → configurable; WhatsApp if configured else Dev OTP
     * - production → WhatsApp when configured; optional AdminSetting
     *   `mobile_verification_mode=dev_show` ONLY while WhatsApp is not yet wired
     *   (TEST OTP, clearly marked — never silent fake success)
     *
     * @return array{channel: string}|null
     */
    private function deliverOtp(string $mobile, string $otp): ?array
    {
        $mode = $this->resolveDeliveryMode();

        if ($mode === 'dev') {
            return ['channel' => 'dev'];
        }

        /** @var MetaWhatsAppCloudService $whatsapp */
        $whatsapp = app(MetaWhatsAppCloudService::class);
        if (! $whatsapp->isConfiguredForOtp()) {
            return null;
        }

        if (! $whatsapp->sendOtp($mobile, $otp)) {
            return null;
        }

        return ['channel' => 'whatsapp'];
    }

    /**
     * @return 'dev'|'whatsapp'
     */
    private function resolveDeliveryMode(): string
    {
        if (app()->environment(['local', 'testing'])) {
            return 'dev';
        }

        /** @var MetaWhatsAppCloudService $whatsapp */
        $whatsapp = app(MetaWhatsAppCloudService::class);
        $whatsappReady = $whatsapp->isConfiguredForOtp();
        $explicit = strtolower(trim((string) config('otp.delivery', '')));

        if (app()->isProduction()) {
            if ($whatsappReady) {
                return 'whatsapp';
            }

            // Temporary QA bridge until WhatsApp Cloud OTP is connected.
            // Must be explicit (env or AdminSetting). Response is marked TEST.
            if ($explicit === 'dev' || $this->adminAllowsTemporaryDevOtp()) {
                return 'dev';
            }

            return 'whatsapp';
        }

        // staging (and any non-production named env)
        if ($explicit === 'dev') {
            return 'dev';
        }
        if ($explicit === 'whatsapp') {
            return 'whatsapp';
        }

        return $whatsappReady ? 'whatsapp' : 'dev';
    }

    private function adminAllowsTemporaryDevOtp(): bool
    {
        try {
            return AdminSetting::getValue('mobile_verification_mode', '') === 'dev_show';
        } catch (\Throwable) {
            return false;
        }
    }

    private function shouldExposeDebugOtp(): bool
    {
        return $this->resolveDeliveryMode() === 'dev';
    }

    /**
     * Public so the Firebase Phone Auth path can record consent through THIS
     * writer instead of growing a second one — the challenge row it hands over
     * carries the same terms/privacy versions and metadata.
     */
    public function persistConsents(User $user, MobileOtpChallenge $challenge): void
    {
        $now = now();
        foreach ([
            'terms' => $challenge->terms_version,
            'privacy' => $challenge->privacy_version,
        ] as $type => $version) {
            UserConsent::query()->create([
                'user_id' => $user->id,
                'consent_type' => $type,
                'version' => (string) $version,
                'accepted_at' => $now,
                'ip_address' => $challenge->ip_address,
                'user_agent' => $challenge->user_agent,
                'locale' => $challenge->locale,
                'metadata' => [
                    'channel' => $challenge->channel,
                    'purpose' => $challenge->purpose,
                    'challenge_id' => $challenge->challenge_id,
                ],
            ]);
        }
    }

    private function normalizeLocale(mixed $locale): ?string
    {
        $locale = strtolower(trim((string) $locale));
        if ($locale === '') {
            return null;
        }

        return in_array($locale, ['mr', 'en'], true) ? $locale : null;
    }

    private function rateKey(string $prefix, string $value): string
    {
        return $prefix.':'.sha1($value);
    }
}
