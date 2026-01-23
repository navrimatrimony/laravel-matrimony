<?php

namespace App\Services;

use App\Models\AdminSetting;

/*
|--------------------------------------------------------------------------
| AdminSettingService
|--------------------------------------------------------------------------
|
| 👉 Provides access to admin policy settings
| 👉 Ensures policy-first implementation
|
*/
class AdminSettingService
{
    /**
     * Check if photo approval is required
     *
     * @return bool
     */
    public static function isPhotoApprovalRequired(): bool
    {
        return AdminSetting::getBool('photo_approval_required', false);
    }

    /**
     * Check if manual profile activation is required
     *
     * @return bool
     */
    public static function isManualProfileActivationRequired(): bool
    {
        return AdminSetting::getBool('manual_profile_activation', false);
    }

    /**
     * Check if profile should be suspended after edit
     *
     * @return bool
     */
    public static function shouldSuspendAfterProfileEdit(): bool
    {
        return AdminSetting::getBool('suspend_after_profile_edit', false);
    }

    /**
     * Get suspend mode after profile edit
     *
     * @return string
     */
    public static function getSuspendMode(): string
    {
        return AdminSetting::getValue('suspend_mode', 'none');
    }
}
