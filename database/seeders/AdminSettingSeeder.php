<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AdminSetting;

class AdminSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Default: Photo approval NOT required
        AdminSetting::setValue('photo_approval_required', '0');
        
        // Profile activation & suspension policies
        AdminSetting::setValue('manual_profile_activation', '0');
        AdminSetting::setValue('suspend_after_profile_edit', '0');
        AdminSetting::setValue('suspend_mode', 'none');
    }
}
