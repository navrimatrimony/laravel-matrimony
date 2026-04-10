<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_photos')) {
            return;
        }

        Schema::table('profile_photos', function (Blueprint $table) {
            if (! Schema::hasColumn('profile_photos', 'admin_override_status')) {
                $table->string('admin_override_status', 32)->nullable()->after('moderation_scan_json');
            }
            if (! Schema::hasColumn('profile_photos', 'admin_override_by')) {
                $table->foreignId('admin_override_by')->nullable()->after('admin_override_status')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('profile_photos', 'admin_override_at')) {
                $table->timestamp('admin_override_at')->nullable()->after('admin_override_by');
            }
            if (! Schema::hasColumn('profile_photos', 'moderation_version')) {
                $table->string('moderation_version', 64)->nullable()->after('admin_override_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_photos')) {
            return;
        }

        Schema::table('profile_photos', function (Blueprint $table) {
            if (Schema::hasColumn('profile_photos', 'moderation_version')) {
                $table->dropColumn('moderation_version');
            }
            if (Schema::hasColumn('profile_photos', 'admin_override_at')) {
                $table->dropColumn('admin_override_at');
            }
            if (Schema::hasColumn('profile_photos', 'admin_override_by')) {
                $table->dropConstrainedForeignId('admin_override_by');
            }
            if (Schema::hasColumn('profile_photos', 'admin_override_status')) {
                $table->dropColumn('admin_override_status');
            }
        });
    }
};
