<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Day-28: Bring ocr_correction_logs to SSOT Day-26 compliance.
 * Zero data loss: corrected_by preserved in ocr_correction_logs_actor_archive.
 * No cascade deletes. No JSON. No partial transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // STEP 1: Create archive table to preserve corrected_by (zero data loss).
        if (! Schema::hasTable('ocr_correction_logs_actor_archive')) {
            Schema::create('ocr_correction_logs_actor_archive', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ocr_correction_log_id');
                $table->unsignedBigInteger('corrected_by');
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('ocr_correction_log_id')
                    ->references('id')
                    ->on('ocr_correction_logs')
                    ->restrictOnDelete();
                $table->foreign('corrected_by')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
                $table->index('corrected_by');
                $table->index('ocr_correction_log_id');
            });
        }

        // STEP 2: Move corrected_by data into archive.
        if (Schema::hasTable('ocr_correction_logs') && Schema::hasColumn('ocr_correction_logs', 'corrected_by')) {
            $rows = DB::table('ocr_correction_logs')->whereNotNull('corrected_by')->get(['id', 'corrected_by', 'created_at']);
            $inserts = $rows->map(fn ($row) => [
                'ocr_correction_log_id' => $row->id,
                'corrected_by' => $row->corrected_by,
                'created_at' => $row->created_at,
            ])->all();
            if ($inserts !== []) {
                DB::table('ocr_correction_logs_actor_archive')->insert($inserts);
            }
        }

        if ($driver === 'sqlite') {
            $this->upSqlite();
        } else {
            $this->upMysqlOrPgsql();
        }
    }

    private function upSqlite(): void
    {
        if (! Schema::hasTable('ocr_correction_logs')) {
            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement('ALTER TABLE ocr_correction_logs RENAME TO ocr_correction_logs__old');

        Schema::create('ocr_correction_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('intake_id');
            $table->string('field_key')->index();
            $table->text('original_value')->nullable();
            $table->text('corrected_value')->nullable();
            $table->decimal('ai_confidence_at_parse', 3, 2)->nullable();
            $table->unsignedInteger('snapshot_schema_version')->default(1);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('intake_id')
                ->references('id')
                ->on('biodata_intakes')
                ->restrictOnDelete();
        });

        DB::statement('INSERT INTO ocr_correction_logs (id, intake_id, field_key, original_value, corrected_value, ai_confidence_at_parse, snapshot_schema_version, created_at) SELECT id, biodata_intake_id, field_name, old_value, new_value, NULL, 1, created_at FROM ocr_correction_logs__old');
        DB::statement('DROP TABLE ocr_correction_logs__old');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    private function upMysqlOrPgsql(): void
    {
        if (! Schema::hasTable('ocr_correction_logs')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        // STEP 3: Drop corrected_by FK and column.
        if (Schema::hasColumn('ocr_correction_logs', 'corrected_by')) {
            try {
                Schema::table('ocr_correction_logs', function (Blueprint $table) {
                    $table->dropForeign(['corrected_by']);
                });
            } catch (\Throwable $e) {
                // FK name may vary
            }
            try {
                Schema::table('ocr_correction_logs', function (Blueprint $table) {
                    $table->dropIndex(['corrected_by']);
                });
            } catch (\Throwable $e) {
                // Index name may vary
            }
            Schema::table('ocr_correction_logs', function (Blueprint $table) {
                $table->dropColumn('corrected_by');
            });
        }

        // STEP 4: Rename columns (drop FK on biodata_intake_id first).
        try {
            Schema::table('ocr_correction_logs', function (Blueprint $table) {
                $table->dropForeign(['biodata_intake_id']);
            });
        } catch (\Throwable $e) {
            // May already be dropped or named differently
        }

        if (Schema::hasColumn('ocr_correction_logs', 'biodata_intake_id')) {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('ALTER TABLE ocr_correction_logs CHANGE biodata_intake_id intake_id BIGINT UNSIGNED NOT NULL');
            } else {
                DB::statement('ALTER TABLE ocr_correction_logs RENAME COLUMN biodata_intake_id TO intake_id');
            }
        }

        if (Schema::hasColumn('ocr_correction_logs', 'field_name')) {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('ALTER TABLE ocr_correction_logs CHANGE field_name field_key VARCHAR(255) NOT NULL');
            } else {
                DB::statement('ALTER TABLE ocr_correction_logs RENAME COLUMN field_name TO field_key');
            }
        }

        if (Schema::hasColumn('ocr_correction_logs', 'old_value')) {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('ALTER TABLE ocr_correction_logs CHANGE old_value original_value TEXT NULL');
            } else {
                DB::statement('ALTER TABLE ocr_correction_logs RENAME COLUMN old_value TO original_value');
            }
        }

        if (Schema::hasColumn('ocr_correction_logs', 'new_value')) {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('ALTER TABLE ocr_correction_logs CHANGE new_value corrected_value TEXT NULL');
            } else {
                DB::statement('ALTER TABLE ocr_correction_logs RENAME COLUMN new_value TO corrected_value');
            }
        }

        // STEP 5: Add missing columns.
        Schema::table('ocr_correction_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('ocr_correction_logs', 'ai_confidence_at_parse')) {
                $table->decimal('ai_confidence_at_parse', 3, 2)->nullable()->after('corrected_value');
            }
            if (! Schema::hasColumn('ocr_correction_logs', 'snapshot_schema_version')) {
                $table->unsignedInteger('snapshot_schema_version')->default(1)->after('ai_confidence_at_parse');
            }
        });

        // STEP 6: Ensure index on field_key (skip if already exists) and FK on intake_id (no cascade).
                // STEP 6: Ensure index on field_key (skip if already exists) and FK on intake_id (no cascade).

        $indexName = 'ocr_correction_logs_field_key_index';
        $hasIndex = false;

        try {
            $driver = DB::connection()->getDriverName();

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $rows = DB::select(
                    "SHOW INDEX FROM `ocr_correction_logs` WHERE Key_name = ?",
                    [$indexName]
                );
                $hasIndex = ! empty($rows);
            } elseif ($driver === 'pgsql') {
                $rows = DB::select(
                    "SELECT 1 FROM pg_indexes WHERE tablename = 'ocr_correction_logs' AND indexname = ? LIMIT 1",
                    [$indexName]
                );
                $hasIndex = ! empty($rows);
            }
        } catch (\Throwable $e) {
            // If index-check fails, we fall back to Schema try/catch below.
            $hasIndex = false;
        }

        if (! $hasIndex) {
            try {
                Schema::table('ocr_correction_logs', function (Blueprint $table) use ($indexName) {
                    $table->index('field_key', $indexName);
                });
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), 'Duplicate key') === false
                    && strpos($e->getMessage(), 'Duplicate') === false
                    && strpos($e->getMessage(), 'already exists') === false) {
                    throw $e;
                }
            }
        }
        try {
            Schema::table('ocr_correction_logs', function (Blueprint $table) {
                $table->foreign('intake_id')->references('id')->on('biodata_intakes')->restrictOnDelete();
            });
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'Duplicate') === false && strpos($e->getMessage(), 'already exists') === false) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        // Rollback not supported: SSOT compliance is one-way.
    }
};
