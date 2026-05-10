<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove duplicate geo columns (keep {@code lat}/{@code lng} when present) and optional city population.
 * Coordinates for nearest-city logic use {@code lat}/{@code lng} only going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('addresses')) {
            return;
        }

        if (! Schema::hasColumn('addresses', 'lat') && Schema::hasColumn('addresses', 'latitude')) {
            Schema::table('addresses', function (Blueprint $table): void {
                $table->decimal('lat', 10, 7)->nullable();
            });
        }
        if (! Schema::hasColumn('addresses', 'lng') && Schema::hasColumn('addresses', 'longitude')) {
            Schema::table('addresses', function (Blueprint $table): void {
                $table->decimal('lng', 10, 7)->nullable();
            });
        }

        if (Schema::hasColumn('addresses', 'lat') && Schema::hasColumn('addresses', 'latitude')) {
            DB::table('addresses')->whereNull('lat')->whereNotNull('latitude')->update([
                'lat' => DB::raw('latitude'),
            ]);
        }
        if (Schema::hasColumn('addresses', 'lng') && Schema::hasColumn('addresses', 'longitude')) {
            DB::table('addresses')->whereNull('lng')->whereNotNull('longitude')->update([
                'lng' => DB::raw('longitude'),
            ]);
        }

        Schema::table('addresses', function (Blueprint $table): void {
            $drops = [];
            foreach (['latitude', 'longitude', 'population'] as $col) {
                if (Schema::hasColumn('addresses', $col)) {
                    $drops[] = $col;
                }
            }
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('addresses')) {
            return;
        }

        Schema::table('addresses', function (Blueprint $table): void {
            if (! Schema::hasColumn('addresses', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable();
            }
            if (! Schema::hasColumn('addresses', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable();
            }
            if (! Schema::hasColumn('addresses', 'population')) {
                $table->unsignedBigInteger('population')->nullable();
            }
        });

        if (Schema::hasColumn('addresses', 'latitude') && Schema::hasColumn('addresses', 'lat')) {
            DB::table('addresses')->whereNull('latitude')->whereNotNull('lat')->update([
                'latitude' => DB::raw('lat'),
            ]);
        }
        if (Schema::hasColumn('addresses', 'longitude') && Schema::hasColumn('addresses', 'lng')) {
            DB::table('addresses')->whereNull('longitude')->whereNotNull('lng')->update([
                'longitude' => DB::raw('lng'),
            ]);
        }
    }
};
