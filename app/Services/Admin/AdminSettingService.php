<?php

namespace App\Services\Admin;

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
     */
    public static function isManualProfileActivationRequired(): bool
    {
        return AdminSetting::getBool('manual_profile_activation_required', false);
    }

    /**
     * Stage 1 (clean / automated-safe path): when true, even NudeNet-safe photos stay hidden until admin approves.
     * When false, photos that pass automated screening can be marked visible without admin (see ProcessProfilePhoto + gallery moderation).
     * Flagged / suspicious photos never use this to auto-approve — they follow Stage 2 (manual queue or AI).
     */
    public static function isPhotoApprovalRequired(): bool
    {
        return AdminSetting::getBool('photo_approval_required', false);
    }

    /**
     * When true, after onboarding cards members must upload a photo before the app unlocks (middleware holds them on upload-photo).
     * When false (default), step 4 finishes into onboarding.complete without the photo step; skip links clear onboarding.
     */
    public static function isOnboardingPhotoRequired(): bool
    {
        return AdminSetting::getBool('onboarding_photo_required', false);
    }

    /**
     * Check if profile should be suspended after edit.
     * When true, profile edits trigger suspension for review.
     */
    public static function shouldSuspendAfterProfileEdit(): bool
    {
        return AdminSetting::getBool('suspend_after_profile_edit', false);
    }

    /**
     * Get suspend mode.
     * Options: 'full' (entire profile suspended) or 'new_content_only' (only new edits hidden)
     */
    public static function getSuspendMode(): string
    {
        return AdminSetting::getValue('suspend_mode', 'full');
    }
}
