<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: has_siblings for Siblings section (Yes = show sibling engine, No = hide).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('matrimony_profiles') && ! Schema::hasColumn('matrimony_profiles', 'has_siblings')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                $table->boolean('has_siblings')->nullable()->after('mother_occupation');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('matrimony_profiles', 'has_siblings')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                $table->dropColumn('has_siblings');
            });
        }
    }
};
