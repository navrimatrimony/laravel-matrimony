<?php

use App\Services\ProfileVisibilitySettingsDefaultsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profiles created before {@see \App\Observers\MatrimonyProfileObserver} may lack a visibility row.
     */
    public function up(): void
    {
        if (! Schema::hasTable('matrimony_profiles') || ! Schema::hasTable('profile_visibility_settings')) {
            return;
        }

        $profileIds = DB::table('matrimony_profiles')->pluck('id');
        foreach ($profileIds as $pid) {
            if (DB::table('profile_visibility_settings')->where('profile_id', $pid)->exists()) {
                continue;
            }
            $isDemo = (bool) DB::table('matrimony_profiles')->where('id', $pid)->value('is_demo');
            $defaults = $isDemo
                ? ProfileVisibilitySettingsDefaultsService::showcaseDefaults()
                : ProfileVisibilitySettingsDefaultsService::registrationDefaults();

            DB::table('profile_visibility_settings')->insert(array_merge($defaults, [
                'profile_id' => $pid,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        // Non-destructive: do not delete user data.
    }
};
