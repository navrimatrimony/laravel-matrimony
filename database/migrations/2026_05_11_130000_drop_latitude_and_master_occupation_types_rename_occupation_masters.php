<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Drops stray legacy {@code latitude} table if present (not part of profile geo SSOT).
 * - Drops {@code master_occupation_types} (education-career UI now uses working_with / occupation engine only).
 * - Renames occupation engine tables to {@code master_*} naming:
 *   {@code occupation_categories} → {@code master_occupation_categories},
 *   {@code occupation_master} → {@code master_occupations},
 *   {@code occupation_custom} → {@code master_occupation_custom},
 *   {@code occupation_master_aliases} → {@code master_occupation_aliases}.
 *
 * Profile column names ({@code occupation_master_id}, etc.) are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('latitude');

        Schema::dropIfExists('master_occupation_types');

        if (Schema::hasTable('master_occupations')) {
            return;
        }
        if (! Schema::hasTable('occupation_master')) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        foreach (
            [
                ['occupation_master_aliases', 'occupation_master_id'],
                ['profile_preferred_occupation_master', 'occupation_master_id'],
                ['matrimony_profiles', 'occupation_master_id'],
                ['matrimony_profiles', 'occupation_custom_id'],
                ['matrimony_profiles', 'father_occupation_master_id'],
                ['matrimony_profiles', 'father_occupation_custom_id'],
                ['matrimony_profiles', 'mother_occupation_master_id'],
                ['matrimony_profiles', 'mother_occupation_custom_id'],
                ['profile_siblings', 'occupation_master_id'],
                ['profile_siblings', 'occupation_custom_id'],
                ['profile_relatives', 'occupation_master_id'],
                ['profile_relatives', 'occupation_custom_id'],
                ['profile_sibling_spouses', 'occupation_master_id'],
                ['profile_sibling_spouses', 'occupation_custom_id'],
                ['occupation_master', 'category_id'],
            ] as [$tbl, $col]
        ) {
            $this->dropForeignIfExists($tbl, $col);
        }

        Schema::rename('occupation_categories', 'master_occupation_categories');
        Schema::rename('occupation_master', 'master_occupations');
        Schema::rename('occupation_custom', 'master_occupation_custom');
        if (Schema::hasTable('occupation_master_aliases')) {
            Schema::rename('occupation_master_aliases', 'master_occupation_aliases');
        }

        Schema::table('master_occupations', function (Blueprint $table) {
            $table->foreign('category_id')->references('id')->on('master_occupation_categories')->cascadeOnDelete();
        });

        if (Schema::hasTable('master_occupation_aliases')) {
            Schema::table('master_occupation_aliases', function (Blueprint $table) {
                $table->foreign('occupation_master_id')->references('id')->on('master_occupations')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('matrimony_profiles')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                if (Schema::hasColumn('matrimony_profiles', 'occupation_master_id')) {
                    $table->foreign('occupation_master_id')->references('id')->on('master_occupations')->nullOnDelete();
                }
                if (Schema::hasColumn('matrimony_profiles', 'occupation_custom_id')) {
                    $table->foreign('occupation_custom_id')->references('id')->on('master_occupation_custom')->nullOnDelete();
                }
                if (Schema::hasColumn('matrimony_profiles', 'father_occupation_master_id')) {
                    $table->foreign('father_occupation_master_id')->references('id')->on('master_occupations')->nullOnDelete();
                }
                if (Schema::hasColumn('matrimony_profiles', 'father_occupation_custom_id')) {
                    $table->foreign('father_occupation_custom_id')->references('id')->on('master_occupation_custom')->nullOnDelete();
                }
                if (Schema::hasColumn('matrimony_profiles', 'mother_occupation_master_id')) {
                    $table->foreign('mother_occupation_master_id')->references('id')->on('master_occupations')->nullOnDelete();
                }
                if (Schema::hasColumn('matrimony_profiles', 'mother_occupation_custom_id')) {
                    $table->foreign('mother_occupation_custom_id')->references('id')->on('master_occupation_custom')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('profile_preferred_occupation_master')) {
            Schema::table('profile_preferred_occupation_master', function (Blueprint $table) {
                $table->foreign('occupation_master_id')->references('id')->on('master_occupations')->cascadeOnDelete();
            });
        }

        foreach (['profile_siblings', 'profile_relatives', 'profile_sibling_spouses'] as $tbl) {
            if (! Schema::hasTable($tbl)) {
                continue;
            }
            Schema::table($tbl, function (Blueprint $table) use ($tbl) {
                if (Schema::hasColumn($tbl, 'occupation_master_id')) {
                    $table->foreign('occupation_master_id')->references('id')->on('master_occupations')->nullOnDelete();
                }
                if (Schema::hasColumn($tbl, 'occupation_custom_id')) {
                    $table->foreign('occupation_custom_id')->references('id')->on('master_occupation_custom')->nullOnDelete();
                }
            });
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Intentionally not reversed: would collide with post-rename app code.
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
            //
        }
    }
};
