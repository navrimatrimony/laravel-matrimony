<?php

namespace App\Services\Intake;

use App\Jobs\ProcessProfilePhoto;
use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use App\Models\IntakeSourceContext;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Admin\AdminSettingService;
use App\Services\DuplicateDetectionService;
use App\Services\DuplicateResult;
use App\Services\Image\ImageProcessingService;
use App\Services\MutationService;
use App\Support\MobileNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase F — promote bulk public registration data into governed matrimony_profiles.
 *
 * Form submit creates/links the profile; photo and preferences update the same profile.
 */
class BulkIntakeRegistrationProfileApplyService
{
    public function __construct(
        private readonly BulkIntakeCandidateDisplayService $candidateDisplayService,
        private readonly IntakeSourceContextRecorder $sourceContextRecorder,
        private readonly MutationService $mutationService,
        private readonly ImageProcessingService $imageProcessingService,
        private readonly IntakePhotoCandidateCropService $photoCandidateCropService,
    ) {}

    public function profileForItem(BulkIntakeBatchItem $item): ?MatrimonyProfile
    {
        $intake = $item->biodataIntake;
        if (! $intake instanceof BiodataIntake) {
            return null;
        }

        if ($intake->matrimony_profile_id) {
            return MatrimonyProfile::query()->find((int) $intake->matrimony_profile_id);
        }

        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $registration = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
        $profileId = isset($registration['matrimony_profile_id']) ? (int) $registration['matrimony_profile_id'] : 0;

        return $profileId > 0 ? MatrimonyProfile::query()->find($profileId) : null;
    }

    /**
     * @return array{profile: MatrimonyProfile, user: User}
     */
    public function applyFormRegistration(
        BulkIntakeBatchItem $item,
        BiodataIntake $intake,
        array $snapshot,
        string $submittedMobile,
    ): array {
        $this->assertConsentMobileMatches($item, $submittedMobile);

        return DB::transaction(function () use ($item, $intake, $snapshot, $submittedMobile): array {
            $lockedItem = BulkIntakeBatchItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $lockedIntake = BiodataIntake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
            $lockedItem->setRelation('biodataIntake', $lockedIntake);

            $user = $this->resolveOwnerUser($lockedIntake, $lockedItem, $snapshot, $submittedMobile);
            $profile = $this->ensureLinkedProfile($lockedIntake, $lockedItem, $user, $snapshot);

            $duplicate = app(DuplicateDetectionService::class)->detectFromSnapshot($snapshot, (int) $user->id);
            if ($duplicate->isDuplicate
                && $duplicate->existingProfileId !== null
                && (int) $duplicate->existingProfileId !== (int) $profile->id
                && $duplicate->duplicateType !== DuplicateResult::TYPE_SAME_USER
            ) {
                throw ValidationException::withMessages([
                    'mobile' => 'या मोबाईल क्रमांकावर आधीच प्रोफाइल नोंदणी झाली आहे.',
                ]);
            }

            $this->mutationService->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');

            $this->persistProfileLink($lockedIntake, $lockedItem, $profile);

            return [
                'profile' => $profile->refresh(),
                'user' => $user->refresh(),
            ];
        });
    }

    public function applyRegistrationPhoto(BiodataIntake $intake, BulkIntakeBatchItem $item): void
    {
        $profile = $this->profileForItem($item);
        if (! $profile instanceof MatrimonyProfile) {
            throw ValidationException::withMessages([
                'profile_photo' => 'Registration profile is missing. Please complete the form first.',
            ]);
        }

        if (! $this->photoCandidateCropService->exists($intake)) {
            return;
        }

        $this->imageProcessingService->enqueueExistingProfilePhotoPath(
            $this->photoCandidateCropService->absolutePath($intake),
            (int) $profile->id,
            'bulk_registration',
            ProcessProfilePhoto::PRIMARY_MODE_INTAKE_CROP_PRIMARY_IF_NONE,
        );
    }

    /**
     * @param  array<string, mixed>  $preferencesSnapshot
     */
    public function applyRegistrationPreferences(
        BulkIntakeBatchItem $item,
        array $preferencesSnapshot,
        int $actorUserId,
    ): MatrimonyProfile {
        $profile = $this->profileForItem($item);
        if (! $profile instanceof MatrimonyProfile) {
            throw ValidationException::withMessages([
                'registration' => 'Registration profile is missing. Please complete the form first.',
            ]);
        }

        $this->mutationService->applyManualSnapshot($profile, $preferencesSnapshot, $actorUserId, 'manual');

        return $profile->refresh();
    }

    private function assertConsentMobileMatches(BulkIntakeBatchItem $item, string $submittedMobile): void
    {
        $consentMobile = $this->consentMobileForItem($item);
        $formMobile = $this->normalizeSubmittedMobile($submittedMobile);

        if ($consentMobile === null || $formMobile === null || $consentMobile !== $formMobile) {
            throw ValidationException::withMessages([
                'mobile' => 'मोबाईल क्रमांक WhatsApp परवानगीच्या नंबरशी जुळला पाहिजे.',
            ]);
        }
    }

    private function consentMobileForItem(BulkIntakeBatchItem $item): ?string
    {
        $active = app(BulkIntakeCandidateContactPlanService::class)->activeMobile($item);
        if ($active !== null) {
            return $active;
        }

        $candidate = $this->candidateDisplayService->candidateForItem($item);
        $collector = app(BulkIntakeCandidateMobileCollector::class);
        $fromDisplay = $collector->parseInput((string) ($candidate['mobile'] ?? ''));

        return $fromDisplay[0] ?? MobileNumber::normalize((string) ($candidate['mobile'] ?? ''));
    }

