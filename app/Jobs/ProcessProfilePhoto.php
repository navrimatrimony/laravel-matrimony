<?php

namespace App\Jobs;

use App\Models\MatrimonyProfile;
use App\Models\AdminSetting;
use App\Models\ProfilePhoto;
use App\Services\Admin\AdminSettingService;
use App\Services\Admin\UserModerationStatsService;
use App\Services\Image\ImageModerationService;
use App\Services\Image\ImageOptimizationService;
use App\Services\Image\PhotoModerationScanPayload;
use App\Services\Image\ProfileGalleryPhotoModerationStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessProfilePhoto implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PRIMARY_MODE_REPLACE_PRIMARY_UPDATE_EXISTING = 'replace_primary_update_existing';
    public const PRIMARY_MODE_INTAKE_CROP_PRIMARY_IF_NONE = 'intake_crop_primary_if_none';

    public function __construct(
        public readonly string $tempImagePath,
        public readonly int $profileId,
        public readonly string $uploadedVia = 'user_web',
        public readonly string $primaryMode = self::PRIMARY_MODE_REPLACE_PRIMARY_UPDATE_EXISTING,
    ) {}

    public function handle(
        ImageModerationService $moderation,
        ImageOptimizationService $optimizer,
    ): void {
        Log::info('PHOTO JOB STARTED', ['path' => $this->tempImagePath, 'profile_id' => $this->profileId]);

        $profile = MatrimonyProfile::query()->find($this->profileId);
        if (! $profile) {
            Log::warning('PHOTO JOB: profile not found', ['profile_id' => $this->profileId]);
            $this->safeDeleteTemp();

            return;
        }

        // Validate temp file exists and is image-like.
        if (! is_file($this->tempImagePath)) {
            Log::warning('ProcessProfilePhoto: temp file missing', [
                'profile_id' => $this->profileId,
                'temp' => $this->tempImagePath,
            ]);

            return;
        }

        Log::info('RUNNING MODERATION', ['profile_id' => $this->profileId]);
        $result = $moderation->moderateProfilePhoto($this->tempImagePath);
        Log::info('MODERATION RESULT', ['profile_id' => $this->profileId, 'result' => $result]);

        $base = (string) Str::uuid();
        $optimized = $optimizer->optimizeAndStoreProfilePhoto($this->tempImagePath, $base);
        $finalFilename = $optimized['filename'];

        $photoApproved = false;
$rejectedAt = null;
$rejectionReason = null;

$statusFromAI = $result['status'] ?? null;

// ✅ APPROVED
if ($statusFromAI === 'approved') {
    $photoApproved = true;

// ❌ REJECTED
} elseif ($statusFromAI === 'rejected') {
    $photoApproved = false;
    $rejectedAt = now();
    $rejectionReason = (string) ($result['reason'] ?? 'Rejected by moderation.');

// 🚨 AI DOWN / ERROR
} elseif ($statusFromAI === 'error') {

    Log::error('AI SERVICE DOWN', [
        'profile_id' => $this->profileId,
        'reason' => $result['reason'] ?? null,
    ]);

    $photoApproved = false;
    $rejectedAt = null;
    $rejectionReason = 'AI service down — try again later';

// ⏳ PENDING (review case)
} else {
    $photoApproved = false;
    $rejectedAt = null;
    $rejectionReason = null;
}

        $moderationSnapshot = PhotoModerationScanPayload::fromModerationResult($result);

// 🚨 IMPORTANT FIX
if ($statusFromAI === 'error') {
    $status = 'error';
} else {
    $status = $photoApproved ? 'approved' : (($rejectedAt !== null) ? 'rejected' : 'pending');
}
        Log::info('PHOTO APPROVED OR REJECTED', [
            'profile_id' => $this->profileId,
            'status' => $status,
            'photo_approval_required' => AdminSettingService::isPhotoApprovalRequired(),
            'final_filename' => $finalFilename,
        ]);

        $primaryDecision = $this->primaryDecision($profile);

        if ($primaryDecision['new_is_primary']) {
            // Always update the stored filename so admin can review it even when not approved.
            // Use bypass to avoid governance conflicts for profile_photo field changes via background job.
            MatrimonyProfile::$bypassGovernanceEnforcement = true;
            try {
                $fill = [
                    'profile_photo' => $finalFilename,
                    'photo_approved' => $photoApproved,
                    'photo_rejected_at' => $rejectedAt,
                    'photo_rejection_reason' => $rejectionReason,
                ];
                if (Schema::hasColumn('matrimony_profiles', 'photo_moderation_snapshot')) {
                    $fill['photo_moderation_snapshot'] = $moderationSnapshot;
                }
                $profile->forceFill($fill)->save();
            } finally {
                MatrimonyProfile::$bypassGovernanceEnforcement = false;
            }
        }

        $this->syncPrimaryGalleryRow($profile, $finalFilename, $status, $moderationSnapshot, $primaryDecision);

        $this->safeDeleteTemp();

        // Optional safety: ensure file is public-readable.
        Storage::disk('public')->setVisibility('matrimony_photos/'.$finalFilename, 'public');
    }

    /**
     * Upload page gallery reads `profile_photos`; primary upload used to only set `matrimony_profiles.profile_photo`.
     * Keep one primary row in sync so the member UI and slot counts match.
     */
    /**
     * @param  array<string, mixed>|null  $moderationSnapshot
     */
    private function syncPrimaryGalleryRow(MatrimonyProfile $profile, string $finalFilename, string $status, ?array $moderationSnapshot = null, ?array $primaryDecision = null): void
    {
        if (! Schema::hasTable('profile_photos')) {
            return;
        }

        $approvedStatus = ProfileGalleryPhotoModerationStatus::fromModerationResult(['status' => $status]);
        if ($status === 'error') {
            $approvedStatus = 'error';
        }

        $primaryDecision ??= $this->primaryDecision($profile);
        $previousPrimaryId = $primaryDecision['previous_primary_id'];
        $newIsPrimary = (bool) $primaryDecision['new_is_primary'];

        $payload = [
            'file_path' => $finalFilename,
            'uploaded_via' => $this->uploadedVia,
            'approved_status' => $approvedStatus,
            'watermark_detected' => false,
            'is_primary' => $newIsPrimary,
        ];
        if (Schema::hasColumn('profile_photos', 'moderation_scan_json')) {
            $payload['moderation_scan_json'] = $moderationSnapshot;
        }
        if (Schema::hasColumn('profile_photos', 'sort_order')) {
            $payload['sort_order'] = $newIsPrimary ? 0 : $this->nextSortOrder($profile);
        }

        if ($newIsPrimary) {
            ProfilePhoto::query()
                ->where('profile_id', $profile->id)
                ->update(['is_primary' => false]);
        }

        if (($primaryDecision['update_previous_primary'] ?? false) === true && $previousPrimaryId !== null) {
            ProfilePhoto::query()->where('id', $previousPrimaryId)->update($payload);
            if (Schema::hasTable('user_moderation_stats')) {
                $uid = $profile->user_id;
                if ($uid) {
                    app(UserModerationStatsService::class)->recordUpload((int) $uid);
                }
            }
        } else {
            ProfilePhoto::query()->create(array_merge([
                'profile_id' => $profile->id,
            ], $payload));
        }
    }

    /**
     * @return array{new_is_primary: bool, update_previous_primary: bool, previous_primary_id: int|null}
     */
    private function primaryDecision(MatrimonyProfile $profile): array
    {
        $previousPrimary = ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->where('is_primary', true)
            ->first(['id', 'uploaded_via']);

        if ($this->primaryMode === self::PRIMARY_MODE_INTAKE_CROP_PRIMARY_IF_NONE) {
            return [
                'new_is_primary' => $previousPrimary === null,
                'update_previous_primary' => false,
                'previous_primary_id' => $previousPrimary?->id,
            ];
        }

        if ($this->uploadedVia === 'user_web' && (string) ($previousPrimary?->uploaded_via ?? '') === 'intake_crop') {
            $policy = (string) AdminSetting::getValue('intake_photo_later_upload_primary_policy', 'new_upload_primary');

            return [
                'new_is_primary' => $policy !== 'keep_intake_primary',
                'update_previous_primary' => false,
                'previous_primary_id' => $previousPrimary?->id,
            ];
        }

        return [
            'new_is_primary' => true,
            'update_previous_primary' => $previousPrimary !== null,
            'previous_primary_id' => $previousPrimary?->id,
        ];
    }

    private function nextSortOrder(MatrimonyProfile $profile): int
    {
        if (! Schema::hasColumn('profile_photos', 'sort_order')) {
            return 0;
        }

        $max = ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->max('sort_order');

        return $max === null ? 0 : ((int) $max + 1);
    }

    private function safeDeleteTemp(): void
    {
        try {
            if (is_file($this->tempImagePath)) {
                @unlink($this->tempImagePath);
            }
        } catch (\Throwable) {
            // ignore
        }
    }
}
