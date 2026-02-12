<?php

namespace App\Services;

use App\Models\MatrimonyProfile;

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
            $whitelist = $targetProfile->contact_visible_to ?? [];
            if (!is_array($whitelist)) {
                $whitelist = [];
            }
            return in_array($viewerProfile->id, $whitelist, true);
        }
        return false;
    }
}