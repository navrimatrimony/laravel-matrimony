<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Day-28: Bring ocr_correction_patterns to SSOT Day-27 compliance.
 * Adds: source (frequency_rule | ai_generalized), index on field_key.
 * Optionally: pattern_confidence decimal(3,2).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ocr_correction_patterns')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

            // Add source column (varchar for portability; app validates enum values).
            if (! Schema::hasColumn('ocr_correction_patterns', 'source')) {
                Schema::table('ocr_correction_patterns', function (Blueprint $table) use ($driver) {
                    if ($driver === 'mysql' || $driver === 'mariadb') {
                        $table->string('source', 32)->default('frequency_rule')->after('field_key');
                    } else {
                        $table->string('source', 32)->default('frequency_rule')->after('field_key');
                    }
                });
            }

            // Ensure wrong_pattern and corrected_value are text if SSOT requires (currently string).
            // SSOT Day-27: wrong_pattern text, corrected_value text - leave as string unless we need to change.
            // Add index on field_key if missing.
            Schema::table('ocr_correction_patterns', function (Blueprint $table) {
                try {
                    $table->index('field_key');
                } catch (\Throwable $e) {
                    // Index may already exist
                }
            });

            // Change pattern_confidence to decimal(3,2) if currently (5,2).
            if (Schema::hasColumn('ocr_correction_patterns', 'pattern_confidence')) {
                $driver = Schema::getConnection()->getDriverName();
                if ($driver === 'mysql' || $driver === 'mariadb') {
                    try {
                        DB::statement('ALTER TABLE ocr_correction_patterns MODIFY pattern_confidence DECIMAL(3,2) DEFAULT 0');
                    } catch (\Throwable $e) {
                        // Keep existing precision if alter fails
                    }
                } elseif ($driver === 'pgsql') {
                    try {
                        DB::statement('ALTER TABLE ocr_correction_patterns ALTER COLUMN pattern_confidence TYPE DECIMAL(3,2) USING LEAST(pattern_confidence, 9.99)');
                        DB::statement("ALTER TABLE ocr_correction_patterns ALTER COLUMN pattern_confidence SET DEFAULT 0");
                    } catch (\Throwable $e) {
                        // Keep existing
                    }
                }
                // SQLite: decimal(5,2) kept; app treats as 0.xx
            }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ocr_correction_patterns')) {
            return;
        }

        Schema::table('ocr_correction_patterns', function (Blueprint $table) {
            if (Schema::hasColumn('ocr_correction_patterns', 'source')) {
                $table->dropColumn('source');
            }
            try {
                $table->dropIndex(['field_key']);
            } catch (\Throwable $e) {
                //
            }
        });
    }
};
