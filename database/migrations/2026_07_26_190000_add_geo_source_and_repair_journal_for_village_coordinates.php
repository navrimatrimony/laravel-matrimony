<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance for `addresses.lat`/`lng`, plus the journal that makes the village-coordinate repair
 * reversible.
 *
 * WHY
 * ---
 * Village lat/lng in `addresses` were geocoded BY NAME (LGD publishes no coordinates), so villages
 * sharing a name were handed each other's points — 44,853 Maharashtra villages hold only 10,220
 * distinct coordinates. {@see \App\Console\Commands\RepairVillageCoordinatesCommand} replaces the bad
 * ones from the India Post office directory, but only some rows can be repaired with confidence. Two
 * new homes are needed and neither has one already (checked against docs/FIELD-OWNERSHIP-MAP.md and
 * grepped across app/ + database/ — no geo_source / coord_source / prev_lat column exists):
 *
 *   1. `addresses.geo_source` — WHERE THE ROW'S CURRENT COORDINATE CAME FROM. This is a property of
 *      the coordinate itself, so it belongs on the same row, next to lat/lng. A consumer must be able
 *      to tell a surveyed-quality postal point from a whole-pincode approximation from an untouched
 *      legacy name-geocode without joining anything.
 *
 *   2. `address_geo_repairs` — the per-row DECISION JOURNAL of each repair run: the coordinate before
 *      the write, the coordinate after, how it was matched or why it was declined, and the batch that
 *      did it. This is the backup that makes the repair undoable (`--rollback=<batch>`) and the audit
 *      trail for "why does this village sit here?". It is history, not current state, which is why it
 *      is not folded into `addresses` — the two are not duplicates of each other.
 *
 * Both are additive. No existing column changes meaning, and nothing outside the new column/table is
 * written by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('addresses') && ! Schema::hasColumn('addresses', 'geo_source')) {
            Schema::table('addresses', function (Blueprint $table): void {
                // NULL = never assessed by the repair. Deliberately nullable rather than defaulted, so
                // "we have not looked at this row" stays distinguishable from "we looked and kept it".
                $table->string('geo_source', 32)->nullable()->after('lng')->index();
            });
        }

        if (! Schema::hasTable('address_geo_repairs')) {
            Schema::create('address_geo_repairs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('batch')->index();
                $table->foreignId('address_id')->constrained('addresses')->cascadeOnDelete();

                // The backup. Nullable because a village row may legitimately have had no coordinate.
                $table->decimal('old_lat', 10, 7)->nullable();
                $table->decimal('old_lng', 10, 7)->nullable();
                $table->string('old_geo_source', 32)->nullable();

                // NULL on a declined row — nothing was written.
                $table->decimal('new_lat', 10, 7)->nullable();
                $table->decimal('new_lng', 10, 7)->nullable();

                $table->string('decision', 40);          // applied / declined
                $table->string('match_type', 40);        // india_post_name_pincode | india_post_pincode_area | legacy_name_geocode
                $table->string('reason', 60)->nullable();// why declined, or which name tier matched
                $table->string('pincode', 10)->nullable();
                $table->decimal('moved_km', 8, 2)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['batch', 'decision']);
                $table->index('address_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('address_geo_repairs');

        if (Schema::hasTable('addresses') && Schema::hasColumn('addresses', 'geo_source')) {
            Schema::table('addresses', function (Blueprint $table): void {
                $table->dropIndex(['geo_source']);
                $table->dropColumn('geo_source');
            });
        }
    }
};
