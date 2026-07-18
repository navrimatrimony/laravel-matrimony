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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

/**
 * Goal 4 native staged registration adapters over SuchakRegistrationService.
 */
class SuchakRegisterApiController extends Controller
{
    private const PUBLIC_LOCATION_RESOLVE_CACHE_USER_ID = 0;

    /** @deprecated Use staged startMobile + complete. Kept for older APK builds during rollout. */
    public function store(Request $request, SuchakRegistrationService $registrationService): JsonResponse
    {
        if ($request->user()?->suchakAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Suchak account already exists for this session.',
            ], 409);
        }

        $validated = $request->validate([
            'suchak_name' => ['required', 'string', 'max:255'],
            'office_name' => [
                Rule::requiredIf(fn (): bool => (string) $request->input('business_type') === SuchakAccount::BUSINESS_TYPE_ORGANIZATION),
                'nullable',
                'string',
                'max:255',
            ],
            'business_type' => ['required', 'string', Rule::in([
                SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
                SuchakAccount::BUSINESS_TYPE_ORGANIZATION,
            ])],
            'employee_count' => [
                Rule::requiredIf(fn (): bool => (string) $request->input('business_type') === SuchakAccount::BUSINESS_TYPE_ORGANIZATION),
                'nullable',
                'integer',
                'min:1',
                'max:100000',
            ],
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

        if (User::query()->where('mobile', $whatsapp)->exists()) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('auth.mobile_duplicate_register'),
            ]);
        }

        $validated['whatsapp_number'] = $whatsapp;
        $validated['mobile_number'] = $whatsapp;
        $validated['email'] = empty($validated['email']) ? null : Str::lower((string) $validated['email']);

        $result = $registrationService->register(
            $validated,
            $request->ip(),
            $request->userAgent()
        );

        $user = $result['user'];
        $token = $user->createToken('suchak-app')->plainTextToken;

        return $this->otpBootstrapResponse($result, $token, 201);
    }

    public function startMobile(Request $request, SuchakRegistrationService $registrationService): JsonResponse
    {
        $validated = $request->validate([
            'whatsapp_number' => ['required', 'string', 'max:32'],
        ]);

        $result = $registrationService->startMobileRegistration(
            (string) $validated['whatsapp_number'],
            $request->ip(),
            $request->userAgent()
        );

        $token = $result['user']->createToken('suchak-app')->plainTextToken;

        return $this->otpBootstrapResponse($result, $token, 201);
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

        return response()->json([
            'success' => true,
            'message' => 'OTP resent.',
            'otp' => $this->otpPayload($result),
        ]);
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
        $account = $user->suchakAccount;
        $account?->forceFill(['onboarding_step' => 'identity'])->save();

        return response()->json([
            'success' => true,
            'message' => 'Mobile verified. Continue onboarding.',
            'next_step' => 'identity',
        ]);
    }

    public function updateIdentity(Request $request, SuchakRegistrationService $registrationService): JsonResponse
    {
        $user = $this->requireSuchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $validated = $request->validate([
            'suchak_name' => ['required', 'string', 'max:255'],
            'business_type' => ['required', 'string', Rule::in([
                SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
                SuchakAccount::BUSINESS_TYPE_ORGANIZATION,
            ])],
            'office_name' => [
                Rule::requiredIf(fn (): bool => (string) $request->input('business_type') === SuchakAccount::BUSINESS_TYPE_ORGANIZATION),
                'nullable',
                'string',
                'max:255',
            ],
            'employee_count' => [
                Rule::requiredIf(fn (): bool => (string) $request->input('business_type') === SuchakAccount::BUSINESS_TYPE_ORGANIZATION),
                'nullable',
                'integer',
                'min:1',
                'max:100000',
            ],
        ]);

        $account = $registrationService->updateIdentity($user->suchakAccount, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Identity saved.',
            'data' => [
                'business_type' => $account->business_type,
                'next_step' => 'profile_photo',
            ],
        ]);
    }

    public function updateLocation(Request $request, SuchakRegistrationService $registrationService): JsonResponse
    {
        $user = $this->requireSuchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $validated = $request->validate([
            'address_line' => ['required', 'string', 'max:1000'],
            'location_id' => ['required', 'integer', AddressHierarchyRules::existsLocationLeafId()],
        ]);

        $account = $registrationService->updateLocation(
            $user->suchakAccount,
            (int) $validated['location_id'],
            (string) $validated['address_line'],
        );

        return response()->json([
            'success' => true,
            'message' => 'Location saved.',
            'data' => [
                'account_id' => $account->id,
                'next_step' => 'email',
            ],
        ]);
    }

    public function setPassword(Request $request, SuchakRegistrationService $registrationService): JsonResponse
    {
        $user = $this->requireSuchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $registrationService->setPassword($user, (string) $validated['password']);
        $account = $registrationService->completeRegistration($user->fresh()->suchakAccount);

        return response()->json([
            'success' => true,
            'message' => 'Registration complete.',
            'data' => [
                'registration_completed_at' => $account->registration_completed_at?->toIso8601String(),
                'next_step' => 'done',
            ],
        ]);
    }

    public function storePhoto(Request $request, SuchakRegistrationService $registrationService): JsonResponse
    {
        return $this->storeTypedPhoto(
            $request,
            $registrationService,
            SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            'profile_photo',
        );
    }

    public function storeOrganizationLogo(Request $request, SuchakRegistrationService $registrationService): JsonResponse
    {
        $user = $this->requireSuchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if ($user->suchakAccount?->business_type !== SuchakAccount::BUSINESS_TYPE_ORGANIZATION) {
            return response()->json([
                'success' => false,
                'message' => 'Organization logo is only for organization accounts.',
            ], 422);
        }

        return $this->storeTypedPhoto(
            $request,
            $registrationService,
            SuchakVerificationRecord::TYPE_ORGANIZATION_LOGO,
            'organization_logo',
        );
    }

    public function storeOfficePhoto(Request $request, SuchakRegistrationService $registrationService): JsonResponse
    {
        $user = $this->requireSuchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if ($user->suchakAccount?->business_type !== SuchakAccount::BUSINESS_TYPE_ORGANIZATION) {
            return response()->json([
                'success' => false,
                'message' => 'Office photo is only for organization accounts.',
            ], 422);
        }

        return $this->storeTypedPhoto(
            $request,
            $registrationService,
            SuchakVerificationRecord::TYPE_OFFICE_PHOTO,
            'office_photo',
        );
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

        $registrationService->uploadVerificationDocument(
            $user->suchakAccount,
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
                    'employee_count' => $account->employee_count,
                    'verification_status' => $account->verification_status,
                    'public_status' => $account->public_status,
                    'registration_completed' => $account->isRegistrationComplete(),
                    'registration_completed_at' => $account->registration_completed_at?->toIso8601String(),
                    'onboarding_step' => $account->onboarding_step,
                ],
                'user' => [
                    'id' => $user->id,
                    'mobile' => $user->mobile,
                    'email' => $user->email,
                    'mobile_verified_at' => $user->mobile_verified_at?->toIso8601String(),
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                ],
                'onboarding' => $onboarding,
                'next_step' => $this->resolveNextStep($user, $account),
            ],
        ]);
    }

    private function storeTypedPhoto(
        Request $request,
        SuchakRegistrationService $registrationService,
        string $verificationType,
        string $field,
    ): JsonResponse {
        $user = $this->requireSuchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if (! $user->mobile_verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'Verify mobile OTP before uploading photos.',
            ], 422);
        }

        $validated = $request->validate([
            $field => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $registrationService->uploadVerificationDocument(
            $user->suchakAccount,
            $validated[$field],
            $verificationType,
            (int) $user->id,
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Photo uploaded for review.',
            'verification_type' => $verificationType,
        ]);
    }

    /**
     * @param  array{user: User, account: SuchakAccount, delivery: string, otp: string|null}  $result
     */
    private function otpBootstrapResponse(array $result, string $token, int $status): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Suchak registration started. Verify mobile OTP.',
            'token' => $token,
            'user' => [
                'id' => $result['user']->id,
                'name' => $result['user']->name,
                'mobile' => $result['user']->mobile,
                'email' => $result['user']->email,
            ],
            'account' => [
                'id' => $result['account']->id,
                'verification_status' => $result['account']->verification_status,
                'registration_completed' => $result['account']->isRegistrationComplete(),
                'onboarding_step' => $result['account']->onboarding_step,
            ],
            'otp' => $this->otpPayload($result),
        ], $status);
    }

    /**
     * @param  array{delivery: string, otp?: string|null}  $result
     * @return array{delivery: string, delivery_channel: string, debug_otp?: string}
     */
    private function otpPayload(array $result): array
    {
        $payload = [
            'delivery' => $result['delivery'],
            'delivery_channel' => $result['delivery'] === 'dev_show' ? 'dev' : $result['delivery'],
        ];
        if (($result['otp'] ?? null) !== null) {
            $payload['debug_otp'] = $result['otp'];
        }

        return $payload;
    }

    private function resolveNextStep(User $user, SuchakAccount $account): string
    {
        if ($account->isRegistrationComplete()) {
            return 'done';
        }
        if (! $user->mobile_verified_at) {
            return 'otp';
        }
        if (trim((string) $account->suchak_name) === '' || $account->suchak_name === 'Suchak') {
            return 'identity';
        }

        $hasProfilePhoto = $account->verificationRecords
            ->contains(fn ($r) => $r->verification_type === SuchakVerificationRecord::TYPE_PROFILE_PHOTO && filled($r->document_path))
            || filled($account->profile_photo_path);
        if (! $hasProfilePhoto) {
            return 'profile_photo';
        }

        if ($account->business_type === SuchakAccount::BUSINESS_TYPE_ORGANIZATION) {
            $hasOffice = $account->verificationRecords
                ->contains(fn ($r) => $r->verification_type === SuchakVerificationRecord::TYPE_OFFICE_PHOTO && filled($r->document_path));
            if (! $hasOffice) {
                return 'office_photo';
            }
        }

        if ($account->city_id === null && $account->taluka_id === null) {
            return 'location';
        }

        if ($user->email_verified_at === null) {
            return 'email';
        }

        return 'password';
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
