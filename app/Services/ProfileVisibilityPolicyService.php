<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Models\User;

class ProfileVisibilityPolicyService
{
    public static function canViewProfile(MatrimonyProfile $targetProfile, ?User $viewerUser): bool
    {
        if (!$viewerUser) {
            return false;
        }
        $viewerProfile = $viewerUser->matrimonyProfile;
        if (!$viewerProfile) {
            return false;
        }
        if ($viewerProfile->id === $targetProfile->id) {
            return true;
        }
        if (!ProfileLifecycleService::isVisibleToOthers($targetProfile)) {
            return false;
        }
        if (ViewTrackingService::isBlocked($viewerProfile->id, $targetProfile->id)) {
            return false;
        }
        if ($targetProfile->visibility_override === true) {
            return true;
        }
        $mode = $targetProfile->profile_visibility_mode ?? 'public';
        if ($mode === 'public') {
            return true;
        }
        if ($mode === 'verified_only') {
            return true;
        }
        if ($mode === 'approved_only') {
            return true;
        }
        return true;
    }
}