    private function normalizeSubmittedMobile(string $submittedMobile): ?string
    {
        $direct = MobileNumber::normalize($submittedMobile);
        if ($direct !== null) {
            return $direct;
        }

        $collector = app(BulkIntakeCandidateMobileCollector::class);
        $parsed = $collector->parseInput($submittedMobile);

        return $parsed[0] ?? null;
    }

    private function resolveOwnerUser(
        BiodataIntake $intake,
        BulkIntakeBatchItem $item,
        array $snapshot,
        string $submittedMobile,
    ): User {
        $normalizedMobile = MobileNumber::normalize($submittedMobile);
        if ($normalizedMobile === null) {
            throw ValidationException::withMessages([
                'mobile' => __('otp.enter_valid_10_digit_mobile'),
            ]);
        }

        if ($intake->uploaded_by !== null) {
            $owner = User::query()->find((int) $intake->uploaded_by);
            if ($owner instanceof User && ! $owner->isAnyAdmin()) {
                if (MobileNumber::normalize((string) $owner->mobile) !== $normalizedMobile) {
                    throw ValidationException::withMessages([
                        'mobile' => 'मोबाईल क्रमांक नोंदणी खात्याशी जुळला पाहिजे.',
                    ]);
                }

                return $owner;
            }
        }

        $existingUser = User::query()->where('mobile', $normalizedMobile)->first();
        if ($existingUser instanceof User) {
            if ($existingUser->isAnyAdmin()) {
                throw ValidationException::withMessages([
                    'mobile' => 'या मोबाईल क्रमांकावर नोंदणी करता येत नाही.',
                ]);
            }

            if ($intake->uploaded_by === null) {
                $intake->forceFill(['uploaded_by' => (int) $existingUser->id])->save();
                $this->recordOwnerAssignment($intake, $item, (int) $existingUser->id, 'existing_user_assigned');
            }

            return $existingUser;
        }

        $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
        $name = trim((string) ($core['full_name'] ?? ''));
        if ($name === '') {
            $name = 'Member';
        }

        $member = User::create([
            'name' => $name,
            'email' => null,
            'mobile' => $normalizedMobile,
            'password' => Hash::make(Str::random(40)),
            'registering_for' => 'self',
            'referral_code' => User::generateUniqueReferralCode(),
        ]);

        $intake->forceFill(['uploaded_by' => (int) $member->id])->save();
        $this->recordOwnerAssignment($intake, $item, (int) $member->id, 'owner_user_created_and_assigned');

        return $member;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function ensureLinkedProfile(
        BiodataIntake $intake,
        BulkIntakeBatchItem $item,
        User $user,
        array $snapshot,
    ): MatrimonyProfile {
        $existingForUser = MatrimonyProfile::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if ($intake->matrimony_profile_id !== null) {
            $linked = MatrimonyProfile::query()->find((int) $intake->matrimony_profile_id);
            if ($linked instanceof MatrimonyProfile) {
                if ($existingForUser instanceof MatrimonyProfile && (int) $existingForUser->id !== (int) $linked->id) {
                    throw ValidationException::withMessages([
                        'registration' => 'या खात्यावर आधीच दुसरी प्रोफाइल अस्तित्वात आहे.',
                    ]);
                }

                return $linked;
            }
        }

        if ($existingForUser instanceof MatrimonyProfile) {
            $this->persistProfileLink($intake, $item, $existingForUser);

            return $existingForUser;
        }

        $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
        $profile = $this->mutationService->createDraftProfileForUser($user, [
            'full_name' => trim((string) ($core['full_name'] ?? '')) ?: $user->defaultBootstrapProfileFullName(),
            'gender_id' => isset($core['gender_id']) ? (int) $core['gender_id'] : null,
            'is_suspended' => AdminSettingService::isManualProfileActivationRequired(),
        ]);

        $this->persistProfileLink($intake, $item, $profile);

        return $profile;
    }

    private function persistProfileLink(BiodataIntake $intake, BulkIntakeBatchItem $item, MatrimonyProfile $profile): void
    {
        if ($intake->matrimony_profile_id !== null && (int) $intake->matrimony_profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'registration' => 'या बायोडाटासाठी दुसरी प्रोफाइल आधीच जोडलेली आहे.',
            ]);
        }

        $intake->forceFill([
            'matrimony_profile_id' => (int) $profile->id,
        ])->save();

        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $registration = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
        $meta['registration'] = array_merge($registration, [
            'matrimony_profile_id' => (int) $profile->id,
            'profile_applied_at' => now()->toISOString(),
            'profile_applied_via' => 'public_web_form',
        ]);

        $item->forceFill([
            'item_meta_json' => $meta,
            'item_status' => BulkIntakeBatchItem::STATUS_PROFILE_DRAFT_CREATED,
        ])->save();
    }

    private function recordOwnerAssignment(BiodataIntake $intake, BulkIntakeBatchItem $item, int $userId, string $action): void
    {
        $this->sourceContextRecorder->recordForIntake($intake, [
            'source_type' => IntakeSourceContext::SOURCE_USER_APP,
            'source_surface' => IntakeSourceContext::SURFACE_WEBSITE,
            'actor_type' => IntakeSourceContext::ACTOR_PROFILE_USER,
            'actor_user_id' => $userId,
            'bulk_intake_batch_id' => $item->bulk_intake_batch_id,
            'bulk_intake_batch_item_id' => $item->id,
            'idempotency_key' => 'bulk-public-registration-owner:'.$item->id.':'.$userId,
            'source_meta_json' => [
                'action' => $action,
                'new_uploaded_by' => $userId,
                'consent_confirmed' => true,
                'assigned_at' => now()->toISOString(),
            ],
        ]);
    }
}
