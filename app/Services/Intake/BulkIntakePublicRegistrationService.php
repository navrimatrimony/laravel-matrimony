<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BulkIntakePublicRegistrationService
{
    private const TOKEN_BYTES = 32;

    public function __construct(
        private readonly BulkIntakeCandidateDisplayService $candidateDisplayService,
        private readonly BulkIntakeWhatsAppConsentService $whatsappConsentService,
        private readonly IntakeHumanReviewSnapshotService $reviewSnapshotService,
        private readonly BulkIntakeRegistrationFormBridgeService $formBridge,
        private readonly BulkIntakeRegistrationPreferencesBridgeService $preferencesBridge,
        private readonly BulkIntakeRegistrationProfileApplyService $profileApplyService,
        private readonly IntakePhotoCandidateCropService $photoCandidateCropService,
    ) {}

    public function publicUrl(BulkIntakeBatchItem $item): string
    {
        return route('bulk-intake.register.show', [
            'token' => $this->ensureToken($item),
        ]);
    }

    public function ensureToken(BulkIntakeBatchItem $item): string
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $registration = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
        $token = trim((string) ($registration['public_token'] ?? ''));
        if ($token !== '') {
            return $token;
        }

        $token = Str::random(self::TOKEN_BYTES);
        $registration['public_token'] = $token;
        $registration['public_token_created_at'] = now()->toISOString();
        $meta['registration'] = $registration;
        $item->forceFill(['item_meta_json' => $meta])->save();

        return $token;
    }

    public function itemForToken(string $token): ?BulkIntakeBatchItem
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        return BulkIntakeBatchItem::query()
            ->where('item_meta_json->registration->public_token', $token)
            ->first();
    }

    /**
     * @return array{allowed: bool, reason: string|null}
     */
    public function accessGate(BulkIntakeBatchItem $item): array
    {
        if ($this->whatsappConsentService->consentStatus($item) !== BulkIntakeWhatsAppConsentService::STATUS_CONSENT_RECEIVED) {
            return ['allowed' => false, 'reason' => 'consent_not_received'];
        }

        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            return ['allowed' => false, 'reason' => 'missing_intake'];
        }

        if ($this->snapshotLocked($intake)) {
            return ['allowed' => false, 'reason' => 'snapshot_locked'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * @return array<string, mixed>
     */
    public function formPayload(BulkIntakeBatchItem $item): array
    {
        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            throw ValidationException::withMessages([
                'registration' => 'Linked biodata intake is missing.',
            ]);
        }

        $snapshot = $this->sourceSnapshot($intake);
        $snapshot = $this->formBridge->mergeCandidatePreviewIntoSnapshot($snapshot, $intake);
        $snapshot = $this->formBridge->prepareDisplaySnapshot($snapshot, $intake);
        $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
        $candidate = $this->candidateDisplayService->candidateForItem($item);
        $motherTongueId = $this->formBridge->resolveMotherTongueId($intake, $core);
        if ($motherTongueId !== null) {
            $core['mother_tongue_id'] = $motherTongueId;
            $snapshot['core'] = $core;
        }

        $mobile = $candidate['mobile'] ?? $core['primary_contact_number'] ?? null;

        return array_merge(
            $this->formBridge->viewContext(
                $item,
                $snapshot,
                is_string($candidate['full_name'] ?? null) ? $candidate['full_name'] : null,
                is_string($mobile) ? $mobile : null,
            ),
            [
                'item' => $item,
                'intake' => $intake,
                'mother_tongue_id' => $motherTongueId,
            ],
        );
    }

    public function save(BulkIntakeBatchItem $item, Request $request): BiodataIntake
    {
        $gate = $this->accessGate($item);
        if (! $gate['allowed']) {
            throw ValidationException::withMessages([
                'registration' => $this->accessDeniedMessage((string) $gate['reason']),
            ]);
        }

        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            throw ValidationException::withMessages([
                'registration' => 'Linked biodata intake is missing.',
            ]);
        }

        $snapshot = $this->formBridge->buildSnapshotFromRequest($request, $item, $intake);

        $intake = $this->reviewSnapshotService->saveReviewedSnapshot($intake, $snapshot, [
            'reviewed_by_user_id' => null,
            'review_actor_type' => IntakeHumanReviewSnapshotService::ACTOR_PROFILE_USER,
            'review_surface' => IntakeHumanReviewSnapshotService::SURFACE_WEBSITE,
            'approval_policy' => IntakeHumanReviewSnapshotService::POLICY_PHASE2C_PROFILE_USER_REVIEW_V1,
            'approval_status' => IntakeHumanReviewSnapshotService::STATUS_REVIEWED,
        ]);

        $mobile = trim((string) $request->input('mobile', ''));
        $this->profileApplyService->applyFormRegistration($item, $intake, $snapshot, $mobile);

        $this->markRegistrationComplete($item);

        return $intake->refresh();
    }

    public function isRegistrationFormComplete(BulkIntakeBatchItem $item): bool
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $registration = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];

        return ($registration['status'] ?? null) === BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE;
    }

    public function isPhotoComplete(BulkIntakeBatchItem $item): bool
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $registration = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];

        return trim((string) ($registration['photo_completed_at'] ?? '')) !== '';
    }

    public function isPreferencesComplete(BulkIntakeBatchItem $item): bool
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $registration = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];

        return trim((string) ($registration['preferences_completed_at'] ?? '')) !== '';
    }

    public function nextStepRouteName(BulkIntakeBatchItem $item): string
    {
        if (! $this->isRegistrationFormComplete($item)) {
            return 'bulk-intake.register.show';
        }
        if (! $this->isPhotoComplete($item)) {
            return 'bulk-intake.register.complete';
        }
        if (! $this->isPreferencesComplete($item)) {
            return 'bulk-intake.register.preferences';
        }

        return 'bulk-intake.register.done';
    }

    /**
     * @return array{allowed: bool, reason: string|null}
     */
    public function postFormAccessGate(BulkIntakeBatchItem $item): array
    {
        $gate = $this->accessGate($item);
        if (! $gate['allowed']) {
            return $gate;
        }

        if (! $this->isRegistrationFormComplete($item)) {
            return ['allowed' => false, 'reason' => 'registration_incomplete'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * @return array<string, mixed>
     */
    public function completePayload(BulkIntakeBatchItem $item, string $token): array
    {
        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            throw ValidationException::withMessages([
                'registration' => 'Linked biodata intake is missing.',
            ]);
        }

        $candidate = $this->candidateDisplayService->candidateForItem($item);
        $snapshot = $this->sourceSnapshot($intake);
        $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];

        return [
            'item' => $item,
            'intake' => $intake,
            'token' => $token,
            'candidate_name' => is_string($candidate['full_name'] ?? null) ? $candidate['full_name'] : ($core['full_name'] ?? null),
            'photo_exists' => $this->photoCandidateCropService->exists($intake),
            'photo_preview_url' => $this->photoCandidateCropService->exists($intake)
                ? route('bulk-intake.register.photo.candidate', ['token' => $token])
                : null,
        ];
    }

    public function savePhoto(BulkIntakeBatchItem $item, UploadedFile $file): BiodataIntake
    {
        $gate = $this->postFormAccessGate($item);
        if (! $gate['allowed']) {
            throw ValidationException::withMessages([
                'profile_photo' => $this->accessDeniedMessage((string) $gate['reason']),
            ]);
        }

        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            throw ValidationException::withMessages([
                'profile_photo' => 'Linked biodata intake is missing.',
            ]);
        }

        try {
            $this->photoCandidateCropService->saveFromRegistrationUpload($intake, $file);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'profile_photo' => 'Invalid photo upload. Please choose a clear JPG or PNG image.',
            ]);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages([
                'profile_photo' => 'Photo could not be saved. Please try again.',
            ]);
        }

        $this->profileApplyService->applyRegistrationPhoto($intake, $item);

        $this->markPhotoComplete($item);

        return $intake->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function preferencesPayload(BulkIntakeBatchItem $item): array
    {
        $gate = $this->postFormAccessGate($item);
        if (! $gate['allowed']) {
            throw ValidationException::withMessages([
                'registration' => $this->accessDeniedMessage((string) $gate['reason']),
            ]);
        }

        if (! $this->isPhotoComplete($item)) {
            throw ValidationException::withMessages([
                'registration' => 'Please upload a photo before setting partner preferences.',
            ]);
        }

        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            throw ValidationException::withMessages([
                'registration' => 'Linked biodata intake is missing.',
            ]);
        }

        $snapshot = $this->sourceSnapshot($intake);
        $candidate = $this->candidateDisplayService->candidateForItem($item);
        $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];

        return array_merge(
            $this->preferencesBridge->viewContext($item, $snapshot),
            [
                'item' => $item,
                'intake' => $intake,
                'candidate_name' => is_string($candidate['full_name'] ?? null) ? $candidate['full_name'] : ($core['full_name'] ?? null),
            ],
        );
    }

    public function savePreferences(BulkIntakeBatchItem $item, Request $request): BiodataIntake
    {
        $gate = $this->postFormAccessGate($item);
        if (! $gate['allowed']) {
            throw ValidationException::withMessages([
                'registration' => $this->accessDeniedMessage((string) $gate['reason']),
            ]);
        }

        if (! $this->isPhotoComplete($item)) {
            throw ValidationException::withMessages([
                'registration' => 'Please upload a photo before setting partner preferences.',
            ]);
        }

        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            throw ValidationException::withMessages([
                'registration' => 'Linked biodata intake is missing.',
            ]);
        }

        $existing = $this->sourceSnapshot($intake);
        $prefsSnapshot = $this->preferencesBridge->buildPreferencesSnapshotFromRequest($request);
        $snapshot = array_merge($existing, $prefsSnapshot);

        $intake = $this->reviewSnapshotService->saveReviewedSnapshot($intake, $snapshot, [
            'reviewed_by_user_id' => null,
            'review_actor_type' => IntakeHumanReviewSnapshotService::ACTOR_PROFILE_USER,
            'review_surface' => IntakeHumanReviewSnapshotService::SURFACE_WEBSITE,
            'approval_policy' => IntakeHumanReviewSnapshotService::POLICY_PHASE2C_PROFILE_USER_REVIEW_V1,
            'approval_status' => IntakeHumanReviewSnapshotService::STATUS_REVIEWED,
        ]);

        $profile = $this->profileApplyService->profileForItem($item);
        if ($profile instanceof \App\Models\MatrimonyProfile) {
            $this->profileApplyService->applyRegistrationPreferences(
                $item,
                $prefsSnapshot,
                (int) ($profile->user_id ?? 0),
            );
        }

        $this->markPreferencesComplete($item);

        return $intake->refresh();
    }

    public function photoCandidateAbsolutePath(BulkIntakeBatchItem $item): ?string
    {
        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            return null;
        }

        if (! $this->photoCandidateCropService->exists($intake)) {
            return null;
        }

        return $this->photoCandidateCropService->absolutePath($intake);
    }

    private function markPhotoComplete(BulkIntakeBatchItem $item): void
    {
        $this->updateRegistrationMeta($item, [
            'photo_completed_at' => now()->toISOString(),
            'photo_completed_via' => 'public_web_form',
        ]);
    }

    private function markPreferencesComplete(BulkIntakeBatchItem $item): void
    {
        $this->updateRegistrationMeta($item, [
            'preferences_completed_at' => now()->toISOString(),
            'preferences_completed_via' => 'public_web_form',
        ]);
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function updateRegistrationMeta(BulkIntakeBatchItem $item, array $patch): void
    {
        DB::transaction(function () use ($item, $patch): void {
            $locked = BulkIntakeBatchItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $meta = is_array($locked->item_meta_json) ? $locked->item_meta_json : [];
            $existing = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
            $meta['registration'] = array_merge($existing, $patch);
            $locked->forceFill(['item_meta_json' => $meta])->save();
        });
    }

    private function markRegistrationComplete(BulkIntakeBatchItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $locked = BulkIntakeBatchItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $meta = is_array($locked->item_meta_json) ? $locked->item_meta_json : [];
            $existing = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
            $meta['registration'] = array_merge($existing, [
                'status' => BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE,
                'completed_at' => now()->toISOString(),
                'completed_via' => 'public_web_form',
            ]);
            $locked->forceFill(['item_meta_json' => $meta])->save();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceSnapshot(BiodataIntake $intake): array
    {
        if (is_array($intake->approval_snapshot_json) && $intake->approval_snapshot_json !== []) {
            return $intake->approval_snapshot_json;
        }

        if (is_array($intake->parsed_json) && $intake->parsed_json !== []) {
            return $intake->parsed_json;
        }

        return [];
    }

    private function intakeForItem(BulkIntakeBatchItem $item): ?BiodataIntake
    {
        $item->loadMissing('biodataIntake');

        return $item->biodataIntake;
    }

    private function snapshotLocked(BiodataIntake $intake): bool
    {
        return (bool) $intake->approved_by_user || (bool) $intake->intake_locked;
    }

    public function accessDeniedMessage(string $reason): string
    {
        return match ($reason) {
            'consent_not_received' => 'Registration link is not active until WhatsApp consent is received.',
            'missing_intake' => 'Registration data is missing.',
            'snapshot_locked' => 'This registration can no longer be edited.',
            'registration_incomplete' => 'Please complete the registration form first.',
            default => 'Registration link is not available.',
        };
    }
}
