<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unify highest education storage on degrees (education_degrees).
 * SSOT matrimony biodata stays on matrimony_profiles (not users).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('matrimony_profiles')) {
            return;
        }

        $afterCol = Schema::hasColumn('matrimony_profiles', 'highest_education_text')
            ? 'highest_education_text'
            : 'highest_education';

        Schema::table('matrimony_profiles', function (Blueprint $table) use ($afterCol) {
            if (! Schema::hasColumn('matrimony_profiles', 'education_degree_id')) {
                $table->unsignedBigInteger('education_degree_id')->nullable()->after($afterCol);
            }
            if (! Schema::hasColumn('matrimony_profiles', 'education_text')) {
                $table->string('education_text')->nullable()->after('education_degree_id');
            }
        });

        if (Schema::hasTable('education_degrees')
            && Schema::hasColumn('matrimony_profiles', 'education_degree_id')) {
            try {
                Schema::table('matrimony_profiles', function (Blueprint $table) {
                    $table->foreign('education_degree_id')
                        ->references('id')
                        ->on('education_degrees')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
                // FK may already exist on partial deploys.
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('matrimony_profiles')) {
            return;
        }

        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('matrimony_profiles', 'education_degree_id')) {
                try {
                    $table->dropForeign(['education_degree_id']);
                } catch (\Throwable) {
                    // ignore
                }
            }
        });

        Schema::table('matrimony_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('matrimony_profiles', 'education_text')) {
                $table->dropColumn('education_text');
            }
            if (Schema::hasColumn('matrimony_profiles', 'education_degree_id')) {
                $table->dropColumn('education_degree_id');
            }
        });
    }
};
