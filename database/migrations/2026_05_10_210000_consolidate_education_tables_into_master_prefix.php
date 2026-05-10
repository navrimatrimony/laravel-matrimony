<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single SSOT for catalog degrees:
 * - Drops legacy {@code master_education} (PHASE-5 duplicate; no category_id).
 * - Renames {@code education_categories} → {@code master_education_categories}.
 * - Renames {@code education_degrees} → {@code master_education} (live degree rows; IDs preserved).
 * - Renames {@code education_degree_aliases} → {@code master_education_aliases}.
 *
 * Profile columns {@code matrimony_profiles.education_degree_id} and pivot
 * {@code profile_preferred_education_degrees.education_degree_id} keep the same names; FK targets update.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('master_education') && Schema::hasColumn('master_education', 'category_id')) {
            return;
        }
        if (! Schema::hasTable('education_degrees') || ! Schema::hasTable('education_categories')) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('profile_preferred_master_education');

        $this->dropForeignIfExists('matrimony_profiles', 'education_degree_id');
        $this->dropForeignIfExists('profile_preferred_education_degrees', 'education_degree_id');
        $this->dropForeignIfExists('education_degree_aliases', 'education_degree_id');
        $this->dropForeignIfExists('education_degrees', 'category_id');

        if (Schema::hasTable('master_education') && ! Schema::hasColumn('master_education', 'category_id')) {
            Schema::drop('master_education');
        }

        Schema::rename('education_categories', 'master_education_categories');
        Schema::rename('education_degrees', 'master_education');
        Schema::rename('education_degree_aliases', 'master_education_aliases');

        Schema::table('master_education', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')
                ->on('master_education_categories')
                ->cascadeOnDelete();
        });

        Schema::table('master_education_aliases', function (Blueprint $table) {
            $table->foreign('education_degree_id')
                ->references('id')
                ->on('master_education')
                ->cascadeOnDelete();
        });

        if (Schema::hasTable('matrimony_profiles') && Schema::hasColumn('matrimony_profiles', 'education_degree_id')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                $table->foreign('education_degree_id')
                    ->references('id')
                    ->on('master_education')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('profile_preferred_education_degrees')) {
            Schema::table('profile_preferred_education_degrees', function (Blueprint $table) {
                $table->foreign('education_degree_id')
                    ->references('id')
                    ->on('master_education')
                    ->cascadeOnDelete();
            });
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Intentionally not reversible on production (would resurrect duplicate master).
    }

    private function dropForeignIfExists(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
            });
        } catch (\Throwable) {
            // Driver-specific FK names; ignore if already absent.
        }
    }
};
