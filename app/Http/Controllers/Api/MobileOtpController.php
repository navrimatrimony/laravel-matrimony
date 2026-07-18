<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Api\MobileOtpService;
use App\Support\MobileNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MobileOtpController extends Controller
{
    public function send(Request $request, MobileOtpService $otpService): JsonResponse
    {
        $validated = $request->validate([
            'mobile' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, \Closure $fail): void {
                if (MobileNumber::normalize((string) $value) === null) {
                    $fail('Enter a valid 10 digit mobile number.');
                }
            }],
            'locale' => ['nullable', 'string', Rule::in(['mr', 'en'])],
            'channel' => ['nullable', 'string', Rule::in(['sms', 'whatsapp'])],
            'purpose' => ['nullable', 'string', Rule::in(['login_or_register'])],
            'terms_accepted' => ['accepted'],
            'privacy_accepted' => ['accepted'],
            'terms_version' => ['required', 'string', 'max:64'],
            'privacy_version' => ['required', 'string', 'max:64'],
            'whatsapp_alerts_opt_in' => ['nullable', 'boolean'],
        ]);

        $validated['purpose'] = $validated['purpose'] ?? 'login_or_register';

        try {
            $result = $otpService->sendChallenge($validated, $request);
        } catch (HttpException $e) {
            return $this->httpExceptionResponse($e);
        }

        $challenge = $result['challenge'];
        $channel = (string) ($result['delivery_channel'] ?? $challenge->channel ?? 'whatsapp');
        $response = [
            'success' => true,
            'challenge_id' => $challenge->challenge_id,
            'expires_in' => $result['expires_in'],
            'resend_after' => $result['resend_after'],
            'delivery_channel' => $channel,
            'message' => $channel === 'whatsapp'
                ? 'OTP sent via WhatsApp'
                : 'OTP sent',
        ];

        if ($result['debug_otp'] !== null) {
            $response['debug_otp'] = $result['debug_otp'];
        }

        return response()->json($response);
    }

    public function verify(Request $request, MobileOtpService $otpService): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'max:64'],
            'mobile' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, \Closure $fail): void {
                if (MobileNumber::normalize((string) $value) === null) {
                    $fail('Enter a valid 10 digit mobile number.');
                }
            }],
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        try {
            $result = $otpService->verifyChallenge($validated, $request);
        } catch (HttpException $e) {
            return $this->httpExceptionResponse($e);
        } catch (ValidationException $e) {
            throw $e;
        }

        $user = $result['user'];

        return response()->json([
            'success' => true,
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'user' => $otpService->userPayload($user),
            'account_state' => $otpService->accountStateFor($user, (bool) $result['is_new_account']),
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
