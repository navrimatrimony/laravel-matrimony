<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminSetting;
use App\Models\Location;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakVerificationRecord;
use App\Models\User;
use App\Services\Location\LocationService;
use App\Services\Messaging\MetaWhatsAppCloudService;
use App\Support\MobileNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SuchakRegistrationService
{
    private const OTP_TTL_SECONDS = 600;

    private const MAX_OTP_ATTEMPTS = 5;

    private const CACHE_KEY_PREFIX = 'suchak_registration_otp:';

    public function __construct(private readonly SuchakActivityLogger $activityLogger)
    {
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{user: User, account: SuchakAccount, delivery: string, otp: string|null}
     */
    public function register(array $attributes, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $whatsapp = $this->normalizeRequiredMobile((string) ($attributes['whatsapp_number'] ?? $attributes['mobile_number'] ?? ''), 'whatsapp_number');
        $mobile = $whatsapp;
        $email = $this->normalizeOptionalEmail($attributes['email'] ?? null);
        $locationColumns = $this->suchakLocationColumns($attributes['location_id'] ?? null);

        [$user, $account] = DB::transaction(function () use ($attributes, $mobile, $whatsapp, $email, $locationColumns, $ipAddress, $userAgent): array {
            $user = User::query()->create([
                'name' => trim((string) $attributes['suchak_name']),
                'email' => $email,
                'mobile' => $mobile,
                'password' => Hash::make((string) $attributes['password']),
                'registering_for' => 'other',
            ]);

            $account = SuchakAccount::query()->create([
                'user_id' => $user->id,
                'suchak_name' => trim((string) $attributes['suchak_name']),
                'office_name' => $this->nullableString($attributes['office_name'] ?? null),
                'business_type' => (string) $attributes['business_type'],
                'employee_count' => isset($attributes['employee_count']) ? (int) $attributes['employee_count'] : null,
                'mobile_number' => $mobile,
                'whatsapp_number' => $whatsapp,
                'email' => $email,
                'address_line' => $this->nullableString($attributes['address_line'] ?? null),
                'city_id' => $locationColumns['city_id'],
                'taluka_id' => $locationColumns['taluka_id'],
                'district_id' => $locationColumns['district_id'],
                'state_id' => $locationColumns['state_id'],
                'verification_status' => SuchakAccount::VERIFICATION_PENDING,
                'public_status' => SuchakAccount::PUBLIC_HIDDEN,
                'registration_completed_at' => now(),
                'onboarding_step' => 'complete',
            ]);

            $this->activityLogger->record([
                'suchak_account_id' => $account->id,
                'actor_user_id' => $user->id,
                'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
                'action_type' => SuchakActivityLog::ACTION_SUCHAK_ONBOARDING_REQUESTED,
                'target_type' => 'suchak_account',
                'target_id' => $account->id,
                'ip_address' => $ipAddress,
                'user_agent' => Str::limit((string) $userAgent, 512, ''),
                'metadata_json' => [
                    'source' => 'public_suchak_registration',
                    'mobile_verification_required' => true,
                    'kyc_document_count' => 0,
                ],
            ]);

            return [$user, $account];
        });

        $otp = $this->issueOtp($user, $mobile);

        return [
            'user' => $user,
            'account' => $account,
            'delivery' => $otp['delivery'],
            'otp' => $otp['otp'],
        ];
    }

    /**
     * Goal 4 staged native start: mobile-first minimal Suchak (cannot operate until complete).
     *
     * @return array{user: User, account: SuchakAccount, delivery: string, otp: string|null}
     */
    public function startMobileRegistration(string $mobile, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $mobile = $this->normalizeRequiredMobile($mobile, 'whatsapp_number');

        if (User::query()->where('mobile', $mobile)->exists()) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('auth.mobile_duplicate_register'),
            ]);
        }

        [$user, $account] = DB::transaction(function () use ($mobile, $ipAddress, $userAgent): array {
            $user = User::query()->create([
                'name' => 'Suchak',
                'email' => null,
                'mobile' => $mobile,
                'password' => Hash::make(Str::random(40)),
                'registering_for' => 'other',
            ]);

            $account = SuchakAccount::query()->create([
                'user_id' => $user->id,
                'suchak_name' => 'Suchak',
                'office_name' => null,
                'business_type' => SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
                'employee_count' => null,
                'mobile_number' => $mobile,
                'whatsapp_number' => $mobile,
                'email' => null,
                'address_line' => null,
                'city_id' => null,
                'taluka_id' => null,
                'district_id' => null,
                'state_id' => null,
                'verification_status' => SuchakAccount::VERIFICATION_PENDING,
                'public_status' => SuchakAccount::PUBLIC_HIDDEN,
                'registration_completed_at' => null,
                'onboarding_step' => 'otp',
            ]);

            $this->activityLogger->record([
                'suchak_account_id' => $account->id,
                'actor_user_id' => $user->id,
                'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
                'action_type' => SuchakActivityLog::ACTION_SUCHAK_ONBOARDING_REQUESTED,
                'target_type' => 'suchak_account',
                'target_id' => $account->id,
                'ip_address' => $ipAddress,
                'user_agent' => Str::limit((string) $userAgent, 512, ''),
                'metadata_json' => [
                    'source' => 'native_suchak_registration_start',
                    'mobile_verification_required' => true,
                    'staged' => true,
                ],
            ]);

            return [$user, $account];
        });

        $otp = $this->issueOtp($user, $mobile);

        return [
            'user' => $user,
            'account' => $account,
            'delivery' => $otp['delivery'],
            'otp' => $otp['otp'],
        ];
    }

    /**
     * @param  array{suchak_name: string, business_type: string, office_name?: string|null, employee_count?: int|null}  $attributes
     */
    public function updateIdentity(SuchakAccount $account, array $attributes): SuchakAccount
    {
        $this->assertRegistrationIncomplete($account);

        $businessType = (string) $attributes['business_type'];
        if (! in_array($businessType, [
            SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
            SuchakAccount::BUSINESS_TYPE_ORGANIZATION,
        ], true)) {
            throw ValidationException::withMessages([
                'business_type' => 'Select individual or organization.',
            ]);
        }

        $suchakName = trim((string) $attributes['suchak_name']);
        if ($suchakName === '') {
            throw ValidationException::withMessages([
                'suchak_name' => 'Name is required.',
            ]);
        }

        $officeName = $this->nullableString($attributes['office_name'] ?? null);
        $employeeCount = $attributes['employee_count'] ?? null;

        if ($businessType === SuchakAccount::BUSINESS_TYPE_ORGANIZATION) {
            if ($officeName === null || $officeName === '') {
                throw ValidationException::withMessages([
                    'office_name' => 'Organization name is required.',
                ]);
            }
            if ($employeeCount === null || (int) $employeeCount < 1) {
                throw ValidationException::withMessages([
                    'employee_count' => 'Enter how many people work in the organization.',
                ]);
            }
        } else {
            $officeName = null;
            $employeeCount = null;
        }

        $account->forceFill([
            'suchak_name' => $suchakName,
            'office_name' => $officeName,
            'business_type' => $businessType,
            'employee_count' => $employeeCount !== null ? (int) $employeeCount : null,
            'onboarding_step' => 'identity',
        ])->save();

        $account->user?->forceFill([
            'name' => $suchakName,
        ])->save();

        return $account->fresh(['user']);
    }

    public function updateLocation(SuchakAccount $account, ?int $locationId, ?string $addressLine): SuchakAccount
    {
        $this->assertRegistrationIncomplete($account);

        if ($locationId === null) {
            throw ValidationException::withMessages([
                'location_id' => __('suchak.register.select_office_location'),
            ]);
        }

        $address = $this->nullableString($addressLine);
        if ($address === null) {
            throw ValidationException::withMessages([
                'address_line' => 'Address is required.',
            ]);
        }

        $locationColumns = $this->suchakLocationColumns($locationId);
        $account->forceFill([
            'address_line' => $address,
            'city_id' => $locationColumns['city_id'],
            'taluka_id' => $locationColumns['taluka_id'],
            'district_id' => $locationColumns['district_id'],
            'state_id' => $locationColumns['state_id'],
            'onboarding_step' => 'location',
        ])->save();

        return $account->fresh();
    }

    public function setPassword(User $user, string $password): void
    {
        $account = $user->suchakAccount;
        if ($account === null) {
            throw ValidationException::withMessages([
                'password' => 'Suchak account is required.',
            ]);
        }
        $this->assertRegistrationIncomplete($account);

        $user->forceFill([
            'password' => Hash::make($password),
        ])->save();
    }

    public function completeRegistration(SuchakAccount $account): SuchakAccount
    {
        $this->assertRegistrationIncomplete($account);

        $user = $account->user;
        if ($user === null || $user->mobile_verified_at === null) {
            throw ValidationException::withMessages([
                'otp' => 'Mobile OTP must be verified before completing registration.',
            ]);
        }

        if (trim((string) $account->suchak_name) === '' || $account->suchak_name === 'Suchak') {
            throw ValidationException::withMessages([
                'suchak_name' => 'Complete your name before finishing registration.',
            ]);
        }

        if ($account->city_id === null && $account->taluka_id === null) {
            throw ValidationException::withMessages([
                'location_id' => 'Complete location before finishing registration.',
            ]);
        }

        $hasProfilePhoto = $account->verificationRecords()
            ->where('verification_type', SuchakVerificationRecord::TYPE_PROFILE_PHOTO)
            ->whereNotNull('document_path')
            ->exists()
            || filled($account->profile_photo_path);

        if (! $hasProfilePhoto) {
            throw ValidationException::withMessages([
                'profile_photo' => 'Upload your photo before finishing registration.',
            ]);
        }

        if ($account->business_type === SuchakAccount::BUSINESS_TYPE_ORGANIZATION) {
            $hasOfficePhoto = $account->verificationRecords()
                ->where('verification_type', SuchakVerificationRecord::TYPE_OFFICE_PHOTO)
                ->whereNotNull('document_path')
                ->exists();
            if (! $hasOfficePhoto) {
                throw ValidationException::withMessages([
                    'office_photo' => 'Upload an office photo before finishing registration.',
                ]);
            }
        }

        if (! filled($user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Set a password before finishing registration.',
            ]);
        }

        $account->forceFill([
            'registration_completed_at' => now(),
            'onboarding_step' => 'complete',
        ])->save();

        return $account->fresh(['user', 'verificationRecords']);
    }

    public function assertRegistrationIncomplete(SuchakAccount $account): void
    {
        if ($account->isRegistrationComplete()) {
            throw ValidationException::withMessages([
                'registration' => 'Registration is already complete.',
            ]);
        }
    }

    /**
     * @return array{delivery: string, otp: string|null}
     */
    public function resendOtp(User $user): array
    {
        $mobile = $this->normalizeRequiredMobile((string) $user->mobile);

        return $this->issueOtp($user, $mobile);
    }

    public function verifyOtp(User $user, string $otp): void
    {
        if (! preg_match('/^[0-9]{6}$/', $otp)) {
            throw ValidationException::withMessages([
                'otp' => 'OTP must be a six digit code.',
            ]);
        }

        $key = $this->otpCacheKey($user);
        $payload = Cache::get($key);

        if (! is_array($payload) || empty($payload['hash'])) {
            throw ValidationException::withMessages([
                'otp' => 'OTP expired. Please request a new OTP.',
            ]);
        }

        $attempts = (int) ($payload['attempts'] ?? 0);
        if ($attempts >= self::MAX_OTP_ATTEMPTS) {
            throw ValidationException::withMessages([
                'otp' => 'OTP attempt limit exceeded. Please request a new OTP.',
            ]);
        }

        if (! Hash::check($otp, (string) $payload['hash'])) {
            $payload['attempts'] = $attempts + 1;
            Cache::put($key, $payload, self::OTP_TTL_SECONDS);

            throw ValidationException::withMessages([
                'otp' => 'Invalid OTP. Please check the code and try again.',
            ]);
        }

        Cache::forget($key);

        $user->forceFill([
            'mobile_verified_at' => now(),
        ])->save();
    }

    public function uploadVerificationDocument(
        SuchakAccount $account,
        UploadedFile $document,
        string $verificationType,
        ?int $actorUserId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakVerificationRecord {
        $record = $this->storeVerificationDocument($account, $document, $verificationType, 'document');

        $this->activityLogger->record([
            'suchak_account_id' => $account->id,
            'actor_user_id' => $actorUserId,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_SUCHAK_ONBOARDING_REQUESTED,
            'target_type' => 'suchak_verification_record',
            'target_id' => $record->id,
            'ip_address' => $ipAddress,
            'user_agent' => Str::limit((string) $userAgent, 512, ''),
            'metadata_json' => [
                'source' => 'post_registration_document_upload',
                'verification_type' => $verificationType,
            ],
        ]);

        return $record;
    }

    /**
     * @return array{delivery: string, otp: string|null}
     */
    private function issueOtp(User $user, string $mobile): array
    {
        $mode = (string) AdminSetting::getValue('mobile_verification_mode', 'dev_show');

        if ($mode === 'off') {
            return [
                'delivery' => 'disabled',
                'otp' => null,
            ];
        }

        $otp = (string) random_int(100000, 999999);
        Cache::put($this->otpCacheKey($user), [
            'hash' => Hash::make($otp),
            'attempts' => 0,
            'mobile' => $mobile,
        ], self::OTP_TTL_SECONDS);

        if ($mode === 'dev_show') {
            return [
                'delivery' => 'dev_show',
                'otp' => $otp,
            ];
        }

        /** @var MetaWhatsAppCloudService $whatsapp */
        $whatsapp = app(MetaWhatsAppCloudService::class);
        if (! $whatsapp->isConfiguredForOtp()) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('otp.whatsapp_not_configured'),
            ]);
        }

        if (! $whatsapp->sendOtp($mobile, $otp)) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('otp.whatsapp_send_failed'),
            ]);
        }

        return [
            'delivery' => 'whatsapp',
            'otp' => null,
        ];
    }

    private function otpCacheKey(User $user): string
    {
        return self::CACHE_KEY_PREFIX.$user->id;
    }

    private function normalizeRequiredMobile(string $value, string $field = 'mobile_number'): string
    {
        $mobile = MobileNumber::normalize($value);

        if ($mobile === null) {
            throw ValidationException::withMessages([
                $field => __('otp.enter_valid_10_digit_mobile'),
            ]);
        }

        return $mobile;
    }

    private function normalizeOptionalEmail(mixed $value): ?string
    {
        $email = trim((string) $value);

        return $email === '' ? null : Str::lower($email);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{city_id: int|null, taluka_id: int|null, district_id: int|null, state_id: int|null}
     */
    private function suchakLocationColumns(mixed $locationId): array
    {
        if ($locationId === null || $locationId === '') {
            return [
                'city_id' => null,
                'taluka_id' => null,
                'district_id' => null,
                'state_id' => null,
            ];
        }

        $leaf = Location::query()->find((int) $locationId);
        if (! $leaf) {
            throw ValidationException::withMessages([
                'location_id' => 'Please select office area from location suggestions.',
            ]);
        }

        /** @var LocationService $locationService */
        $locationService = app(LocationService::class);
        $locationService->ensureAncestorsLoaded($leaf);

        $type = strtolower((string) ($leaf->hierarchy ?? ''));
        $id = static fn (?Location $location): ?int => $location ? (int) $location->id : null;

        return [
            'city_id' => $type === 'village' ? (int) $leaf->id : null,
            'taluka_id' => $id($locationService->getAncestorByType($leaf, 'taluka')),
            'district_id' => $id($locationService->getAncestorByType($leaf, 'district')),
            'state_id' => $id($locationService->getAncestorByType($leaf, 'state')),
        ];
    }

    private function storeVerificationDocument(
        SuchakAccount $account,
        UploadedFile $document,
        string $verificationType,
        string $field,
    ): SuchakVerificationRecord {
        $path = $document->store('suchak/verification-documents/'.$account->id, 'local');

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                $field => 'Unable to store Suchak verification document.',
            ]);
        }

        return SuchakVerificationRecord::query()->updateOrCreate(
            [
                'suchak_account_id' => $account->id,
                'verification_type' => $verificationType,
            ],
            [
                'document_path' => $path,
                'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
                'admin_user_id' => null,
                'remarks' => null,
                'remarks_mr' => null,
                'verified_at' => null,
                'rejected_at' => null,
            ],
        );
    }
}
