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
        Schema::table('profile_visibility_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('profile_visibility_settings', 'enable_relatives_section')) {
                $table->boolean('enable_relatives_section')->default(true)->after('hide_from_blocked_users');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_visibility_settings')) {
            return;
        }
        Schema::table('profile_visibility_settings', function (Blueprint $table) {
            if (Schema::hasColumn('profile_visibility_settings', 'enable_relatives_section')) {
                $table->dropColumn('enable_relatives_section');
            }
        });
    }
};
