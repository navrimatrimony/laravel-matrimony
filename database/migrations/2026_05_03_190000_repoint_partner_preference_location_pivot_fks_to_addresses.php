<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partner preference location pivots store {@code addresses}.id (same as {@see Location}).
 * Some databases still enforce FKs to legacy {@code countries} / {@code states} / … — inserts then fail.
 */
return new class extends Migration
{
    /** [ pivot_table, fk_column ] */
    private const PIVOTS = [
        ['profile_preferred_countries', 'country_id'],
        ['profile_preferred_states', 'state_id'],
        ['profile_preferred_talukas', 'taluka_id'],
        ['profile_preferred_districts', 'district_id'],
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

        foreach (self::PIVOTS as [$pivotTable, $column]) {
            if (! Schema::hasTable($pivotTable) || ! Schema::hasColumn($pivotTable, $column)) {
                continue;
            }
            $this->dropForeignKeysNotReferencingAddresses($pivotTable, $column);
            $this->deleteOrphanPivotRows($pivotTable, $column);
            if ($this->columnHasForeignKeyToTable($pivotTable, $column, 'addresses')) {
                continue;
            }
            Schema::table($pivotTable, function (Blueprint $table) use ($column) {
                $table->foreign($column)
                    ->references('id')
                    ->on('addresses')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty.
    }

    private function dropForeignKeysNotReferencingAddresses(string $pivotTable, string $allowedColumn): void
    {
        $db = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT DISTINCT kcu.COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE kcu
             WHERE kcu.TABLE_SCHEMA = ?
               AND kcu.TABLE_NAME = ?
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
               AND kcu.REFERENCED_TABLE_NAME <> ?',
            [$db, $pivotTable, 'addresses']
        );

        foreach ($rows as $row) {
            $col = $row->COLUMN_NAME ?? null;
            if ($col !== $allowedColumn) {
                continue;
            }
            try {
                Schema::table($pivotTable, function (Blueprint $table) use ($col) {
                    $table->dropForeign([$col]);
                });
            } catch (\Throwable) {
            }
        }
    }

    /** Remove pivot rows whose FK target is missing from {@code addresses}. */
    private function deleteOrphanPivotRows(string $pivotTable, string $column): void
    {
        DB::table($pivotTable)
            ->whereNotNull($column)
            ->whereNotExists(function ($q) use ($pivotTable, $column) {
                $q->select(DB::raw('1'))
                    ->from('addresses')
                    ->whereColumn('addresses.id', $pivotTable.'.'.$column);
            })
            ->delete();
    }

    private function columnHasForeignKeyToTable(string $pivotTable, string $column, string $refTable): bool
    {
        $db = DB::getDatabaseName();
        $n = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $db)
            ->where('TABLE_NAME', $pivotTable)
            ->where('COLUMN_NAME', $column)
            ->where('REFERENCED_TABLE_NAME', $refTable)
            ->count();

        return $n > 0;
    }
};
