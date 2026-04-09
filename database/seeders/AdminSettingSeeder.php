<?php

namespace Database\Seeders;

use App\Models\AdminSetting;
use Illuminate\Database\Seeder;

class AdminSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Default: Photo approval NOT required
        AdminSetting::setValue('photo_approval_required', '0');
        AdminSetting::setValue('photo_moderation_mode', 'manual'); // manual|auto
        AdminSetting::setValue('photo_ai_provider', 'openai'); // openai|sarvam
        // When ON: after NudeNet reports safe, still run OpenAI/Sarvam moderation before auto-approve (fixes bad NudeNet).
        AdminSetting::setValue('photo_verify_safe_with_secondary_ai', '0');

        // Profile activation & suspension policies
        AdminSetting::setValue('manual_profile_activation', '0');
        AdminSetting::setValue('suspend_after_profile_edit', '0');
        AdminSetting::setValue('suspend_mode', 'none');

        // Mobile OTP: off | dev_show (OTP on screen) | live (real SMS later)
        AdminSetting::setValue('mobile_verification_mode', 'dev_show');
        // After registration: redirect to OTP step (user can skip and go to wizard)
        AdminSetting::setValue('redirect_to_mobile_verify_after_registration', '1');
    }
}
