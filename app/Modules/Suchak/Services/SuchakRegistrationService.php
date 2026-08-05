<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminSetting;
use App\Models\Location;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakVerificationDocument;
use App\Models\SuchakVerificationRecord;
use App\Models\User;
use App\Services\Image\ImageModerationService;
use App\Services\Image\ImageOptimizationService;
use App\Services\Location\LocationService;
use App\Services\Messaging\MetaWhatsAppCloudService;
use App\Support\MobileNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SuchakRegistrationService
{
    private const OTP_TTL_SECONDS = 600;

    private const MAX_OTP_ATTEMPTS = 5;

    private const CACHE_KEY_PREFIX = 'suchak_registration_otp:';

    public function __construct(private readonly SuchakActivityLogger $activityLogger) {}

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
     * @param  bool  $mobileAlreadyVerified  Set ONLY by a server-side proof —
     *                                       today that means a Firebase ID token
     *                                       whose signature this server checked
     *                                       (see SuchakFirebasePhoneAuthService).
     *                                       It is never derived from a request
     *                                       field, and no OTP is issued when it
     *                                       is true.
     * @return array{user: User, account: SuchakAccount, delivery: string, otp: string|null}
     */
    public function startMobileRegistration(
        string $mobile,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        bool $mobileAlreadyVerified = false,
    ): array {
        $mobile = $this->normalizeRequiredMobile($mobile, 'whatsapp_number');

        if (User::query()->where('mobile', $mobile)->exists()) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('auth.mobile_duplicate_register'),
            ]);
        }

        [$user, $account] = DB::transaction(function () use ($mobile, $ipAddress, $userAgent, $mobileAlreadyVerified): array {
            $user = User::query()->create([
                'name' => 'Suchak',
                'email' => null,
                'mobile' => $mobile,
                'mobile_verified_at' => $mobileAlreadyVerified ? now() : null,
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
                // A Firebase-verified start has already cleared the OTP step,
                // so it must not land the Suchak back on an OTP screen.
                'onboarding_step' => $mobileAlreadyVerified ? 'identity' : 'otp',
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
                    'source' => $mobileAlreadyVerified
                        ? 'native_suchak_registration_start_firebase'
                        : 'native_suchak_registration_start',
                    'mobile_verification_required' => ! $mobileAlreadyVerified,
                    'mobile_verification_channel' => $mobileAlreadyVerified ? 'firebase' : null,
                    'staged' => true,
                ],
            ]);

            return [$user, $account];
        });

        if ($mobileAlreadyVerified) {
            // No code was sent and none may be requested — the proof already
            // happened. Returning a delivery channel of 'firebase' keeps the
            // response shape identical for older clients while making it
            // impossible for one to sit waiting for an SMS.
            return [
                'user' => $user,
                'account' => $account,
                'delivery' => SuchakFirebasePhoneAuthService::CHANNEL,
                'otp' => null,
            ];
        }

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
        if (! in_array($businessType, SuchakAccount::BUSINESS_TYPES, true)) {
            throw ValidationException::withMessages([
                'business_type' => 'Select individual or marriage bureau.',
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

        if ($businessType === SuchakAccount::BUSINESS_TYPE_BUREAU) {
            if ($officeName === null || $officeName === '') {
                throw ValidationException::withMessages([
                    'office_name' => 'Bureau name is required.',
                ]);
            }
            // Assume he works alone unless he says otherwise. Most Suchaks do,
            // and the alternative was refusing the registration of a one-man
            // मंडळ over a number that tells the product nothing.
            $employeeCount = $employeeCount === null || (int) $employeeCount < 1 ? 1 : $employeeCount;
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

        $locationColumns = $this->suchakLocationColumns($locationId);
        $account->forceFill([
            'address_line' => $this->nullableString($addressLine),
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

        // Office photo is optional for bureau accounts (native APK can skip).

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
        string $field = 'document',
    ): SuchakVerificationRecord {
        $record = $this->storeVerificationDocument($account, $document, $verificationType, $field);

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
            // U5: AdminSetting `dev_show` alone must never emit plaintext OTP in production
            // (mirror MobileOtpService environment awareness via isProduction()).
            return [
                'delivery' => 'dev_show',
                'otp' => app()->isProduction() ? null : $otp,
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

    /**
     * Removes one file from a verification, and the stored file with it.
     *
     * The record survives even when its last file goes: the requirement still
     * exists, it is simply unmet again, and dropping the row would take the
     * admin's remarks with it.
     */
    public function deleteVerificationDocument(SuchakVerificationDocument $document): void
    {
        $record = $document->record;
        $path = (string) $document->document_path;

        DB::transaction(function () use ($document, $record): void {
            $document->delete();

            if (! $record) {
                return;
            }

            $remaining = $record->documents()->orderByDesc('id')->first();

            $record->forceFill([
                // Keep the single-path column pointing at something that still
                // exists, or every older reader would follow it to a deleted
                // file.
                'document_path' => $remaining?->document_path,
                'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
                'admin_user_id' => null,
                'verified_at' => null,
                'rejected_at' => null,
            ])->save();
        });

        // Outside the transaction: losing the blob after the rows are gone is
        // an orphaned file, which is harmless. Losing the rows after the blob
        // is a record pointing at nothing, which is not.
        if ($path !== '' && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    private function storeVerificationDocument(
        SuchakAccount $account,
        UploadedFile $document,
        string $verificationType,
        string $field,
    ): SuchakVerificationRecord {
        $photoTypes = [
            SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            SuchakVerificationRecord::TYPE_OFFICE_PHOTO,
            SuchakVerificationRecord::TYPE_ORGANIZATION_LOGO,
        ];

        if (in_array($verificationType, $photoTypes, true)) {
            return $this->storeModeratedOptimizedPhoto(
                $account,
                $document,
                $verificationType,
                $field,
            );
        }

        $path = $document->store('suchak/verification-documents/'.$account->id, 'local');

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                $field => 'Unable to store Suchak verification document.',
            ]);
        }

        $record = SuchakVerificationRecord::query()->updateOrCreate(
            [
                'suchak_account_id' => $account->id,
                'verification_type' => $verificationType,
            ],
            [
                // Kept pointing at the newest file. Everything that reads a
                // verification through this single column — the admin list, the
                // "uploaded" flag in the onboarding status — keeps working
                // without knowing the record now holds several.
                'document_path' => $path,
                // Any new file reopens the decision. Approving page one and
                // then receiving page two must not leave the record approved
                // on evidence nobody has looked at.
                'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
                'admin_user_id' => null,
                'remarks' => null,
                'remarks_mr' => null,
                'verified_at' => null,
                'rejected_at' => null,
            ],
        );

        // Appended, never replaced. A Suchak sending the back of an Aadhaar
        // after the front used to erase the front, and nothing said so.
        $record->documents()->create([
            'document_path' => $path,
            'original_name' => Str::limit((string) $document->getClientOriginalName(), 255, ''),
            'mime_type' => $document->getClientMimeType(),
            'size_bytes' => $document->getSize() ?: null,
        ]);

        return $record->refresh();
    }

    /**
     * Suchak onboarding photos: NudeNet + WebP optimize, then route by AI outcome:
     * safe → auto-approve, review/error → admin "Needs review", unsafe → store rejected + 422.
     */
    private function storeModeratedOptimizedPhoto(
        SuchakAccount $account,
        UploadedFile $document,
        string $verificationType,
        string $field,
    ): SuchakVerificationRecord {
        $sourcePath = $document->getRealPath();
        if (! is_string($sourcePath) || $sourcePath === '' || ! is_file($sourcePath)) {
            throw ValidationException::withMessages([
                $field => 'Unable to read uploaded photo.',
            ]);
        }

        $moderation = app(ImageModerationService::class)->moderateProfilePhoto($sourcePath);
        $aiStatus = (string) ($moderation['status'] ?? '');
        $aiReason = (string) ($moderation['reason'] ?? '');

        $moderationDecision = match ($aiStatus) {
            'approved' => SuchakVerificationRecord::MODERATION_SAFE,
            'rejected' => SuchakVerificationRecord::MODERATION_REJECTED,
            'error' => SuchakVerificationRecord::MODERATION_ERROR,
            default => SuchakVerificationRecord::MODERATION_REVIEW,
        };

        try {
            $encoded = app(ImageOptimizationService::class)->encodeCoverWebp($sourcePath);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => 'Unable to process photo. Please try another image.',
            ]);
        }

        $relative = 'suchak/verification-documents/'.$account->id.'/'.(string) Str::uuid().'.webp';
        if (! Storage::disk('local')->put($relative, $encoded['bytes'])) {
            throw ValidationException::withMessages([
                $field => 'Unable to store Suchak verification document.',
            ]);
        }

        $adminStatus = match ($moderationDecision) {
            SuchakVerificationRecord::MODERATION_SAFE => SuchakVerificationRecord::STATUS_APPROVED,
            SuchakVerificationRecord::MODERATION_REJECTED => SuchakVerificationRecord::STATUS_REJECTED,
            default => SuchakVerificationRecord::STATUS_PENDING,
        };

        $remarks = match ($moderationDecision) {
            SuchakVerificationRecord::MODERATION_SAFE => 'Auto-approved by AI safety check (safe).',
            SuchakVerificationRecord::MODERATION_REJECTED => $aiReason !== ''
                ? $aiReason
                : 'Rejected by automated moderation (unsafe).',
            SuchakVerificationRecord::MODERATION_ERROR => 'AI safety check unavailable — needs human review.',
            default => $aiReason !== '' ? $aiReason : 'AI flagged for human review.',
        };

        $fileMeta = [
            'bytes' => strlen($encoded['bytes']),
            'width' => ImageOptimizationService::TARGET_WIDTH,
            'height' => ImageOptimizationService::TARGET_HEIGHT,
            'format' => 'webp',
        ];

        $record = SuchakVerificationRecord::query()->updateOrCreate(
            [
                'suchak_account_id' => $account->id,
                'verification_type' => $verificationType,
            ],
            [
                'document_path' => $relative,
                'file_meta' => $fileMeta,
                'admin_status' => $adminStatus,
                'moderation_decision' => $moderationDecision,
                'admin_user_id' => null,
                'remarks' => $remarks,
                'remarks_mr' => null,
                'verified_at' => $adminStatus === SuchakVerificationRecord::STATUS_APPROVED ? now() : null,
                'rejected_at' => $adminStatus === SuchakVerificationRecord::STATUS_REJECTED ? now() : null,
            ],
        );

        if ($adminStatus === SuchakVerificationRecord::STATUS_APPROVED) {
            app(SuchakAccountLifecycleService::class)
                ->publishApprovedProfilePhotoFromRecord($record->fresh());
        }

        if ($moderationDecision === SuchakVerificationRecord::MODERATION_REJECTED) {
            throw ValidationException::withMessages([
                $field => $remarks,
            ]);
        }

        return $record;
    }
}
