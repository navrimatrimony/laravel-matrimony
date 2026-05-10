<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Canonical career occupation: {@code occupation_master_id} / {@code occupation_custom_id} only.
 * Backfills from legacy {@code profession_id}, {@code occupation_title}, then drops parallel columns.
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

    public function up(): void
    {
        $profiles = 'matrimony_profiles';
        if (! Schema::hasTable($profiles)) {
            return;
        }
        $hasProfCol = Schema::hasColumn($profiles, 'profession_id');
        $hasTitleCol = Schema::hasColumn($profiles, 'occupation_title');
        $hasWwCol = Schema::hasColumn($profiles, 'working_with_type_id');
        $hasMidCol = Schema::hasColumn($profiles, 'occupation_master_id');
        $hasCidCol = Schema::hasColumn($profiles, 'occupation_custom_id');
        $pr = Schema::hasTable('professions');
        $cust = Schema::hasTable('master_occupation_custom');

        if ((! $hasProfCol && ! $hasTitleCol) || ! ($hasMidCol && $hasCidCol && Schema::hasTable('master_occupations'))) {
            return;
        }

        /** @var array<string, ?int> $nameNormToMasterId */
        $nameNormToMasterId = [];

        DB::table($profiles)->orderBy('id')->chunkById(400, function ($rows) use ($profiles, $hasProfCol, $hasTitleCol, $pr, $hasMidCol, $hasCidCol, &$nameNormToMasterId, $cust): void {
            foreach ($rows as $row) {
                $pid = (int) $row->id;
                $uid = isset($row->user_id) && (int) $row->user_id > 0 ? (int) $row->user_id : null;

                $mid = $hasMidCol && isset($row->occupation_master_id) && (int) $row->occupation_master_id > 0
                    ? (int) $row->occupation_master_id
                    : null;
                $cid = $hasCidCol && isset($row->occupation_custom_id) && (int) $row->occupation_custom_id > 0
                    ? (int) $row->occupation_custom_id
                    : null;

                if ($mid !== null || $cid !== null) {
                    continue;
                }

                $resolveMaster = static function (string $normLabel) use (&$nameNormToMasterId): ?int {
                    if ($normLabel === '') {
                        return null;
                    }
                    if (! array_key_exists($normLabel, $nameNormToMasterId)) {
                        $found = DB::table('master_occupations')
                            ->whereRaw('LOWER(TRIM(name)) = ?', [$normLabel])
                            ->value('id');
                        $nameNormToMasterId[$normLabel] = $found !== null ? (int) $found : null;
                    }

                    return $nameNormToMasterId[$normLabel];
                };

                $resolvedMid = null;
                if ($hasProfCol && isset($row->profession_id) && (int) $row->profession_id > 0 && $pr) {
                    $profName = DB::table('professions')->where('id', (int) $row->profession_id)->value('name');
                    if ($profName !== null && trim((string) $profName) !== '') {
                        $resolvedMid = $resolveMaster(mb_strtolower(trim((string) $profName)));
                    }
                }

                if ($resolvedMid === null && $hasTitleCol && isset($row->occupation_title)) {
                    $ttl = trim((string) $row->occupation_title);
                    if ($ttl !== '') {
                        $resolvedMid = $resolveMaster(mb_strtolower($ttl));
                    }
                }

                if ($resolvedMid !== null && $resolvedMid > 0) {
                    DB::table($profiles)->where('id', $pid)->update([
                        'occupation_master_id' => $resolvedMid,
                        'occupation_custom_id' => null,
                        'updated_at' => now(),
                    ]);

                    continue;
                }

                $label = '';
                if ($hasTitleCol && isset($row->occupation_title) && trim((string) $row->occupation_title) !== '') {
                    $label = mb_substr(trim((string) $row->occupation_title), 0, 160);
                } elseif ($hasProfCol && isset($row->profession_id) && (int) $row->profession_id > 0 && $pr) {
                    $pn = DB::table('professions')->where('id', (int) $row->profession_id)->value('name');
                    $label = $pn !== null ? mb_substr(trim((string) $pn), 0, 160) : '';
                }

                if ($label !== '' && $uid !== null && $cust && mb_strlen($label) >= 2) {
                    $norm = Str::lower(preg_replace('/\s+/u', ' ', $label) ?? '');
                    $existingCustom = DB::table('master_occupation_custom')
                        ->where('user_id', $uid)
                        ->where('normalized_name', $norm)
                        ->value('id');
                    if ($existingCustom === null) {
                        $existingCustom = DB::table('master_occupation_custom')->insertGetId([
                            'raw_name' => $label,
                            'normalized_name' => $norm,
                            'user_id' => $uid,
                            'status' => 'pending',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    DB::table($profiles)->where('id', $pid)->update([
                        'occupation_master_id' => null,
                        'occupation_custom_id' => (int) $existingCustom,
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        foreach (['working_with_type_id', 'profession_id'] as $fkCol) {
            if (Schema::hasColumn($profiles, $fkCol)) {
                $this->dropForeignKeyOnColumnIfExists($profiles, $fkCol);
            }
        }

        Schema::table($profiles, function (Blueprint $table) use ($profiles, $hasWwCol, $hasProfCol, $hasTitleCol): void {
            $drop = [];
            if ($hasWwCol && Schema::hasColumn($profiles, 'working_with_type_id')) {
                $drop[] = 'working_with_type_id';
            }
            if ($hasProfCol && Schema::hasColumn($profiles, 'profession_id')) {
                $drop[] = 'profession_id';
            }
            if ($hasTitleCol && Schema::hasColumn($profiles, 'occupation_title')) {
                $drop[] = 'occupation_title';
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        if (Schema::hasTable('field_registry') && Schema::hasColumn('field_registry', 'is_archived')) {
            DB::table('field_registry')->whereIn('field_key', [
                'occupation_title',
                'profession_id',
                'working_with_type_id',
            ])->update(['is_archived' => true, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $profiles = 'matrimony_profiles';
        if (! Schema::hasTable($profiles)) {
            return;
        }
        Schema::table($profiles, function (Blueprint $table) use ($profiles): void {
            if (! Schema::hasColumn($profiles, 'occupation_title')) {
                $table->string('occupation_title', 255)->nullable()->after('highest_education');
            }
            if (! Schema::hasColumn($profiles, 'working_with_type_id')) {
                $table->foreignId('working_with_type_id')->nullable()->after('occupation_title')
                    ->constrained('working_with_types')->nullOnDelete();
            }
            if (! Schema::hasColumn($profiles, 'profession_id')) {
                $table->foreignId('profession_id')->nullable()->after('working_with_type_id')
                    ->constrained('professions')->nullOnDelete();
            }
        });

        if (Schema::hasTable('field_registry') && Schema::hasColumn('field_registry', 'is_archived')) {
            DB::table('field_registry')->whereIn('field_key', [
                'occupation_title',
                'profession_id',
                'working_with_type_id',
            ])->update(['is_archived' => false, 'updated_at' => now()]);
        }
    }
};
