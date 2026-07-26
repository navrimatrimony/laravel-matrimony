<?php

use App\Services\Location\GeoCentroidBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Fill the EXISTING `addresses.lat` / `addresses.lng` on Maharashtra's taluka and district rows.
 *
 * `addresses` carries these columns at every hierarchy level, but only villages were ever geocoded,
 * leaving every taluka and district empty. Consumers that needed a taluka's position therefore
 * re-derived it at request time with an AVG over the village rows — measured on production at
 * 16,081 ms per suggestions request, 73% of the total, to recompute a geographic constant 63 times.
 *
 * The coordinate is a property of the address row, so it is stored on the address row: no new column,
 * no side table, no cache key (frozen no-duplicate rule), and therefore no cold-start stall after a
 * `cache:clear` / `optimize:clear`.
 *
 * Scoped to Maharashtra (owner decision): 358 talukas + 35 districts rather than 7,854 rows
 * nationwide. Out-of-state rows keep a NULL coordinate on purpose and fall back to the plain hierarchy
 * rule — see {@see \App\Services\Matching\NearbyGeographyResolver}. The state is resolved through
 * `addresses.slug`, never a hardcoded id.
 *
 * The formulas, the state bounds and the acceptance gate live in {@see GeoCentroidBackfillService}
 * because the maintenance command `locations:backfill-geo-centroids` must use the identical ones;
 * duplicating them here is exactly the drift this codebase forbids.
 *
 * IDEMPOTENT: only rows whose lat/lng are NULL are touched, so a re-run (or a replay on a database
 * where the command has already run) changes nothing and never overwrites a corrected coordinate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('addresses')
            || ! Schema::hasColumn('addresses', 'lat')
            || ! Schema::hasColumn('addresses', 'lng')
            || ! class_exists(GeoCentroidBackfillService::class)) {
            return;
        }

        $service = new GeoCentroidBackfillService;

        foreach (GeoCentroidBackfillService::supportedStates() as $stateSlug) {
            // DISTRICTS FIRST: a district centre is the reference point for the taluka acceptance
            // check. (The service recomputes it from the villages either way, so the order is for the
            // reader, not for correctness.)
            $service->backfillDistricts(false, $stateSlug);
            $service->backfillTalukas(false, $stateSlug);
        }
    }

    public function down(): void
    {
        // Deliberately irreversible. The pre-migration state was "NULL for every taluka and district",
        // and blanking the columns again would also blank any coordinate an operator has since set by
        // hand or via `locations:backfill-geo-centroids`. Re-running `up()` is a no-op, so nothing is
        // gained by clearing them.
    }
};
