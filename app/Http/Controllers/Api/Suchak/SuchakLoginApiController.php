<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Api\MobileOtpService;
use App\Support\MobileNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Suchak API adapter: login transport for existing Suchak accounts only.
 * Reuses MobileOtpService delivery + Sanctum. Does not create member users.
 * User ≠ Suchak — non-Suchak mobiles/emails are rejected.
 */
class SuchakLoginApiController extends Controller
{
    public function sendOtp(Request $request, MobileOtpService $otpService): JsonResponse
    {
        $validated = $request->validate([
            'mobile' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, \Closure $fail): void {
                if (MobileNumber::normalize((string) $value) === null) {
                    $fail('Enter a valid 10 digit mobile number.');
                }
            }],
            'locale' => ['nullable', 'string', Rule::in(['mr', 'en'])],
            'terms_accepted' => ['accepted'],
            'privacy_accepted' => ['accepted'],
            'terms_version' => ['required', 'string', 'max:64'],
            'privacy_version' => ['required', 'string', 'max:64'],
        ]);

        $mobile = MobileNumber::normalize((string) $validated['mobile']);
        $user = User::query()->where('mobile', $mobile)->first();
        if ($user === null || $user->suchakAccount === null) {
            return response()->json([
                'success' => false,
                'code' => 'suchak_not_found',
                'message' => 'No Suchak account found for this mobile. Register as a new Suchak, or use a Suchak login.',
            ], 404);
        }

        $validated['purpose'] = 'suchak_login';
        $validated['channel'] = 'whatsapp';

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
            'message' => match ($channel) {
                'whatsapp' => 'OTP sent via WhatsApp',
                'dev' => 'TEST OTP issued (development / staging only)',
                default => 'OTP sent',
            },
        ];

        if ($result['debug_otp'] !== null) {
            $response['debug_otp'] = $result['debug_otp'];
        }

        return response()->json($response);
    }

    public function verifyOtp(Request $request, MobileOtpService $otpService): JsonResponse
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

        $mobile = MobileNumber::normalize((string) $validated['mobile']);
        $existing = User::query()->where('mobile', $mobile)->first();
        if ($existing === null || $existing->suchakAccount === null) {
            return response()->json([
                'success' => false,
                'code' => 'suchak_not_found',
                'message' => 'No Suchak account found for this mobile. Register as a new Suchak.',
            ], 404);
        }

        try {
            $result = $otpService->verifyChallenge($validated, $request);
        } catch (HttpException $e) {
            return $this->httpExceptionResponse($e);
        } catch (ValidationException $e) {
            throw $e;
        }

        /** @var User $user */
        $user = $result['user'];
        if ($user->suchakAccount === null || ! empty($result['is_new_account'])) {
            $user->tokens()->delete();

            return response()->json([
                'success' => false,
                'code' => 'suchak_not_found',
                'message' => 'No Suchak account found for this mobile. Register as a new Suchak.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Suchak login successful',
            'token' => $result['token'],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'mobile' => $user->mobile,
                'email' => $user->email,
            ],
        ]);
    }

    public function loginWithPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->resolvePasswordUser(
            trim((string) $validated['login']),
            (string) $validated['password'],
        );

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        if ($user->suchakAccount === null) {
            return response()->json([
                'success' => false,
                'code' => 'suchak_not_found',
                'message' => 'This account is not a Suchak. Open the member app, or register as a new Suchak.',
            ], 403);
        }

        $token = $user->createToken('suchak-app')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Suchak login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'mobile' => $user->mobile,
                'email' => $user->email,
            ],
        ]);
    }

    public function loginWithGoogle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'id_token' => ['required', 'string', 'max:8192'],
        ]);

        try {
            $this->assertGoogleIdToken((string) $validated['email'], (string) $validated['id_token']);
        } catch (HttpException $e) {
            return $this->httpExceptionResponse($e);
        }

        $email = Str::lower(trim((string) $validated['email']));
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($user === null || $user->suchakAccount === null) {
            return response()->json([
                'success' => false,
                'code' => 'suchak_not_found',
                'message' => 'No Suchak account found for this Google email. Register as a new Suchak.',
            ], 404);
        }

        $token = $user->createToken('suchak-app')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Suchak login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'mobile' => $user->mobile,
                'email' => $user->email,
            ],
        ]);
    }

    private function resolvePasswordUser(string $login, string $password): ?User
    {
        if ($login === '') {
            return null;
        }

        $mobileDigits = MobileNumber::normalize($login);
        if ($mobileDigits !== null) {
            $users = User::query()
                ->where('mobile', $mobileDigits)
                ->orderByDesc('mobile_verified_at')
                ->orderByDesc('id')
                ->get();

            foreach ($users as $candidate) {
                if (filled($candidate->password) && Hash::check($password, $candidate->password)) {
                    return $candidate;
                }
            }

            return null;
        }

        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $candidate = User::query()
                ->whereRaw('LOWER(email) = ?', [Str::lower($login)])
                ->first();

            return $candidate && filled($candidate->password) && Hash::check($password, $candidate->password)
                ? $candidate
                : null;
        }

        return null;
    }

    private function assertGoogleIdToken(string $email, string $idToken): void
    {
        $email = Str::lower(trim($email));
        $idToken = trim($idToken);
        if ($idToken === '') {
            throw new HttpException(422, 'Google sign-in token is missing.');
        }

        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->get('https://oauth2.googleapis.com/tokeninfo', [
                    'id_token' => $idToken,
                ]);
        } catch (\Throwable) {
            throw new HttpException(422, 'Google sign-in failed.');
        }

        if (! $response->ok() || ! is_array($response->json())) {
            throw new HttpException(422, 'Google sign-in failed.');
        }

        $claims = $response->json();
        $googleEmail = Str::lower(trim((string) ($claims['email'] ?? '')));
        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL) === true;
        $audience = trim((string) ($claims['aud'] ?? ''));
        $allowed = $this->googleClientIds();

        if ($allowed === []) {
            throw new HttpException(422, 'Google sign-in is not configured.');
        }

        if (! in_array($audience, $allowed, true)) {
            throw new HttpException(422, 'Google sign-in audience is invalid.');
        }

        if ($googleEmail === '' || $googleEmail !== $email || ! $emailVerified) {
            throw new HttpException(422, 'Google sign-in failed.');
        }
    }

    /**
     * @return list<string>
     */
    private function googleClientIds(): array
    {
        $ids = config('services.google.client_ids', []);
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }

        $web = trim((string) config('services.google.web_client_id', ''));
        if ($web !== '') {
            $ids = is_array($ids) ? $ids : [];
            $ids[] = $web;
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            is_array($ids) ? $ids : []
        ))));
    }

    private function httpExceptionResponse(HttpException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], $e->getStatusCode());
    }
}
