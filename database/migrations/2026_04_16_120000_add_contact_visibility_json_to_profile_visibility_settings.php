<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_visibility_settings')) {
            return;
        }
        if (! Schema::hasColumn('profile_visibility_settings', 'contact_visibility_json')) {
            Schema::table('profile_visibility_settings', function (Blueprint $table) {
                $table->json('contact_visibility_json')->nullable()->after('hide_from_blocked_users');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_visibility_settings')) {
            return;
        }
        if (Schema::hasColumn('profile_visibility_settings', 'contact_visibility_json')) {
            Schema::table('profile_visibility_settings', function (Blueprint $table) {
                $table->dropColumn('contact_visibility_json');
            });
        }
    }
};
