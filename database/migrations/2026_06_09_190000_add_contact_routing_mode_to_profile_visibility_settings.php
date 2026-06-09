<?php

use App\Models\ProfileVisibilitySetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_visibility_settings')) {
            return;
        }

        if (! Schema::hasColumn('profile_visibility_settings', 'contact_routing_mode')) {
            Schema::table('profile_visibility_settings', function (Blueprint $table) {
                $afterColumn = Schema::hasColumn('profile_visibility_settings', 'contact_visibility_json')
                    ? 'contact_visibility_json'
                    : 'hide_from_blocked_users';

                $table->string('contact_routing_mode', 32)
                    ->default(ProfileVisibilitySetting::CONTACT_ROUTING_DIRECT_AND_SUCHAK)
                    ->after($afterColumn);
            });
        }

        DB::table('profile_visibility_settings')
            ->whereNull('contact_routing_mode')
            ->update([
                'contact_routing_mode' => ProfileVisibilitySetting::CONTACT_ROUTING_DIRECT_AND_SUCHAK,
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_visibility_settings')) {
            return;
        }

        if (Schema::hasColumn('profile_visibility_settings', 'contact_routing_mode')) {
            Schema::table('profile_visibility_settings', function (Blueprint $table) {
                $table->dropColumn('contact_routing_mode');
            });
        }
    }
};
