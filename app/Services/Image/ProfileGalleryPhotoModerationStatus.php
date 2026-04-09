<?php

namespace App\Services\Image;

use App\Services\Admin\AdminSettingService;

/**
 * Maps ImageModerationService::moderateProfilePhoto() output to profile_photos.approved_status.
 *
 * Stage 2 (flagged / pending_manual / rejected) never becomes publicly "approved" from Stage 1 alone.
 * Only the automated "approved" (NudeNet-safe) path respects photo_approval_required (Stage 1).
 */
class ProfileGalleryPhotoModerationStatus
{
    public static function fromModerationResult(array $result): string
    {
        $status = (string) ($result['status'] ?? '');
        $photoApprovalRequired = AdminSettingService::isPhotoApprovalRequired();

        if ($status === 'rejected') {
            return 'rejected';
        }
        if ($status === 'pending_manual') {
            return 'pending';
        }
        if ($status === 'approved') {
            return $photoApprovalRequired ? 'pending' : 'approved';
        }

        return 'pending';
    }
}
