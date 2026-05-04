<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot SSOT repair: any FK still pointing at parallel legacy geo tables
 * ({@code countries}, {@code states}, {@code cities}, …) is dropped and recreated toward {@code addresses}.
 *
 * Must run **before** {@see 2026_05_06_100000_drop_legacy_parallel_geo_master_tables} so referenced tables still exist.
 *
 * Safe to run multiple times (idempotent when already fixed).
 */
return new class extends Migration
{
    /** Legacy parallel master tables that must never be FK targets after SSOT migration. */
    private const LEGACY_GEO_REF_TABLES = [
        'countries',
        'states',
        'cities',
        'districts',
        'talukas',
        'villages',
        'pincodes',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('addresses')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        $db = DB::getDatabaseName();

        $fkRows = DB::select(
            '
            SELECT DISTINCT
                rc.CONSTRAINT_NAME,
                rc.TABLE_NAME,
                kcu.COLUMN_NAME,
                rc.REFERENCED_TABLE_NAME
            FROM information_schema.REFERENTIAL_CONSTRAINTS rc
            INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
                ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
                AND rc.TABLE_NAME = kcu.TABLE_NAME
                AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE rc.CONSTRAINT_SCHEMA = ?
                AND rc.REFERENCED_TABLE_NAME IN ('.$this->quotedPlaceholders(count(self::LEGACY_GEO_REF_TABLES)).')
                AND (
                    SELECT COUNT(*)
                    FROM information_schema.KEY_COLUMN_USAGE x
                    WHERE x.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
                        AND x.TABLE_NAME = rc.TABLE_NAME
                        AND x.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                        AND x.REFERENCED_TABLE_NAME IS NOT NULL
                ) = 1
            ',
            array_merge([$db], self::LEGACY_GEO_REF_TABLES)
        );

        $pairs = [];
        foreach ($fkRows as $row) {
            $table = $row->TABLE_NAME ?? null;
            $column = $row->COLUMN_NAME ?? null;
            if ($table === null || $column === null || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            $pairs[$table."\0".$column] = [$table, $column];
        }

        foreach ($pairs as [$table, $column]) {
            try {
                Schema::table($table, function (Blueprint $blueprint) use ($column) {
                    $blueprint->dropForeign([$column]);
                });
            } catch (\Throwable) {
            }
        }

        foreach ($pairs as [$table, $column]) {
            $this->scrubOrphans($table, $column);
            if ($this->columnReferencesTable($table, $column, 'addresses')) {
                continue;
            }
            $nullable = $this->columnIsNullable($table, $column);
            Schema::table($table, function (Blueprint $blueprint) use ($column, $nullable) {
                $fk = $blueprint->foreign($column)->references('id')->on('addresses');
                if ($nullable) {
                    $fk->nullOnDelete();
                } else {
                    $fk->cascadeOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        // Irreversible.
    }

    private function quotedPlaceholders(int $n): string
    {
        return implode(',', array_fill(0, $n, '?'));
    }

    private function scrubOrphans(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $nullable = $this->columnIsNullable($table, $column);

        $q = DB::table($table)
            ->whereNotNull($column)
            ->whereNotExists(function ($sub) use ($table, $column) {
                $sub->select(DB::raw('1'))
                    ->from('addresses')
                    ->whereColumn('addresses.id', $table.'.'.$column);
            });

        if ($nullable) {
            $q->update([$column => null]);
        } else {
            $q->delete();
        }
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        $db = DB::getDatabaseName();
        $row = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$db, $table, $column]
        );

        return ($row->IS_NULLABLE ?? 'NO') === 'YES';
    }

    private function columnReferencesTable(string $table, string $column, string $referencedTable): bool
    {
        $db = DB::getDatabaseName();

        $n = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $db)
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->where('REFERENCED_TABLE_NAME', $referencedTable)
            ->count();

        return $n > 0;
    }
};
