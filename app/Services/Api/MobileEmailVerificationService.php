<?php

namespace App\Services\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MobileEmailVerificationService
{
    public const OTP_TTL_SECONDS = 600;

    public const RESEND_AFTER_SECONDS = 60;

    public const MAX_ATTEMPTS = 5;

    private const SEND_EMAIL_LIMIT = 5;

    private const SEND_IP_LIMIT = 20;

    private const SEND_DECAY_SECONDS = 3600;

    private const VERIFY_IP_LIMIT = 60;

    /**
     * @return array{challenge_id: string, expires_in: int, resend_after: int, debug_otp: string|null}
     */
    public function sendOtp(User $user, string $email, Request $request): array
    {
        $email = $this->normalizeEmail($email);
        $this->assertEmailAvailable($user, $email);

        $this->assertSendLimits($user, $email, $request);

        $otp = (string) random_int(100000, 999999);
        $challengeId = (string) Str::uuid();

        if (! $this->shouldExposeDebugOtp()) {
            $this->deliverEmailOtp($email, $otp);
        }

        Cache::put($this->challengeKey($challengeId), [
            'user_id' => (int) $user->id,
            'email' => $email,
            'otp_hash' => Hash::make($otp),
            'attempts' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'expires_at' => now()->addSeconds(self::OTP_TTL_SECONDS)->timestamp,
        ], self::OTP_TTL_SECONDS);

        $this->hitSendLimits($user, $email, $request);

        return [
            'challenge_id' => $challengeId,
            'expires_in' => self::OTP_TTL_SECONDS,
            'resend_after' => self::RESEND_AFTER_SECONDS,
            'debug_otp' => $this->shouldExposeDebugOtp() ? $otp : null,
        ];
    }

    public function verifyOtp(User $user, string $challengeId, string $email, string $otp, Request $request): User
    {
        $email = $this->normalizeEmail($email);

        $verifyIpKey = $this->rateKey('mobile-email-otp-verify:ip', $request->ip() ?? 'unknown');
        if (RateLimiter::tooManyAttempts($verifyIpKey, self::VERIFY_IP_LIMIT)) {
            throw new HttpException(429, 'Too many OTP verification attempts.');
        }
        RateLimiter::hit($verifyIpKey, self::SEND_DECAY_SECONDS);

        $key = $this->challengeKey($challengeId);
        $challenge = Cache::get($key);

        if (! is_array($challenge)
            || (int) ($challenge['user_id'] ?? 0) !== (int) $user->id
            || (string) ($challenge['email'] ?? '') !== $email
            || (int) ($challenge['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages([
                'otp' => 'Invalid or expired OTP.',
            ]);
        }

        $attempts = (int) ($challenge['attempts'] ?? 0);
        $maxAttempts = (int) ($challenge['max_attempts'] ?? self::MAX_ATTEMPTS);
        if ($attempts >= $maxAttempts) {
            throw new HttpException(429, 'OTP attempt limit exceeded.');
        }

        if (! Hash::check($otp, (string) ($challenge['otp_hash'] ?? ''))) {
            $challenge['attempts'] = $attempts + 1;
            $remainingSeconds = max(1, (int) ($challenge['expires_at'] ?? now()->timestamp) - now()->timestamp);
            Cache::put($key, $challenge, $remainingSeconds);

            if ((int) $challenge['attempts'] >= $maxAttempts) {
                throw new HttpException(429, 'OTP attempt limit exceeded.');
            }

            throw ValidationException::withMessages([
                'otp' => 'Invalid or expired OTP.',
            ]);
        }

        Cache::forget($key);

        return $this->markEmailVerified($user, $email);
    }

    public function verifyGoogleEmail(User $user, string $email, string $idToken): User
    {
        $email = $this->normalizeEmail($email);
        $idToken = trim($idToken);
        if ($idToken === '') {
            throw new HttpException(422, 'Google email verification token is missing.');
        }

        $claims = $this->googleTokenClaims($idToken);
        $googleEmail = $this->normalizeEmail((string) ($claims['email'] ?? ''));
        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL) === true;
        $audience = trim((string) ($claims['aud'] ?? ''));
        $allowedAudiences = $this->googleClientIds();

