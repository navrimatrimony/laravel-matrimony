<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profile_photos') && ! Schema::hasColumn('profile_photos', 'moderation_scan_json')) {
            Schema::table('profile_photos', function (Blueprint $table) {
                $table->json('moderation_scan_json')->nullable()->after('watermark_detected');
            });
        }

        if (Schema::hasTable('matrimony_profiles') && ! Schema::hasColumn('matrimony_profiles', 'photo_moderation_snapshot')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                $table->json('photo_moderation_snapshot')->nullable()->after('photo_rejection_reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('profile_photos') && Schema::hasColumn('profile_photos', 'moderation_scan_json')) {
            Schema::table('profile_photos', function (Blueprint $table) {
                $table->dropColumn('moderation_scan_json');
            });
        }

        if (Schema::hasTable('matrimony_profiles') && Schema::hasColumn('matrimony_profiles', 'photo_moderation_snapshot')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                $table->dropColumn('photo_moderation_snapshot');
            });
        }
    }
};
