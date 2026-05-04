<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reliable SSOT repair: finds FK columns via {@code KEY_COLUMN_USAGE}, drops legacy targets,
 * scrubs orphans, adds FK to {@code addresses}. Includes explicit drops for common Laravel
 * constraint names (e.g. {@code profile_preferred_countries_country_id_foreign → countries}).
 */
return new class extends Migration
{
    private const LEGACY_GEO_REF = [
        'countries',
        'states',
        'cities',
        'districts',
        'talukas',
        'villages',
        'pincodes',
    ];

    private const KNOWN_PIVOT_FK_NAMES = [
        'profile_preferred_countries' => [
            'constraint' => 'profile_preferred_countries_country_id_foreign',
            'column' => 'country_id',
        ],
        'profile_preferred_states' => [
            'constraint' => 'profile_preferred_states_state_id_foreign',
            'column' => 'state_id',
        ],
        'profile_preferred_talukas' => [
            'constraint' => 'profile_preferred_talukas_taluka_id_foreign',
            'column' => 'taluka_id',
        ],
        'profile_preferred_districts' => [
            'constraint' => 'profile_preferred_districts_district_id_foreign',
            'column' => 'district_id',
        ],
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
        $inList = implode(',', array_fill(0, count(self::LEGACY_GEO_REF), '?'));

        $rows = DB::select(
            "
            SELECT
                kcu.CONSTRAINT_NAME,
                kcu.TABLE_NAME,
                kcu.COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE kcu
            WHERE kcu.TABLE_SCHEMA = ?
                AND kcu.REFERENCED_TABLE_NAME IN ({$inList})
            ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
            ",
            array_merge([$db], self::LEGACY_GEO_REF)
        );

        $byConstraint = [];
        foreach ($rows as $row) {
            $c = $row->CONSTRAINT_NAME ?? '';
            if ($c === '') {
                continue;
            }
            $byConstraint[$c][] = $row;
        }

        $pairs = [];
        foreach ($byConstraint as $list) {
            if (count($list) !== 1) {
                continue;
            }
            $r = $list[0];
            $table = $r->TABLE_NAME ?? null;
            $column = $r->COLUMN_NAME ?? null;
            if ($table === null || $column === null || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            $pairs[$table."\0".$column] = [$table, $column];
        }

        foreach (self::KNOWN_PIVOT_FK_NAMES as $table => $meta) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $meta['column'])) {
                continue;
            }
            $pairs[$table."\0".$meta['column']] = [$table, $meta['column']];
        }

        foreach (self::KNOWN_PIVOT_FK_NAMES as $table => $meta) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $this->dropForeignKeyByName($table, $meta['constraint']);
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
            $this->addFkToAddresses($table, $column);
        }
    }

    public function down(): void
    {
        // Irreversible.
    }

    private function dropForeignKeyByName(string $table, string $constraintName): void
    {
        $db = DB::getDatabaseName();
        $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', $db)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraintName)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();

        if (! $exists) {
            return;
        }

        try {
            DB::statement('ALTER TABLE `'.$table.'` DROP FOREIGN KEY `'.$constraintName.'`');
        } catch (\Throwable) {
        }
    }

    private function addFkToAddresses(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        if ($this->columnReferencesTable($table, $column, 'addresses')) {
            return;
        }
        $nullable = $this->columnIsNullable($table, $column);
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $nullable) {
                $fk = $blueprint->foreign($column)->references('id')->on('addresses');
                if ($nullable) {
                    $fk->nullOnDelete();
                } else {
                    $fk->cascadeOnDelete();
                }
            });
        } catch (\Throwable) {
        }
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

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $db)
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->where('REFERENCED_TABLE_NAME', $referencedTable)
            ->exists();
    }
};
