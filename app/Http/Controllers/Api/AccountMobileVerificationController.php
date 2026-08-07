<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Api\MobileOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Signed-in member changes or adds THEIR OWN mobile number.
 *
 * The number is never written on request: the OTP goes to the number being
 * claimed, and only a correct OTP moves it onto the account. Mirrors
 * {@see MobileEmailVerificationController} so the app can share one widget.
 */
class AccountMobileVerificationController extends Controller
{
    public function sendOtp(Request $request, MobileOtpService $otpService): JsonResponse
    {
        $validated = $request->validate([
            'mobile' => ['required', 'string', 'max:32'],
        ]);

        try {
            $result = $otpService->sendPossessionChallenge(
                $request->user(),
                (string) $validated['mobile'],
                $request,
            );
        } catch (HttpException $e) {
            return $this->httpExceptionResponse($e);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'challenge_id' => (string) $result['challenge']->challenge_id,
                'expires_in' => (int) $result['expires_in'],
                'resend_after' => (int) $result['resend_after'],
                'debug_otp' => $result['debug_otp'],
            ],
        ]);
    }

    public function verifyOtp(Request $request, MobileOtpService $otpService): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'max:64'],
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        try {
            $user = $otpService->verifyPossession(
                $request->user(),
                (string) $validated['challenge_id'],
                (string) $validated['otp'],
                $request,
            );
        } catch (HttpException $e) {
            return $this->httpExceptionResponse($e);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'mobile' => $user->mobile,
                'mobile_verified_at' => optional($user->mobile_verified_at)?->toISOString(),
            ],
        ]);
    }

    private function httpExceptionResponse(HttpException $e): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $e->getMessage(),
        ];

        $retryAfter = $e->getHeaders()['Retry-After'] ?? null;
        if ($retryAfter !== null) {
            $payload['resend_after'] = (int) $retryAfter;
        }

        return response()->json($payload, $e->getStatusCode());
    }
}