        if ($allowedAudiences === []) {
            throw new HttpException(422, 'Google sign-in is not configured.');
        }

        if (! in_array($audience, $allowedAudiences, true)) {
            throw new HttpException(422, 'Google email verification audience is invalid.');
        }

        if ($googleEmail === '' || $googleEmail !== $email || ! $emailVerified) {
            throw new HttpException(422, 'Google email verification failed.');
        }

        return $this->markEmailVerified($user, $email);
    }

    private function googleTokenClaims(string $idToken): array
    {
        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->get('https://oauth2.googleapis.com/tokeninfo', [
                    'id_token' => $idToken,
                ]);
        } catch (\Throwable) {
            throw new HttpException(422, 'Google email verification failed.');
        }

        if (! $response->ok()) {
            throw new HttpException(422, 'Google email verification failed.');
        }

        $claims = $response->json();
        if (! is_array($claims)) {
            throw new HttpException(422, 'Google email verification failed.');
        }

        return $claims;
    }

    private function markEmailVerified(User $user, string $email): User
    {
        return DB::transaction(function () use ($user, $email): User {
            /** @var User $locked */
            $locked = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEmailAvailable($locked, $email);

            $locked->forceFill([
                'email' => $email,
                'email_verified_at' => now(),
            ])->save();

            return $locked->fresh('matrimonyProfile');
        });
    }

    private function assertEmailAvailable(User $user, string $email): void
    {
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'Enter a valid email address.',
            ]);
        }

        $exists = User::query()
            ->whereKeyNot($user->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();

        if ($exists) {
            throw new HttpException(409, 'Email belongs to another account.');
        }
    }

    private function deliverEmailOtp(string $email, string $otp): void
    {
        try {
            Mail::raw(
                "Your Navri Matrimony email verification OTP is {$otp}. It expires in 10 minutes.",
                static function ($message) use ($email): void {
                    $message->to($email)->subject('Verify your Navri Matrimony email');
                }
            );
        } catch (\Throwable) {
            throw new HttpException(503, 'Email OTP provider is not configured.');
        }
    }

    private function assertSendLimits(User $user, string $email, Request $request): void
    {
        $emailKey = $this->rateKey('mobile-email-otp-send:email', $user->id.':'.$email);
        if (RateLimiter::tooManyAttempts($emailKey, self::SEND_EMAIL_LIMIT)) {
            throw new HttpException(429, 'Too many OTP requests for this email.');
        }

        $cooldownKey = $this->rateKey('mobile-email-otp-send:cooldown', $user->id.':'.$email);
        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            $seconds = RateLimiter::availableIn($cooldownKey);
            throw new HttpException(429, 'Please wait before requesting another OTP.', null, [
                'Retry-After' => (string) max(1, $seconds),
            ]);
        }

        $ipKey = $this->rateKey('mobile-email-otp-send:ip', $request->ip() ?? 'unknown');
        if (RateLimiter::tooManyAttempts($ipKey, self::SEND_IP_LIMIT)) {
            throw new HttpException(429, 'Too many OTP requests from this IP address.');
        }
    }

    private function hitSendLimits(User $user, string $email, Request $request): void
    {
        RateLimiter::hit($this->rateKey('mobile-email-otp-send:email', $user->id.':'.$email), self::SEND_DECAY_SECONDS);
        RateLimiter::hit($this->rateKey('mobile-email-otp-send:cooldown', $user->id.':'.$email), self::RESEND_AFTER_SECONDS);
        RateLimiter::hit($this->rateKey('mobile-email-otp-send:ip', $request->ip() ?? 'unknown'), self::SEND_DECAY_SECONDS);
    }

    private function googleClientIds(): array
    {
        $ids = config('services.google.client_ids', []);
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            is_array($ids) ? $ids : []
        )));
    }

    private function shouldExposeDebugOtp(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function challengeKey(string $challengeId): string
    {
        return 'mobile-email-otp-challenge:'.$challengeId;
    }

    private function rateKey(string $prefix, string $value): string
    {
        return $prefix.':'.sha1($value);
    }
}
