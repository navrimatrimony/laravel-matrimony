<?php

namespace App\Services\Api;

use App\Models\AdminSetting;
use App\Models\MobileOtpChallenge;
use App\Models\User;
use App\Models\UserConsent;
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
     * @return array{challenge: MobileOtpChallenge, expires_in: int, resend_after: int, debug_otp: string|null}
     */
    public function sendChallenge(array $validated, Request $request): array
    {
        $mobile = MobileNumber::normalize((string) ($validated['mobile'] ?? ''));
        if ($mobile === null) {
            throw ValidationException::withMessages([
                'mobile' => 'Enter a valid 10 digit mobile number.',
            ]);
        }

        $this->assertSendLimits($mobile, $request);
        $this->assertCooldownAvailable($mobile);

        $otp = (string) random_int(100000, 999999);

        if (! $this->deliverSmsOtp($mobile, $otp)) {
            throw new HttpException(503, 'SMS OTP provider is not configured.');
        }

        $now = now();
        $challenge = MobileOtpChallenge::query()->create([
            'challenge_id' => (string) Str::uuid(),
            'mobile' => $mobile,
            'channel' => 'sms',
            'purpose' => (string) ($validated['purpose'] ?? 'login_or_register'),
            'otp_hash' => Hash::make($otp),
            'attempts' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'expires_at' => $now->copy()->addSeconds(self::OTP_TTL_SECONDS),
            'last_sent_at' => $now,
            'resend_available_at' => $now->copy()->addSeconds(self::RESEND_AFTER_SECONDS),
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'locale' => $this->normalizeLocale($validated['locale'] ?? null),
            'terms_version' => (string) $validated['terms_version'],
            'privacy_version' => (string) $validated['privacy_version'],
            'whatsapp_alerts_opt_in' => array_key_exists('whatsapp_alerts_opt_in', $validated)
                ? (bool) $validated['whatsapp_alerts_opt_in']
                : null,
        ]);

        $this->hitSendLimits($mobile, $request);

        return [
            'challenge' => $challenge,
            'expires_in' => self::OTP_TTL_SECONDS,
            'resend_after' => self::RESEND_AFTER_SECONDS,
            'debug_otp' => $this->shouldExposeDebugOtp() ? $otp : null,
        ];
    }

    /**
     * @return array{user: User, token: string, is_new_account: bool}
     */
    public function verifyChallenge(array $validated, Request $request): array
    {
        $mobile = MobileNumber::normalize((string) ($validated['mobile'] ?? ''));
        if ($mobile === null) {
            throw ValidationException::withMessages([
                'mobile' => 'Enter a valid 10 digit mobile number.',
            ]);
        }

        $verifyIpKey = $this->rateKey('mobile-otp-verify:ip', $request->ip() ?? 'unknown');
        if (RateLimiter::tooManyAttempts($verifyIpKey, self::VERIFY_IP_LIMIT)) {
            throw new HttpException(429, 'Too many OTP verification attempts.');
        }
        RateLimiter::hit($verifyIpKey, self::SEND_DECAY_SECONDS);

        $result = DB::transaction(function () use ($validated, $mobile): array {
            $challenge = MobileOtpChallenge::query()
                ->where('challenge_id', (string) $validated['challenge_id'])
                ->where('mobile', $mobile)
                ->lockForUpdate()
                ->first();

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

            if (! Hash::check((string) $validated['otp'], (string) $challenge->otp_hash)) {
                $attempts = (int) $challenge->attempts + 1;
                $challenge->forceFill([
                    'attempts' => $attempts,
                ])->save();

                if ($attempts >= (int) $challenge->max_attempts) {
                    return ['otp_error' => 'limit'];
                }

                return ['otp_error' => 'invalid'];
            }

            $challenge->forceFill([
                'verified_at' => now(),
            ])->save();

            $user = User::query()
                ->where('mobile', $mobile)
                ->lockForUpdate()
                ->first();

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

        if (($result['otp_error'] ?? null) === 'limit') {
            throw new HttpException(429, 'OTP attempt limit exceeded.');
        }

        if (($result['otp_error'] ?? null) === 'invalid') {
            throw ValidationException::withMessages([
                'otp' => 'Invalid or expired OTP.',
            ]);
        }

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

    private function deliverSmsOtp(string $mobile, string $otp): bool
    {
        unset($mobile, $otp);

        return $this->shouldExposeDebugOtp();
    }

    private function shouldExposeDebugOtp(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        try {
            return AdminSetting::getValue('mobile_verification_mode', '') === 'dev_show';
        } catch (\Throwable) {
            return false;
        }
    }

    private function persistConsents(User $user, MobileOtpChallenge $challenge): void
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
