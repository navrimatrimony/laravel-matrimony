<?php

namespace App\Services\Image;

use App\Models\MatrimonyProfile;

/**
 * Primary photo moderation columns are owned by {@see ProcessProfilePhoto} after processing.
 * When a new upload is queued, this sets "pending review" state without going through MutationService.
 */
class ProfilePhotoPendingStateService
{
    public function applyPendingReviewState(MatrimonyProfile $profile): void
    {
        $prior = MatrimonyProfile::$bypassGovernanceEnforcement;
        MatrimonyProfile::$bypassGovernanceEnforcement = true;
        try {
            $fill = [
                'photo_approved' => false,
                'photo_rejected_at' => null,
                'photo_rejection_reason' => null,
            ];
            if (\Illuminate\Support\Facades\Schema::hasColumn('matrimony_profiles', 'photo_moderation_snapshot')) {
                $fill['photo_moderation_snapshot'] = null;
            }
            $profile->forceFill($fill)->save();
        } finally {
            MatrimonyProfile::$bypassGovernanceEnforcement = $prior;
        }
    }
}
