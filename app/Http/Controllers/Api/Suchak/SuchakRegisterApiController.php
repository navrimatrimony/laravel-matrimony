<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakVerificationRecord;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakRegistrationService;
use App\Services\Location\CurrentLocationResolverService;
use App\Support\MobileNumber;
use App\Support\Suchak\SuchakOnboardingPresenter;
use App\Support\Validation\AddressHierarchyRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

/**
 * Suchak API adapter: native mobile registration over SuchakRegistrationService.
 * Mirrors web AccountRequestController validation and workflow. No new business rules.
 */
class SuchakRegisterApiController extends Controller
{
    private const PUBLIC_LOCATION_RESOLVE_CACHE_USER_ID = 0;

    public function store(Request $request, SuchakRegistrationService $registrationService): JsonResponse
    {
        if ($request->user()?->suchakAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Suchak account already exists for this session.',
            ], 409);
        }

        if ($request->user()) {
            return response()->json([
                'success' => false,
                'code' => 'member_account_conflict',
                'message' => __('suchak.register.separate_account_body'),
            ], 403);
        }

        $validated = $request->validate([
            'suchak_name' => ['required', 'string', 'max:255'],
            'office_name' => [
                Rule::requiredIf(fn (): bool => in_array((string) $request->input('business_type'), [
                    SuchakAccount::BUSINESS_TYPE_BUREAU,
                    SuchakAccount::BUSINESS_TYPE_ORGANIZATION,
                ], true)),
                'nullable',
                'string',
                'max:255',
            ],
            'business_type' => ['required', 'string', Rule::in([
                SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
                SuchakAccount::BUSINESS_TYPE_BUREAU,
                SuchakAccount::BUSINESS_TYPE_ORGANIZATION,
            ])],
            'whatsapp_number' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'address_line' => ['required', 'string', 'max:1000'],
            'location_id' => ['nullable', 'integer', AddressHierarchyRules::existsLocationLeafId()],
            'location_input' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        if (empty($validated['location_id']) && filled($validated['location_input'] ?? null)) {
            throw ValidationException::withMessages([
                'location_id' => __('suchak.register.select_office_location'),
            ]);
        }

        $whatsapp = MobileNumber::normalize((string) $validated['whatsapp_number']);
        if ($whatsapp === null) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('otp.enter_valid_10_digit_mobile'),
            ]);
        }

        Validator::make(
            ['whatsapp_number' => $whatsapp],
            ['whatsapp_number' => ['required', Rule::unique('users', 'mobile')]],
            ['whatsapp_number.unique' => __('auth.mobile_duplicate_register')]
        )->validate();

        $validated['whatsapp_number'] = $whatsapp;
        $validated['mobile_number'] = $whatsapp;
        $validated['email'] = empty($validated['email']) ? null : Str::lower((string) $validated['email']);

        $result = $registrationService->register(
            $validated,
            $request->ip(),
            $request->userAgent()
        );

        /** @var User $user */
        $user = $result['user'];
        $token = $user->createToken('suchak-app')->plainTextToken;

        $response = [
            'success' => true,
            'message' => 'Suchak registration created. Verify mobile OTP.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'mobile' => $user->mobile,
                'email' => $user->email,
            ],
            'account' => [
                'id' => $result['account']->id,
                'verification_status' => $result['account']->verification_status,
            ],
            'otp' => [
                'delivery' => $result['delivery'],
                'delivery_channel' => $result['delivery'] === 'dev_show' ? 'dev' : $result['delivery'],
            ],
        ];

        if ($result['otp'] !== null) {
            $response['otp']['debug_otp'] = $result['otp'];
        }

        return response()->json($response, 201);
    }

    public function resolveLocation(
        Request $request,
        CurrentLocationResolverService $currentLocationResolverService,
    ): JsonResponse {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $result = $currentLocationResolverService->resolve(
            self::PUBLIC_LOCATION_RESOLVE_CACHE_USER_ID,
            (float) $validated['lat'],
            (float) $validated['lon'],
        );

        $status = ($result['success'] ?? false) ? 200 : 422;
        if (($result['status'] ?? '') === 'busy') {
            $status = 429;
        }

        return response()->json($result, $status);
    }

    public function resendOtp(Request $request, SuchakRegistrationService $registrationService): JsonResponse
    {
        $user = $this->requireSuchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if ($user->mobile_verified_at) {
            return response()->json([
                'success' => true,
                'message' => 'Mobile already verified.',
                'already_verified' => true,
            ]);
        }

        $result = $registrationService->resendOtp($user);
        $response = [
            'success' => true,
            'message' => 'OTP resent.',
            'otp' => [
                'delivery' => $result['delivery'],
                'delivery_channel' => $result['delivery'] === 'dev_show' ? 'dev' : $result['delivery'],
            ],
        ];
        if ($result['otp'] !== null) {
            $response['otp']['debug_otp'] = $result['otp'];
        }

        return response()->json($response);
    }

    public function verifyOtp(Request $request, SuchakRegistrationService $registrationService): JsonResponse
    {
        $validated = $request->validate([
            'otp' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        $user = $this->requireSuchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $registrationService->verifyOtp($user, (string) $validated['otp']);

        return response()->json([
            'success' => true,
            'message' => 'Mobile verified. Continue onboarding.',
        ]);
    }

    public function storePhoto(Request $request, SuchakRegistrationService $registrationService): JsonResponse
    {
        $user = $this->requireSuchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if (! $user->mobile_verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'Verify mobile OTP before uploading photo.',
            ], 422);
        }

        $validated = $request->validate([
            'profile_photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $account = $user->suchakAccount;
        $registrationService->uploadVerificationDocument(
            $account,
            $validated['profile_photo'],
            SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            (int) $user->id,
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Photo uploaded for review.',
        ]);
    }

    public function storeDocument(Request $request, SuchakRegistrationService $registrationService): JsonResponse
    {
        $user = $this->requireSuchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $validated = $request->validate([
            'verification_type' => ['required', 'string', Rule::in([
                SuchakVerificationRecord::TYPE_IDENTITY,
                SuchakVerificationRecord::TYPE_OFFICE,
                SuchakVerificationRecord::TYPE_BUSINESS,
            ])],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $account = $user->suchakAccount;
        $registrationService->uploadVerificationDocument(
            $account,
            $validated['document'],
            (string) $validated['verification_type'],
            (int) $user->id,
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Document uploaded for review.',
        ]);
    }

    public function status(Request $request, SuchakOnboardingPresenter $onboardingPresenter): JsonResponse
    {
        $user = $this->requireSuchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $account = $user->suchakAccount;
        $account->load([
            'verificationRecords' => fn ($query) => $query->latest('id'),
        ]);

        $onboarding = $onboardingPresenter->forAccount($account, $account->verificationRecords);
        // Strip web-only action URLs for mobile clients.
        $onboarding['steps'] = collect($onboarding['steps'] ?? [])
            ->map(function (array $step): array {
                unset($step['action_url']);

                return $step;
            })
            ->values()
            ->all();
        if (is_array($onboarding['current_step'] ?? null)) {
            unset($onboarding['current_step']['action_url']);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'account' => [
                    'id' => $account->id,
                    'suchak_name' => $account->suchak_name,
                    'business_type' => $account->business_type,
                    'verification_status' => $account->verification_status,
                    'public_status' => $account->public_status,
                ],
                'user' => [
                    'id' => $user->id,
                    'mobile' => $user->mobile,
                    'email' => $user->email,
                    'mobile_verified_at' => $user->mobile_verified_at?->toIso8601String(),
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                ],
                'onboarding' => $onboarding,
            ],
        ]);
    }

    private function requireSuchakUser(Request $request): User|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json([
                'success' => false,
                'message' => 'Suchak account is required.',
            ], 403);
        }

        return $user;
    }
}
