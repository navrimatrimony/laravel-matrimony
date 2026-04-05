<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supports "who viewed me" time-window queries: viewed_profile_id + created_at range.
     */
    public function up(): void
    {
        if (! Schema::hasTable('profile_views')) {
            return;
        }

        if (Schema::hasIndex('profile_views', 'pv_viewed_created_idx')) {
            return;
        }

        Schema::table('profile_views', function (Blueprint $table) {
            $table->index(['viewed_profile_id', 'created_at'], 'pv_viewed_created_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_views')) {
            return;
        }

        if (! Schema::hasIndex('profile_views', 'pv_viewed_created_idx')) {
            return;
        }

        Schema::table('profile_views', function (Blueprint $table) {
            $table->dropIndex('pv_viewed_created_idx');
        });
    }
};
