<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Native / work hierarchy and parent contact slot 3 lived on {@code matrimony_profiles} in parallel with
 * typed {@code profile_addresses} rows (self + native / work). Drops legacy columns after backfill.
 *
 * User mandate: single address story — same exception to PHASE-5 "no column drop" as residence cleanup.
 */
return new class extends Migration
{
    private function dropForeignKeyOnColumnIfExists(string $table, string $column): void
    {
        $conn = Schema::getConnection();
        $driver = $conn->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $db = $conn->getDatabaseName();
            $rows = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
                 AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$db, $table, $column]
            );
            foreach ($rows as $row) {
                $name = (string) ($row->CONSTRAINT_NAME ?? '');
                if ($name === '') {
                    continue;
                }
                try {
                    DB::statement('ALTER TABLE `'.$table.'` DROP FOREIGN KEY `'.$name.'`');
                } catch (\Throwable) {
                }
            }

            return;
        }

        if (! Schema::hasColumn($table, $column)) {
            return;
        }
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropForeign([$column]);
            });
        } catch (\Throwable) {
        }
    }

    private function dropMysqlNonPrimaryIndexesOnColumn(string $table, string $column): void
    {
        $conn = Schema::getConnection();
        if (! in_array($conn->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $db = $conn->getDatabaseName();
        $candidates = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $db)
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->where('INDEX_NAME', '!=', 'PRIMARY')
            ->distinct()
            ->pluck('INDEX_NAME');
        foreach ($candidates as $indexName) {
            $name = (string) $indexName;
            if ($name === '') {
                continue;
            }
            $colCount = DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', $db)
                ->where('TABLE_NAME', $table)
                ->where('INDEX_NAME', $name)
                ->count();
            if ($colCount !== 1) {
                continue;
            }
            try {
                DB::statement('ALTER TABLE `'.$table.'` DROP INDEX `'.$name.'`');
            } catch (\Throwable) {
            }
        }
    }

    private function tryDropIndexByColumn(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropIndex([$column]);
            });
        } catch (\Throwable) {
        }
        foreach ([$table.'_'.$column.'_index', 'matrimony_profiles_'.$column.'_index'] as $idx) {
            try {
                Schema::table($table, function (Blueprint $blueprint) use ($idx): void {
                    $blueprint->dropIndex($idx);
                });
            } catch (\Throwable) {
            }
        }
    }

    public function up(): void
    {
        $tbl = 'matrimony_profiles';
        if (! Schema::hasTable($tbl)) {
            return;
        }

        foreach (['native', 'work'] as $atype) {
            if (! Schema::hasTable('master_address_types') || ! Schema::hasTable('profile_addresses')) {
                break;
            }
            if (! DB::table('master_address_types')->where('key', $atype)->exists()) {
                continue;
            }
            $typeId = (int) DB::table('master_address_types')->where('key', $atype)->value('id');
            $leafColPa = Schema::hasColumn('profile_addresses', 'location_id') ? 'location_id' : 'city_id';

            $sourceCol = $atype === 'native' ? 'native_city_id' : 'work_city_id';
            if (! Schema::hasColumn($tbl, $sourceCol)) {
                continue;
            }

            DB::table($tbl)->orderBy('id')->chunkById(500, function ($profiles) use ($tbl, $typeId, $leafColPa, $sourceCol): void {
                foreach ($profiles as $p) {
                    $pid = (int) $p->id;
                    $leaf = isset($p->{$sourceCol}) && (int) $p->{$sourceCol} > 0 ? (int) $p->{$sourceCol} : null;
                    if ($leaf === null) {
                        continue;
                    }
                    $existing = DB::table('profile_addresses')
                        ->where('profile_id', $pid)
                        ->where('address_scope', 'self')
                        ->where('address_type_id', $typeId)
                        ->first();
                    $now = now();
                    if ($existing) {
                        DB::table('profile_addresses')->where('id', $existing->id)->update([
                            $leafColPa => $leaf,
                            'updated_at' => $now,
                        ]);

                        continue;
                    }
                    DB::table('profile_addresses')->insert([
                        'profile_id' => $pid,
                        'address_scope' => 'self',
                        'address_type_id' => $typeId,
                        'address_line' => null,
                        $leafColPa => $leaf,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
        }

        if (Schema::hasTable('field_registry')) {
            if (Schema::hasColumn('field_registry', 'is_archived')) {
                DB::table('field_registry')
                    ->whereIn('field_key', ['work_city_id', 'work_state_id'])
                    ->update(['is_archived' => true, 'updated_at' => now()]);
            }
        }

        $colsToDrop = [
            'work_city_id',
            'work_state_id',
            'native_city_id',
            'native_taluka_id',
            'native_district_id',
            'native_state_id',
            'father_contact_3',
            'mother_contact_3',
        ];

        foreach ($colsToDrop as $col) {
            if (! Schema::hasColumn($tbl, $col)) {
                continue;
            }
            $this->dropForeignKeyOnColumnIfExists($tbl, $col);
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $this->dropMysqlNonPrimaryIndexesOnColumn($tbl, $col);
            } else {
                $this->tryDropIndexByColumn($tbl, $col);
            }
        }

        Schema::table($tbl, function (Blueprint $table) use ($tbl, $colsToDrop): void {
            $drops = array_values(array_filter(array_map(
                fn (string $c) => Schema::hasColumn($tbl, $c) ? $c : null,
                $colsToDrop
            )));
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }

    public function down(): void
    {
        $tbl = 'matrimony_profiles';
        if (! Schema::hasTable($tbl)) {
            return;
        }

        Schema::table($tbl, function (Blueprint $table) use ($tbl): void {
            foreach (['work_city_id', 'native_city_id', 'native_taluka_id', 'native_district_id', 'native_state_id'] as $c) {
                if (! Schema::hasColumn($tbl, $c)) {
                    $table->unsignedBigInteger($c)->nullable();
                }
            }
            if (! Schema::hasColumn($tbl, 'work_state_id')) {
                $table->unsignedBigInteger('work_state_id')->nullable();
            }
            if (! Schema::hasColumn($tbl, 'father_contact_3')) {
                $table->string('father_contact_3', 20)->nullable();
            }
            if (! Schema::hasColumn($tbl, 'mother_contact_3')) {
                $table->string('mother_contact_3', 20)->nullable();
            }
        });

        if (Schema::hasTable('addresses')) {
            Schema::table($tbl, function (Blueprint $table) use ($tbl): void {
                foreach (['work_city_id', 'work_state_id', 'native_city_id', 'native_taluka_id', 'native_district_id', 'native_state_id'] as $col) {
                    if (Schema::hasColumn($tbl, $col)) {
                        try {
                            $table->foreign($col)->references('id')->on('addresses')->nullOnDelete();
                        } catch (\Throwable) {
                        }
                    }
                }
            });
        }

        if (Schema::hasTable('field_registry') && Schema::hasColumn('field_registry', 'is_archived')) {
            DB::table('field_registry')
                ->whereIn('field_key', ['work_city_id', 'work_state_id'])
                ->update(['is_archived' => false, 'updated_at' => now()]);
        }
    }
};
