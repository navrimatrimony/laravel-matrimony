<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ContactVisibilityPolicyService
{
    public static function canViewContact(MatrimonyProfile $targetProfile, ?MatrimonyProfile $viewerProfile): bool
    {
        if (!$viewerProfile) {
            return false;
        }
        if ($viewerProfile->id === $targetProfile->id) {
            return true;
        }
        if ($targetProfile->lifecycle_state !== 'active') {
            return false;
        }
        if ($targetProfile->visibility_override === true) {
            return true;
        }
        $mode = $targetProfile->contact_unlock_mode ?? 'after_interest_accepted';
        if ($mode === 'never') {
            return false;
        }
        if ($mode === 'admin_only') {
            return false;
        }
        if ($mode === 'after_interest_accepted') {
            if (!Schema::hasTable('profile_contact_visibility')) {
                return false;
            }
            return DB::table('profile_contact_visibility')
                ->where('owner_profile_id', $targetProfile->id)
                ->where('viewer_profile_id', $viewerProfile->id)
                ->whereNull('revoked_at')
                ->exists();
        }
        return false;
    }
}