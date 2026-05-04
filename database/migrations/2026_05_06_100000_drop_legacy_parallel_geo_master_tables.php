<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops obsolete parallel master tables that duplicated geographic hierarchy.
 * Canonical hierarchy is {@see addresses} only (SSOT). Safe when nothing references these tables.
 *
 * MySQL: disables FK checks briefly; other drivers skip (tests/SQLite use addresses-only).
 */
return new class extends Migration
{
    /** @var list<string> Child-most first — typical legacy FK chains */
    private const LEGACY_GEO_TABLES = [
        'villages',
        'cities',
        'pincodes',
        'talukas',
        'districts',
        'states',
        'countries',
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (self::LEGACY_GEO_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    Schema::drop($table);
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function down(): void
    {
        // Irreversible: legacy parallel tables are intentionally removed.
    }
};
