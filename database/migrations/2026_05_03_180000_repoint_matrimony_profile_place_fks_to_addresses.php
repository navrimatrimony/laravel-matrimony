<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Place columns (birth_*, native_*, work_*) must reference {@code addresses} only (geo SSOT).
 * Older DBs may still enforce FKs to legacy {@code cities} / {@code states} / … — saves then fail when the UI posts {@code addresses}.id.
 *
 * Important: {@code matrimony_profiles} may still have {@code country_id}/{@code state_id}/… pointing at parallel
 * legacy master tables. Adding a new FK on this table causes MySQL to re-check **all** FKs on the row;
 * orphan {@code country_id} → {@code countries} then blocks {@code ALTER TABLE}. We drop **every** FK on this
 * table that targets legacy geo tables, scrub orphans to {@code addresses} ids or null, then add FKs on the
 * canonical place columns only.
 *
 * Additive + corrective only: drop wrong FKs, null orphan IDs, add FK to addresses where missing.
 */
return new class extends Migration
{
    private const LEGACY_GEO_REF_TABLES = [
        'countries',
        'states',
        'cities',
        'districts',
        'talukas',
        'villages',
        'pincodes',
    ];

    /** Columns that must end up with FK → {@code addresses} (semantic names unchanged; PHASE-5). */
    private const GEO_COLUMNS = [
        'birth_city_id',
        'birth_taluka_id',
        'birth_district_id',
        'birth_state_id',
        'native_city_id',
        'native_taluka_id',
        'native_district_id',
        'native_state_id',
        'work_city_id',
        'work_state_id',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('matrimony_profiles') || ! Schema::hasTable('addresses')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        $columnsToScrub = $this->discoverLegacyGeoFkColumns();
        $this->dropLegacyGeoForeignKeysOnMatrimonyProfiles();

        foreach (array_values(array_unique(array_merge($columnsToScrub, self::GEO_COLUMNS))) as $column) {
            if (! Schema::hasColumn('matrimony_profiles', $column)) {
                continue;
            }
            $this->nullOrphanPlaceIds($column);
        }

        foreach (self::GEO_COLUMNS as $column) {
            if (! Schema::hasColumn('matrimony_profiles', $column)) {
                continue;
            }
            if ($this->columnHasForeignKeyToTable($column, 'addresses')) {
                continue;
            }
            try {
                Schema::table('matrimony_profiles', function (Blueprint $table) use ($column) {
                    $table->foreign($column)
                        ->references('id')
                        ->on('addresses')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty: reverting would re-break apps that post address IDs.
    }

    /**
     * Drop every FK on {@code matrimony_profiles} whose referenced table is a legacy parallel geo master.
     */
    private function dropLegacyGeoForeignKeysOnMatrimonyProfiles(): void
    {
        $db = DB::getDatabaseName();
        $placeholders = implode(',', array_fill(0, count(self::LEGACY_GEO_REF_TABLES), '?'));

        $rows = DB::select(
            "
            SELECT DISTINCT kcu.COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE kcu
            WHERE kcu.TABLE_SCHEMA = ?
              AND kcu.TABLE_NAME = 'matrimony_profiles'
              AND kcu.REFERENCED_TABLE_NAME IN ({$placeholders})
            ",
            array_merge([$db], self::LEGACY_GEO_REF_TABLES)
        );

        foreach ($rows as $row) {
            $col = $row->COLUMN_NAME ?? null;
            if ($col === null || ! Schema::hasColumn('matrimony_profiles', $col)) {
                continue;
            }
            try {
                Schema::table('matrimony_profiles', function (Blueprint $table) use ($col) {
                    $table->dropForeign([$col]);
                });
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Capture column names while legacy FK metadata still exists in information_schema.
     *
     * @return list<string>
     */
    private function discoverLegacyGeoFkColumns(): array
    {
        $db = DB::getDatabaseName();
        $placeholders = implode(',', array_fill(0, count(self::LEGACY_GEO_REF_TABLES), '?'));

        $fromDb = DB::select(
            "
            SELECT DISTINCT kcu.COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE kcu
            WHERE kcu.TABLE_SCHEMA = ?
              AND kcu.TABLE_NAME = 'matrimony_profiles'
              AND kcu.REFERENCED_TABLE_NAME IN ({$placeholders})
            ",
            array_merge([$db], self::LEGACY_GEO_REF_TABLES)
        );

        $cols = [];
        foreach ($fromDb as $row) {
            if (! empty($row->COLUMN_NAME)) {
                $cols[] = (string) $row->COLUMN_NAME;
            }
        }

        return $cols;
    }

    private function nullOrphanPlaceIds(string $column): void
    {
        if (! Schema::hasColumn('matrimony_profiles', $column)) {
            return;
        }

        DB::table('matrimony_profiles')
            ->whereNotNull($column)
            ->whereNotExists(function ($q) use ($column) {
                $q->select(DB::raw('1'))
                    ->from('addresses')
                    ->whereColumn('addresses.id', 'matrimony_profiles.'.$column);
            })
            ->update([$column => null]);
    }

    private function columnHasForeignKeyToTable(string $column, string $table): bool
    {
        $db = DB::getDatabaseName();
        $n = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $db)
            ->where('TABLE_NAME', 'matrimony_profiles')
            ->where('COLUMN_NAME', $column)
            ->where('REFERENCED_TABLE_NAME', $table)
            ->count();

        return $n > 0;
    }
};
