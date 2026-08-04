<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureSuchakLegacyOtpEnabled;
use App\Modules\Suchak\Services\SuchakFirebaseAuthException;
use App\Modules\Suchak\Services\SuchakFirebasePhoneAuthService;
use App\Services\Auth\FirebaseIdTokenException;
use App\Services\Auth\FirebaseIdTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Real phone verification for the Suchak app — registration and login.
 *
 * Both routes carry one thing that matters: a Firebase ID token. Neither reads
 * a "verified" flag from the client, and neither falls back to the demo OTP if
 * verification fails. A silent fallback would BE the bypass, so every failure
 * here is a refusal with a reason.
 */
class SuchakFirebaseAuthApiController extends Controller
{
    /**
     * Tells the app whether this route can be used at all, before it spends a
     * Firebase SMS finding out. Public on purpose — it exposes no secret.
     */
    public function status(FirebaseIdTokenVerifier $verifier): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'available' => $verifier->isConfigured(),
                'legacy_otp_available' => $this->legacyOtpEnabled(),
            ],
        ]);
    }

    public function login(Request $request, SuchakFirebasePhoneAuthService $service): JsonResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $result = $service->login(
                (string) $validated['firebase_id_token'],
                $validated['mobile'] ?? null,
                $request,
            );
        } catch (FirebaseIdTokenException $e) {
            return $this->tokenFailure($e, 'login');
        } catch (SuchakFirebaseAuthException $e) {
            return $this->accountFailure($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Suchak login successful',
            'token' => $result['token'],
            'verification' => $this->verificationPayload($result),
            'user' => $this->userPayload($result),
            'account' => $this->accountPayload($result),
        ]);
    }

    public function register(Request $request, SuchakFirebasePhoneAuthService $service): JsonResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $result = $service->startRegistration(
                (string) $validated['firebase_id_token'],
                $validated['mobile'] ?? null,
                $request,
            );
        } catch (FirebaseIdTokenException $e) {
            return $this->tokenFailure($e, 'register');
        } catch (SuchakFirebaseAuthException $e) {
            return $this->accountFailure($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Mobile verified. Continue onboarding.',
            'token' => $result['token'],
            'verification' => $this->verificationPayload($result),
            'user' => $this->userPayload($result),
            'account' => $this->accountPayload($result),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            // 8 KB ceiling: a Firebase ID token is ~1 KB, and an unbounded
            // string here is a free way to make the server base64-decode
            // megabytes before it can refuse them.
            'firebase_id_token' => ['required', 'string', 'min:20', 'max:8192'],
            // Optional cross-check only. The number that counts is the one
            // inside the signed token; this exists so a mismatch is caught
            // loudly instead of the wrong account being touched quietly.
            'mobile' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', Rule::in(['mr', 'en'])],
            // Consent-first survives the channel change: when the app sends
            // both versions they are recorded exactly as the OTP path did.
            'terms_version' => ['nullable', 'string', 'max:64'],
            'privacy_version' => ['nullable', 'string', 'max:64'],
        ]);
    }

    private function tokenFailure(FirebaseIdTokenException $e, string $entryPoint): JsonResponse
    {
        // Server-side problems are worth an operator's attention; a bad token
        // from a client is not, and logging one would put credentials in a log.
        if ($e->kind === FirebaseIdTokenException::KIND_UNAVAILABLE) {
            Log::warning('suchak.firebase_auth.unavailable', [
                'entry_point' => $entryPoint,
                'code' => $e->errorCode,
            ]);
        }

        return response()->json([
            'success' => false,
            'code' => $e->errorCode,
            'message' => $e->getMessage(),
        ], $e->statusCode());
    }

    private function accountFailure(SuchakFirebaseAuthException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $e->errorCode,
            'message' => $e->getMessage(),
        ], $e->status);
    }

    /**
     * What the server is willing to state about this verification.
     *
     * `channel` and `verified_at` only. No acceptance tier and no `*_match`
     * flag: this proves who signed in, not who agreed to anything.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function verificationPayload(array $result): array
    {
        return [
            'channel' => SuchakFirebasePhoneAuthService::CHANNEL,
            'verified_at' => now()->toISOString(),
            'mobile' => $result['identity']->mobile,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function userPayload(array $result): array
    {
        $user = $result['user'];

        return [
            'id' => $user->id,
            'name' => $user->name,
            'mobile' => $user->mobile,
            'email' => $user->email,
            'mobile_verified_at' => optional($user->mobile_verified_at)?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function accountPayload(array $result): array
    {
        $account = $result['account'];

        return [
            'id' => $account->id,
            'verification_status' => $account->verification_status,
            'registration_completed' => $account->isRegistrationComplete(),
            'onboarding_step' => $account->onboarding_step,
        ];
    }

    private function legacyOtpEnabled(): bool
    {
        return EnsureSuchakLegacyOtpEnabled::enabled();
    }
}
