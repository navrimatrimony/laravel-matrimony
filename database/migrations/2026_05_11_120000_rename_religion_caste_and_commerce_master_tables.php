<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prefix core reference masters with {@code master_} (single copy each; column names unchanged).
 *
 * - {@code religions} → {@code master_religions}
 * - {@code religion_aliases} → {@code master_religion_aliases}
 * - {@code castes} → {@code master_castes}
 * - {@code caste_aliases} → {@code master_caste_aliases}
 * - {@code sub_castes} → {@code master_sub_castes}
 * - {@code sub_caste_aliases} → {@code master_sub_caste_aliases}
 * - {@code colleges} → {@code master_colleges}
 * - {@code income_ranges} → {@code master_income_ranges}
 * - {@code coupons} → {@code master_coupons}
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('master_religions')) {
            return;
        }
        if (! Schema::hasTable('religions')) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        foreach (
            [
                ['matrimony_profiles', 'religion_id'],
                ['matrimony_profiles', 'caste_id'],
                ['matrimony_profiles', 'sub_caste_id'],
                ['matrimony_profiles', 'college_id'],
                ['matrimony_profiles', 'income_range_id'],
                ['subscriptions', 'coupon_id'],
                ['profile_preferred_castes', 'caste_id'],
                ['profile_preferred_religions', 'religion_id'],
                ['castes', 'religion_id'],
                ['sub_castes', 'caste_id'],
                ['caste_aliases', 'caste_id'],
                ['religion_aliases', 'religion_id'],
                ['sub_caste_aliases', 'sub_caste_id'],
            ] as [$tbl, $col]
        ) {
            $this->dropForeignIfExists($tbl, $col);
        }

        Schema::rename('religion_aliases', 'master_religion_aliases');
        Schema::rename('caste_aliases', 'master_caste_aliases');
        Schema::rename('sub_caste_aliases', 'master_sub_caste_aliases');
        Schema::rename('sub_castes', 'master_sub_castes');
        Schema::rename('castes', 'master_castes');
        Schema::rename('religions', 'master_religions');
        Schema::rename('colleges', 'master_colleges');
        Schema::rename('income_ranges', 'master_income_ranges');
        Schema::rename('coupons', 'master_coupons');

        Schema::table('master_castes', function (Blueprint $table) {
            $table->foreign('religion_id')->references('id')->on('master_religions')->nullOnDelete();
        });
        Schema::table('master_sub_castes', function (Blueprint $table) {
            $table->foreign('caste_id')->references('id')->on('master_castes')->cascadeOnDelete();
        });
        Schema::table('master_caste_aliases', function (Blueprint $table) {
            $table->foreign('caste_id')->references('id')->on('master_castes')->cascadeOnDelete();
        });
        Schema::table('master_religion_aliases', function (Blueprint $table) {
            $table->foreign('religion_id')->references('id')->on('master_religions')->cascadeOnDelete();
        });
        Schema::table('master_sub_caste_aliases', function (Blueprint $table) {
            $table->foreign('sub_caste_id')->references('id')->on('master_sub_castes')->cascadeOnDelete();
        });

        if (Schema::hasTable('matrimony_profiles')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                if (Schema::hasColumn('matrimony_profiles', 'religion_id')) {
                    $table->foreign('religion_id')->references('id')->on('master_religions')->nullOnDelete();
                }
                if (Schema::hasColumn('matrimony_profiles', 'caste_id')) {
                    $table->foreign('caste_id')->references('id')->on('master_castes')->nullOnDelete();
                }
                if (Schema::hasColumn('matrimony_profiles', 'sub_caste_id')) {
                    $table->foreign('sub_caste_id')->references('id')->on('master_sub_castes')->nullOnDelete();
                }
                if (Schema::hasColumn('matrimony_profiles', 'college_id')) {
                    $table->foreign('college_id')->references('id')->on('master_colleges')->nullOnDelete();
                }
                if (Schema::hasColumn('matrimony_profiles', 'income_range_id')) {
                    $table->foreign('income_range_id')->references('id')->on('master_income_ranges')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('profile_preferred_castes')) {
            Schema::table('profile_preferred_castes', function (Blueprint $table) {
                $table->foreign('caste_id')->references('id')->on('master_castes')->cascadeOnDelete();
            });
        }
        if (Schema::hasTable('profile_preferred_religions')) {
            Schema::table('profile_preferred_religions', function (Blueprint $table) {
                $table->foreign('religion_id')->references('id')->on('master_religions')->cascadeOnDelete();
            });
        }
        if (Schema::hasTable('subscriptions') && Schema::hasColumn('subscriptions', 'coupon_id')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->foreign('coupon_id')->references('id')->on('master_coupons')->nullOnDelete();
            });
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Not reversible safely (would collide with prior master_* names).
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
