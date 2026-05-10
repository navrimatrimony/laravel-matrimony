<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Residence SSOT: self + address type "current" in {@code profile_addresses} only.
 * Migrates legacy {@code matrimony_profiles.location_id} and {@code address_line}, then drops those columns.
 *
 * User mandate: remove parallel profile-table residence storage to avoid confusion (overrides PHASE-5 "no column drop" for this pair only).
 */
return new class extends Migration
{
    /** MySQL FK names vary (old migrations / manual DB); {@see Blueprint::dropForeign} inside a closure still throws at execute time. */
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
            // SQLite / missing FK definition: skip
        }
    }

    /**
     * After DROP FOREIGN KEY, InnoDB may leave a single-column index on {@code $column}; drop it so DROP COLUMN succeeds.
     *
     * @param  string  $column  only non-composite indexes wholly on this column are safe; typical for {@code location_id}.
     */
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

    public function up(): void
    {
        if (! Schema::hasTable('matrimony_profiles') || ! Schema::hasTable('profile_addresses')) {
            return;
        }

        if (Schema::hasTable('master_address_types') && ! DB::table('master_address_types')->where('key', 'current')->exists()) {
            $row = [
                'key' => 'current',
                'label' => 'Current',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('master_address_types', 'label_mr')) {
                $row['label_mr'] = 'सध्याचे';
            }
            DB::table('master_address_types')->insert($row);
        }

        $typeId = DB::table('master_address_types')->where('key', 'current')->value('id');
        if ($typeId === null) {
            return;
        }
        $typeId = (int) $typeId;

        $hasLocationColumn = Schema::hasColumn('matrimony_profiles', 'location_id');
        $hasLineColumn = Schema::hasColumn('matrimony_profiles', 'address_line');

        if (! $hasLocationColumn && ! $hasLineColumn) {
            return;
        }

        $leafCol = Schema::hasColumn('profile_addresses', 'location_id') ? 'location_id' : 'city_id';

        DB::table('matrimony_profiles')->orderBy('id')->chunkById(500, function ($profiles) use ($typeId, $hasLocationColumn, $hasLineColumn, $leafCol): void {
            foreach ($profiles as $p) {
                $profileId = (int) $p->id;
                $legacyCity = $hasLocationColumn && isset($p->location_id) && (int) $p->location_id > 0
                    ? (int) $p->location_id
                    : null;
                $legacyLine = null;
                if ($hasLineColumn && isset($p->address_line)) {
                    $t = trim((string) $p->address_line);
                    $legacyLine = $t !== '' ? mb_substr($t, 0, 255) : null;
                }

                if ($legacyCity === null && $legacyLine === null) {
                    continue;
                }

                $existing = DB::table('profile_addresses')
                    ->where('profile_id', $profileId)
                    ->where('address_scope', 'self')
                    ->where('address_type_id', $typeId)
                    ->first();

                $now = now();
                if ($existing) {
                    $upd = ['updated_at' => $now];
                    if ($legacyCity !== null) {
                        $upd[$leafCol] = $legacyCity;
                    }
                    if ($legacyLine !== null) {
                        $upd['address_line'] = $legacyLine;
                    }
                    if (count($upd) > 1) {
                        DB::table('profile_addresses')->where('id', $existing->id)->update($upd);
                    }

                    continue;
                }

                $insert = [
                    'profile_id' => $profileId,
                    'address_scope' => 'self',
                    'address_type_id' => $typeId,
                    'address_line' => $legacyLine,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $insert[$leafCol] = $legacyCity;
                DB::table('profile_addresses')->insert($insert);
            }
        });

        $tbl = 'matrimony_profiles';
        if (Schema::hasColumn($tbl, 'location_id')) {
            $this->dropForeignKeyOnColumnIfExists($tbl, 'location_id');
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $this->dropMysqlNonPrimaryIndexesOnColumn($tbl, 'location_id');
            } else {
                foreach ([$tbl.'_location_id_index', 'matrimony_profiles_location_id_index'] as $idx) {
                    try {
                        Schema::table($tbl, function (Blueprint $blueprint) use ($idx): void {
                            $blueprint->dropIndex($idx);
                        });
                    } catch (\Throwable) {
                    }
                }
                try {
                    Schema::table($tbl, function (Blueprint $blueprint): void {
                        $blueprint->dropIndex(['location_id']);
                    });
                } catch (\Throwable) {
                }
            }
        }

        Schema::table($tbl, function (Blueprint $table) use ($tbl): void {
            $drops = array_values(array_filter([
                Schema::hasColumn($tbl, 'location_id') ? 'location_id' : null,
                Schema::hasColumn($tbl, 'address_line') ? 'address_line' : null,
            ]));
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
            if (! Schema::hasColumn($tbl, 'location_id')) {
                $table->unsignedBigInteger('location_id')->nullable()->after('city_id');
            }
            if (! Schema::hasColumn($tbl, 'address_line')) {
                $table->string('address_line', 255)->nullable()->after('city_id');
            }
        });

        if (Schema::hasTable('addresses') && Schema::hasColumn($tbl, 'location_id')) {
            Schema::table($tbl, function (Blueprint $table): void {
                try {
                    $table->foreign('location_id')->references('id')->on('addresses')->nullOnDelete();
                } catch (\Throwable) {
                }
            });
        }
    }
};
