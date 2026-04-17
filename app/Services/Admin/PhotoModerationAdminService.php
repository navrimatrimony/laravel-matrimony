<?php

namespace App\Services\Admin;

use App\Models\MatrimonyProfile;
use App\Models\PhotoLearningDataset;
use App\Models\PhotoModerationLog;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Notifications\ImageRejectedNotification;
use App\Services\Image\ProfileGalleryPhotoDeletionService;
use App\Support\SafeNotifier;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PhotoModerationAdminService
{
    public function __construct(
        private readonly ProfileGalleryPhotoDeletionService $galleryPhotoDeletion,
        private readonly UserModerationStatsService $userModerationStats,
    ) {}

    public static function moderationScanIndicatesUnsafe(?array $moderationScanJson): bool
    {
        if (! is_array($moderationScanJson)) {
            return false;
        }

        $st = PhotoModerationStoredScan::apiStatus($moderationScanJson);

        return $st === 'unsafe';
    }

    public function assertCanApprove(ProfilePhoto $photo): void
    {
        if (self::moderationScanIndicatesUnsafe($photo->moderation_scan_json)) {
            throw ValidationException::withMessages([
                'action' => ['Cannot approve: NudeNet API classified this image as unsafe.'],
            ]);
        }
    }

    /**
     * @param  'approve'|'move_to_review'|'reject'|'delete'  $action
     */
    public function applyPhotoAction(
        ProfilePhoto $photo,
        string $action,
        User $admin,
        ?string $reason = null,
    ): void {
        $this->assertModerationReason($reason);

        $version = (string) config('moderation.engine_version_label', 'laravel-nudenet-1');

        if ($action === 'delete') {
            $this->deleteWithAudit($photo, $admin, $reason);

            return;
        }

        if ($action === 'approve') {
            $this->assertCanApprove($photo);
        }

        $oldApproved = (string) ($photo->approved_status ?? 'pending');

        $fill = match ($action) {
            'approve' => [
                'approved_status' => 'approved',
                'admin_override_status' => 'approved',
                'admin_override_by' => $admin->id,
                'admin_override_at' => now(),
                'moderation_version' => $version,
            ],
            'move_to_review' => [
                'approved_status' => 'pending',
                'admin_override_status' => 'review',
                'admin_override_by' => $admin->id,
                'admin_override_at' => now(),
                'moderation_version' => $version,
            ],
            'reject' => [
                'approved_status' => 'rejected',
                'admin_override_status' => 'rejected',
                'admin_override_by' => $admin->id,
                'admin_override_at' => now(),
                'moderation_version' => $version,
            ],
            default => throw ValidationException::withMessages([
                'action' => ['Invalid moderation action.'],
            ]),
        };

        $newApproved = (string) $fill['approved_status'];

        DB::transaction(function () use ($photo, $fill, $oldApproved, $newApproved, $admin, $reason): void {
            $photo->forceFill($fill);
            $priorBypass = MatrimonyProfile::$bypassGovernanceEnforcement;
            MatrimonyProfile::$bypassGovernanceEnforcement = true;
            try {
                $photo->save();
            } finally {
                MatrimonyProfile::$bypassGovernanceEnforcement = $priorBypass;
            }

            $this->syncProfilePrimaryIfNeeded($photo);

            PhotoModerationLog::query()->create([
                'photo_id' => $photo->id,
                'old_status' => $oldApproved,
                'new_status' => $newApproved,
                'admin_id' => $admin->id,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            $learningJson = PhotoModerationLearningScanNormalizer::normalize(
                is_array($photo->moderation_scan_json) ? $photo->moderation_scan_json : null
            ) ?? ['detections' => []];

            PhotoLearningDataset::query()->create([
                'profile_photo_id' => $photo->id,
                'moderation_scan_json' => $learningJson,
                'final_decision' => (string) $fill['admin_override_status'],
                'admin_id' => $admin->id,
            ]);
        });

        if (in_array($action, ['approve', 'move_to_review', 'reject'], true)) {
            $profileUserId = MatrimonyProfile::query()->where('id', $photo->profile_id)->value('user_id');
            if ($profileUserId) {
                $this->userModerationStats->recordModerationOutcome(
                    (int) $profileUserId,
                    match ($action) {
                        'approve' => 'approved',
                        'reject' => 'rejected',
                        'move_to_review' => 'review',
                    }
                );
            }
        }

        if ($action === 'reject') {
            $profile = MatrimonyProfile::query()->find($photo->profile_id);
            $owner = $profile?->user;
            if ($owner !== null) {
                $msg = ($reason !== null && trim($reason) !== '') ? $reason : 'Your photo was rejected by moderation.';
                SafeNotifier::notify($owner, new ImageRejectedNotification($msg));
            }
        }
    }

    /**
     * @param  list<int>  $photoIds
     * @return list<string> Warnings (e.g. skipped ids)
     */
    public function applyBulk(
        array $photoIds,
        string $action,
        User $admin,
        ?string $reason = null,
    ): array {
        $warnings = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $photoIds))));
        foreach ($ids as $id) {
            $photo = ProfilePhoto::query()->find($id);
            if ($photo === null) {
                $warnings[] = "Missing photo id {$id}.";

                continue;
            }
            try {
                $this->applyPhotoAction($photo, $action, $admin, $reason);
            } catch (ValidationException $e) {
                $warnings[] = "Photo {$id}: ".implode(' ', Arr::flatten($e->errors()));
            }
        }

        return $warnings;
    }

    private function assertModerationReason(?string $reason): void
    {
        $t = trim((string) ($reason ?? ''));
        if (mb_strlen($t) < 10) {
            throw ValidationException::withMessages([
                'reason' => ['Reason required (min 10 characters).'],
            ]);
        }
    }

    private function deleteWithAudit(ProfilePhoto $photo, User $admin, ?string $reason): void
    {
        $oldApproved = (string) ($photo->approved_status ?? 'pending');
        $photoId = $photo->id;
        $profile = MatrimonyProfile::query()->findOrFail($photo->profile_id);

        PhotoModerationLog::query()->create([
            'photo_id' => $photoId,
            'old_status' => $oldApproved,
            'new_status' => 'deleted',
            'admin_id' => $admin->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);

        $this->galleryPhotoDeletion->deleteWithPrimaryResync($profile, $photo);
    }

    private function syncProfilePrimaryIfNeeded(ProfilePhoto $photo): void
    {
        if (! $photo->is_primary) {
            return;
        }

        $profile = MatrimonyProfile::query()->find($photo->profile_id);
        if ($profile === null) {
            return;
        }

        $approved = $photo->effectiveApprovedStatus() === 'approved';

        $priorBypass = MatrimonyProfile::$bypassGovernanceEnforcement;
        MatrimonyProfile::$bypassGovernanceEnforcement = true;
        try {
            $profile->profile_photo = $photo->file_path;
            $profile->photo_approved = $approved;
            if ($approved) {
                $profile->photo_rejected_at = null;
                $profile->photo_rejection_reason = null;
            }
            $profile->save();
        } finally {
            MatrimonyProfile::$bypassGovernanceEnforcement = $priorBypass;
        }
    }
}
