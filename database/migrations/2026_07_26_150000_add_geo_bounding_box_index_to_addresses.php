<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bounding-box index for the nearby-geography scan.
 *
 * {@see \App\Services\Location\LocationService::nearbyTalukasByCoordinate()} filters `addresses` on
 * `hierarchy` + `is_active` + a lat/lng bounding box, twice — once for talukas that carry their own
 * coordinate and once for the village-centroid self-join that covers talukas which do not. `addresses`
 * holds ~670k geocoded village rows and had no index on `lat`/`lng` at all, so both passes were full
 * scans. Measured on the dev dataset: the centroid aggregate ran in 2,615 ms without this index and
 * 60 ms with it, returning byte-identical rows.
 *
 * That scan sits behind {@see \App\Services\Matching\NearbyGeographyResolver}, which the matching
 * relaxation ladder consults once per distinct district in the candidate pool — so on a wide pool the
 * un-indexed version alone could account for tens of seconds of a single suggestions request.
 *
 * Index only: no column, data or constraint changes.
 */
return new class extends Migration
{
    private const INDEX = 'addresses_hierarchy_active_lat_lng_index';

    public function up(): void
    {
        if (! Schema::hasTable('addresses') || $this->indexExists()) {
            return;
        }

        // Column order mirrors the query's predicate order: both equality filters first, then the two
        // range-scanned coordinates.
        Schema::table('addresses', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->index(['hierarchy', 'is_active', 'lat', 'lng'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('addresses') || ! $this->indexExists()) {
            return;
        }

        Schema::table('addresses', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    private function indexExists(): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('type', 'index')
                ->where('name', self::INDEX)
                ->exists();
        }

        return collect(DB::select('SHOW INDEX FROM `addresses`'))
            ->contains(static fn ($row): bool => ($row->Key_name ?? null) === self::INDEX);
    }
};
