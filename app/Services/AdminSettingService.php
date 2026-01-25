<?php

namespace App\Services;

use App\Models\AdminSetting;

/*
|--------------------------------------------------------------------------
| AdminSettingService
|--------------------------------------------------------------------------
|
| Wrapper service for admin settings.
| Provides typed helper methods for common policy checks.
| Uses AdminSetting model for storage.
|
*/
class AdminSettingService
{
    /**
     * Check if manual profile activation is required.
     * When true, new profiles start as suspended until admin activates.
     *
     * @return bool
     */
    public static function isManualProfileActivationRequired(): bool
    {
        return AdminSetting::getBool('manual_profile_activation_required', false);
    }

    /**
     * Check if photo approval is required.
     * When true, new photos are hidden until admin approves.
     *
     * @return bool
     */
    public static function isPhotoApprovalRequired(): bool
    {
        return AdminSetting::getBool('photo_approval_required', false);
    }

    /**
     * Check if profile should be suspended after edit.
     * When true, profile edits trigger suspension for review.
     *
     * @return bool
     */
    public static function shouldSuspendAfterProfileEdit(): bool
    {
        return AdminSetting::getBool('suspend_after_profile_edit', false);
    }

    /**
     * Get suspend mode.
     * Options: 'full' (entire profile suspended) or 'new_content_only' (only new edits hidden)
     *
     * @return string
     */
    public static function getSuspendMode(): string
    {
        return AdminSetting::getValue('suspend_mode', 'full');
    }
}
