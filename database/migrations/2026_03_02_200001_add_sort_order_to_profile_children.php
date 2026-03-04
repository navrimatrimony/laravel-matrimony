<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: sort_order for profile_children (MaritalEngine children repeater).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profile_children') && ! Schema::hasColumn('profile_children', 'sort_order')) {
            Schema::table('profile_children', function (Blueprint $table) {
                $table->unsignedInteger('sort_order')->default(0)->after('child_living_with_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('profile_children') && Schema::hasColumn('profile_children', 'sort_order')) {
            Schema::table('profile_children', function (Blueprint $table) {
                $table->dropColumn('sort_order');
            });
        }
    }
};
