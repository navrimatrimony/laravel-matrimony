<?php

namespace App\Services\Intake;

use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Api\MobileEmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Bulk public registration — account email and password steps.
 *
 * Delegates verification to MobileEmailVerificationService (same as mobile app).
 */
class BulkIntakeRegistrationAccountSetupService
{
    public const SESSION_OTP_PREFIX = 'bulk_registration_email_otp.';

    public function __construct(
        private readonly BulkIntakeRegistrationProfileApplyService $profileApplyService,
        private readonly MobileEmailVerificationService $emailVerification,
    ) {}

    public function userForItem(BulkIntakeBatchItem $item): ?User
    {
        $profile = $this->profileApplyService->profileForItem($item);
        if ($profile === null || (int) ($profile->user_id ?? 0) <= 0) {
            return null;
        }

        $user = User::query()->find((int) $profile->user_id);

        return $user instanceof User ? $user : null;
    }

    public function ensureAuthenticated(BulkIntakeBatchItem $item): User
    {
        $user = $this->userForItem($item);
        if (! $user instanceof User || $user->isAnyAdmin()) {
            throw ValidationException::withMessages([
                'registration' => 'Registration account is missing.',
            ]);
        }

        if ($this->shouldPreserveCurrentAdminSession()) {
            return $user;
        }

        if (! Auth::check() || (int) Auth::id() !== (int) $user->id) {
            Auth::login($user);
        }

        return $user;
    }

    /**
     * Admin bulk-intake testing must not hijack the browser session.
     */
    public function isAdminPreviewSession(): bool
    {
        return $this->shouldPreserveCurrentAdminSession();
    }

    private function shouldPreserveCurrentAdminSession(): bool
    {
        $current = Auth::user();

        return $current instanceof User && $current->isAnyAdmin();
    }

    public function isEmailStepComplete(BulkIntakeBatchItem $item, ?User $user = null): bool
    {
        if ($this->registrationMetaTimestamp($item, 'email_step_skipped_at') !== null) {
            return true;
        }

        if ($this->registrationMetaTimestamp($item, 'email_step_completed_at') !== null) {
            return true;
        }

        $user ??= $this->userForItem($item);

        return $user instanceof User
            && $user->email_verified_at !== null
            && filled($user->email);
    }

    public function isPasswordStepComplete(BulkIntakeBatchItem $item): bool
    {
        return $this->registrationMetaTimestamp($item, 'password_step_skipped_at') !== null
            || $this->registrationMetaTimestamp($item, 'password_step_completed_at') !== null;
    }

    public function googleWebClientId(): ?string
    {
        $webClientId = trim((string) config('services.google.web_client_id', ''));
        if ($webClientId !== '') {
            return $webClientId;
        }

        $ids = config('services.google.client_ids', []);
        if (! is_array($ids) || $ids === []) {
            return null;
        }

        $first = trim((string) ($ids[0] ?? ''));

        return $first !== '' ? $first : null;
    }

