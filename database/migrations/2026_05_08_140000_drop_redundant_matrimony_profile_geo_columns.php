<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Residence SSOT is {@code location_id} → {@code addresses}. Split legacy columns are redundant.
 * Birth place SSOT is {@code birth_city_id} only; taluka/district/state duplicates hierarchy.
 */
return new class extends Migration
{
    private function tryDropForeign(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropForeign([$column]);
            });
        } catch (\Throwable) {
            // FK name differs or already removed
        }
    }

    public function up(): void
    {
        if (! Schema::hasTable('matrimony_profiles')) {
            return;
        }

        foreach (['birth_taluka_id', 'birth_district_id', 'birth_state_id'] as $col) {
            $this->tryDropForeign('matrimony_profiles', $col);
        }

        // SQLite: drop single-column indexes before dropColumn (otherwise rebuild fails on orphaned index names).
        foreach (['country_id', 'state_id', 'district_id', 'taluka_id', 'city_id'] as $col) {
            if (! Schema::hasColumn('matrimony_profiles', $col)) {
                continue;
            }
            try {
                Schema::table('matrimony_profiles', function (Blueprint $table) use ($col): void {
                    $table->dropIndex([$col]);
                });
            } catch (\Throwable) {
            }
        }

        Schema::table('matrimony_profiles', function (Blueprint $table): void {
            $drops = array_values(array_filter([
                Schema::hasColumn('matrimony_profiles', 'country_id') ? 'country_id' : null,
                Schema::hasColumn('matrimony_profiles', 'state_id') ? 'state_id' : null,
                Schema::hasColumn('matrimony_profiles', 'district_id') ? 'district_id' : null,
                Schema::hasColumn('matrimony_profiles', 'taluka_id') ? 'taluka_id' : null,
                Schema::hasColumn('matrimony_profiles', 'city_id') ? 'city_id' : null,
                Schema::hasColumn('matrimony_profiles', 'birth_taluka_id') ? 'birth_taluka_id' : null,
                Schema::hasColumn('matrimony_profiles', 'birth_district_id') ? 'birth_district_id' : null,
                Schema::hasColumn('matrimony_profiles', 'birth_state_id') ? 'birth_state_id' : null,
            ]));
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('matrimony_profiles')) {
            return;
        }

        Schema::table('matrimony_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('matrimony_profiles', 'country_id')) {
                $table->unsignedBigInteger('country_id')->nullable();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'state_id')) {
                $table->unsignedBigInteger('state_id')->nullable();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'district_id')) {
                $table->unsignedBigInteger('district_id')->nullable();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'taluka_id')) {
                $table->unsignedBigInteger('taluka_id')->nullable();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'city_id')) {
                $table->unsignedBigInteger('city_id')->nullable();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'birth_taluka_id')) {
                $table->unsignedBigInteger('birth_taluka_id')->nullable();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'birth_district_id')) {
                $table->unsignedBigInteger('birth_district_id')->nullable();
            }
            if (! Schema::hasColumn('matrimony_profiles', 'birth_state_id')) {
                $table->unsignedBigInteger('birth_state_id')->nullable();
            }
        });

        if (Schema::hasTable('addresses')) {
            Schema::table('matrimony_profiles', function (Blueprint $table): void {
                foreach (['birth_taluka_id', 'birth_district_id', 'birth_state_id'] as $col) {
                    if (Schema::hasColumn('matrimony_profiles', $col)) {
                        try {
                            $table->foreign($col)->references('id')->on('addresses')->nullOnDelete();
                        } catch (\Throwable) {
                        }
                    }
                }
            });
        }
    }
};
