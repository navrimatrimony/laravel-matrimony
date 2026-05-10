<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product simplification: education is {@code matrimony_profiles.highest_education} (multiselect) only.
 * Removes optional college/specialization snapshot fields and the {@code profile_education} history table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('profile_education');

        if (Schema::hasTable('matrimony_profiles')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                if (Schema::hasColumn('matrimony_profiles', 'college_id')) {
                    try {
                        $table->dropForeign(['college_id']);
                    } catch (\Throwable) {
                        //
                    }
                    $table->dropColumn('college_id');
                }
                if (Schema::hasColumn('matrimony_profiles', 'specialization')) {
                    $table->dropColumn('specialization');
                }
            });
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Intentionally not reversed (would lose data semantics after deploy).
    }
};