    public function googleSignInConfigured(): bool
    {
        return $this->googleWebClientId() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function emailStepPayload(BulkIntakeBatchItem $item, string $token): array
    {
        $user = $this->ensureAuthenticated($item);
        $candidate = app(BulkIntakeCandidateDisplayService::class)->candidateForItem($item);

        return [
            'item' => $item,
            'token' => $token,
            'candidate_name' => is_string($candidate['full_name'] ?? null) ? $candidate['full_name'] : $user->name,
            'user' => $user,
            'google_client_id' => $this->googleWebClientId(),
            'google_sign_in_configured' => $this->googleSignInConfigured(),
            'verified_email' => $user->email_verified_at !== null ? $user->email : null,
            'otp_session' => $this->otpSessionForToken($token),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function passwordStepPayload(BulkIntakeBatchItem $item, string $token): array
    {
        $user = $this->ensureAuthenticated($item);
        $candidate = app(BulkIntakeCandidateDisplayService::class)->candidateForItem($item);

        return [
            'item' => $item,
            'token' => $token,
            'candidate_name' => is_string($candidate['full_name'] ?? null) ? $candidate['full_name'] : $user->name,
            'user' => $user,
        ];
    }

    public function verifyGoogleEmail(BulkIntakeBatchItem $item, string $email, string $idToken, Request $request): User
    {
        $user = $this->ensureAuthenticated($item);

        try {
            $verified = $this->emailVerification->verifyGoogleEmail($user, $email, $idToken);
        } catch (HttpException $e) {
            throw ValidationException::withMessages([
                'email' => $this->httpExceptionMessage($e, 'Google ईमेल पुष्टी अयशस्वी. कृपया OTP वापरून पुन्हा प्रयत्न करा.'),
            ]);
        }

        $this->markEmailStepCompleted($item, 'google');

        return $verified;
    }

    /**
     * @return array{challenge_id: string, expires_in: int, resend_after: int, debug_otp: string|null}
     */
    public function sendEmailOtp(BulkIntakeBatchItem $item, string $email, string $token, Request $request): array
    {
        $user = $this->ensureAuthenticated($item);

        try {
            $result = $this->emailVerification->sendOtp($user, $email, $request);
        } catch (HttpException $e) {
            throw ValidationException::withMessages([
                'email' => $this->httpExceptionMessage($e, 'OTP पाठवता आला नाही. कृपया थोड्या वेळाने पुन्हा प्रयत्न करा.'),
            ]);
        }

        session()->put($this->otpSessionKey($token), [
            'challenge_id' => $result['challenge_id'],
            'email' => strtolower(trim($email)),
            'sent_at' => now()->toISOString(),
        ]);

        return $result;
    }

    public function verifyEmailOtp(BulkIntakeBatchItem $item, string $token, string $otp, Request $request): User
    {
        $user = $this->ensureAuthenticated($item);
        $session = $this->otpSessionForToken($token);
        if ($session === null) {
            throw ValidationException::withMessages([
                'otp' => 'OTP सत्र संपले. कृपया पुन्हा OTP मागवा.',
            ]);
        }

        try {
            $verified = $this->emailVerification->verifyOtp(
                $user,
                (string) $session['challenge_id'],
                (string) $session['email'],
                $otp,
                $request,
            );
        } catch (HttpException $e) {
            throw ValidationException::withMessages([
                'otp' => $this->httpExceptionMessage($e, 'OTP चुकीचा किंवा कालबाह्य झाला.'),
            ]);
        }

        session()->forget($this->otpSessionKey($token));
        $this->markEmailStepCompleted($item, 'email_otp');

        return $verified;
    }

    public function skipEmailStep(BulkIntakeBatchItem $item): void
    {
        $this->updateRegistrationMeta($item, [
            'email_step_skipped_at' => now()->toISOString(),
            'email_step_skipped_via' => 'public_web_form',
        ]);
    }

    public function setPassword(BulkIntakeBatchItem $item, string $password, string $passwordConfirmation): void
    {
        $user = $this->ensureAuthenticated($item);

        $validated = validator(
            [
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
            ],
            [
                'password' => ['required', 'confirmed', Rules\Password::defaults()],
            ],
            [],
            [
                'password' => 'पासवर्ड',
            ],
        )->validate();

        $user->forceFill([
            'password' => Hash::make((string) $validated['password']),
        ])->save();

        $this->updateRegistrationMeta($item, [
            'password_step_completed_at' => now()->toISOString(),
            'password_step_completed_via' => 'public_web_form',
        ]);
    }

    public function skipPasswordStep(BulkIntakeBatchItem $item): void
    {
        $this->updateRegistrationMeta($item, [
            'password_step_skipped_at' => now()->toISOString(),
            'password_step_skipped_via' => 'public_web_form',
        ]);
    }

    /**
     * @return array{challenge_id: string, email: string, sent_at?: string}|null
     */
    public function otpSessionForToken(string $token): ?array
    {
        $session = session($this->otpSessionKey($token));

        return is_array($session) ? $session : null;
    }

    private function markEmailStepCompleted(BulkIntakeBatchItem $item, string $via): void
    {
        $this->updateRegistrationMeta($item, [
            'email_step_completed_at' => now()->toISOString(),
            'email_step_completed_via' => $via,
        ]);
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function updateRegistrationMeta(BulkIntakeBatchItem $item, array $patch): void
    {
        DB::transaction(function () use ($item, $patch): void {
            $locked = BulkIntakeBatchItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $meta = is_array($locked->item_meta_json) ? $locked->item_meta_json : [];
            $existing = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
            $meta['registration'] = array_merge($existing, $patch);
            $locked->forceFill(['item_meta_json' => $meta])->save();
        });
    }

    private function registrationMetaTimestamp(BulkIntakeBatchItem $item, string $key): ?string
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $registration = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
        $value = trim((string) ($registration[$key] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function otpSessionKey(string $token): string
    {
        return self::SESSION_OTP_PREFIX.sha1($token);
    }

    private function httpExceptionMessage(HttpException $exception, string $fallback): string
    {
        $message = trim($exception->getMessage());

        return $message !== '' ? $message : $fallback;
    }
}
