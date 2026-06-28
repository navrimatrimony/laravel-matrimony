<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Api\MobileEmailVerificationService;
use App\Services\Api\MobileOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MobileEmailVerificationController extends Controller
{
    public function verifyGoogle(
        Request $request,
        MobileEmailVerificationService $emailVerification,
        MobileOtpService $otpService
    ): JsonResponse {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'id_token' => ['required', 'string', 'max:8192'],
        ]);

        try {
            $user = $emailVerification->verifyGoogleEmail(
                $request->user(),
                (string) $validated['email'],
                (string) $validated['id_token'],
            );
        } catch (HttpException $e) {
            return $this->httpExceptionResponse($e, ['fallback' => 'email_otp']);
        }

        return $this->accountResponse($user, $otpService);
    }

    public function sendOtp(Request $request, MobileEmailVerificationService $emailVerification): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        try {
            $result = $emailVerification->sendOtp($request->user(), (string) $validated['email'], $request);
        } catch (HttpException $e) {
            return $this->httpExceptionResponse($e);
        }

        $response = [
            'success' => true,
            'challenge_id' => $result['challenge_id'],
            'expires_in' => $result['expires_in'],
            'resend_after' => $result['resend_after'],
            'delivery_channel' => 'email',
            'message' => 'OTP sent',
        ];

        if ($result['debug_otp'] !== null) {
            $response['debug_otp'] = $result['debug_otp'];
        }

        return response()->json($response);
    }

    public function verifyOtp(
        Request $request,
        MobileEmailVerificationService $emailVerification,
        MobileOtpService $otpService
    ): JsonResponse {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'max:64'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        try {
            $user = $emailVerification->verifyOtp(
                $request->user(),
                (string) $validated['challenge_id'],
                (string) $validated['email'],
                (string) $validated['otp'],
                $request,
            );
        } catch (HttpException $e) {
            return $this->httpExceptionResponse($e);
        } catch (ValidationException $e) {
            throw $e;
        }

        return $this->accountResponse($user, $otpService);
    }

    private function accountResponse($user, MobileOtpService $otpService): JsonResponse
    {
        return response()->json([
            'success' => true,
            'user' => $otpService->userPayload($user),
            'account_state' => $otpService->accountStateFor($user),
        ]);
    }

    private function httpExceptionResponse(HttpException $e, array $extra = []): JsonResponse
    {
        $payload = array_merge([
            'success' => false,
            'message' => $e->getMessage(),
        ], $extra);

        $retryAfter = $e->getHeaders()['Retry-After'] ?? null;
        if ($retryAfter !== null) {
            $payload['resend_after'] = (int) $retryAfter;
        }

        return response()->json($payload, $e->getStatusCode());
    }
}
