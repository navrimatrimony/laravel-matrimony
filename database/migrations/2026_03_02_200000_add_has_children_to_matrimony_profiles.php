<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: has_children for MaritalEngine (required for divorced/separated/widowed).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('matrimony_profiles') && ! Schema::hasColumn('matrimony_profiles', 'has_children')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                $table->boolean('has_children')->nullable()->after('marital_status_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('matrimony_profiles', 'has_children')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                $table->dropColumn('has_children');
            });
        }
    }
};
