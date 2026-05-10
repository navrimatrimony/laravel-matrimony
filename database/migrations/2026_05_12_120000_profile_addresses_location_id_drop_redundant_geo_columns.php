<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single leaf FK to {@code addresses}: {@code profile_addresses.location_id} (renamed from {@code city_id}).
 * Drops denormalized hierarchy columns that duplicated {@code addresses} (always nullable in practice).
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'profile_addresses';
        if (! Schema::hasTable($t)) {
            return;
        }

        foreach (['village_id', 'country_id', 'state_id', 'district_id', 'taluka_id', 'city_id'] as $col) {
            if (! Schema::hasColumn($t, $col)) {
                continue;
            }
            Schema::table($t, function (Blueprint $table) use ($col): void {
                try {
                    $table->dropForeign([$col]);
                } catch (\Throwable) {
                }
            });
        }

        $dropCols = array_values(array_filter([
            Schema::hasColumn($t, 'village_id') ? 'village_id' : null,
            Schema::hasColumn($t, 'country_id') ? 'country_id' : null,
            Schema::hasColumn($t, 'state_id') ? 'state_id' : null,
            Schema::hasColumn($t, 'district_id') ? 'district_id' : null,
            Schema::hasColumn($t, 'taluka_id') ? 'taluka_id' : null,
        ]));
        if ($dropCols !== []) {
            Schema::table($t, function (Blueprint $table) use ($dropCols): void {
                $table->dropColumn($dropCols);
            });
        }

        if (Schema::hasColumn($t, 'city_id') && ! Schema::hasColumn($t, 'location_id')) {
            Schema::table($t, function (Blueprint $table): void {
                $table->renameColumn('city_id', 'location_id');
            });
        }

        if (Schema::hasTable('addresses') && Schema::hasColumn($t, 'location_id')) {
            Schema::table($t, function (Blueprint $table): void {
                try {
                    $table->foreign('location_id')->references('id')->on('addresses')->nullOnDelete();
                } catch (\Throwable) {
                }
            });
        }
    }

    public function down(): void
    {
        // Forward-only: restoring dropped FK columns + split hierarchy is unsafe without a data plan.
    }
};
