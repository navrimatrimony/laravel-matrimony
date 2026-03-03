<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ocr_correction_logs')) {
            return;
        }
        $conn = Schema::getConnection();
        $driver = $conn->getDriverName();
        $idxCorrectedBy = 'ocr_correction_logs_corrected_by_index';
        $idxIntakeField = 'ocr_correction_logs_biodata_intake_id_field_name_index';
        $indexExists = function (string $name) use ($conn, $driver) {
            if ($driver === 'sqlite') {
                $r = $conn->select("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'ocr_correction_logs' AND name = ?", [$name]);
                return count($r) > 0;
            }
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $r = $conn->select("SHOW INDEX FROM ocr_correction_logs WHERE Key_name = ?", [$name]);
                return count($r) > 0;
            }
            return false;
        };

        Schema::table('ocr_correction_logs', function (Blueprint $table) use ($idxCorrectedBy, $idxIntakeField, $indexExists) {
            if (Schema::hasColumn('ocr_correction_logs', 'corrected_by')) {
                try {
                    $table->foreign('corrected_by')->references('id')->on('users')->restrictOnDelete();
                } catch (\Throwable $e) {
                    // FK may already exist
                }
                if (! $indexExists($idxCorrectedBy)) {
                    $table->index('corrected_by');
                }
            }
            if (Schema::hasColumn('ocr_correction_logs', 'biodata_intake_id') && Schema::hasColumn('ocr_correction_logs', 'field_name') && ! $indexExists($idxIntakeField)) {
                $table->index(['biodata_intake_id', 'field_name']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('ocr_correction_logs', function (Blueprint $table) {

            $table->dropForeign(['corrected_by']);
            $table->dropIndex(['corrected_by']);
            $table->dropIndex(['biodata_intake_id', 'field_name']);
        });
    }
};