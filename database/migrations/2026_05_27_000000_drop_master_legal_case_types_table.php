<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGAL_CASES_TABLE = 'profile_legal_cases';
    private const MASTER_TABLE = 'master_legal_case_types';

    public function up(): void
    {
        if (Schema::hasTable(self::LEGAL_CASES_TABLE)) {
            if (Schema::hasColumn(self::LEGAL_CASES_TABLE, 'legal_case_type_id')) {
                Schema::table(self::LEGAL_CASES_TABLE, function (Blueprint $table) {
                    $foreignKey = self::LEGAL_CASES_TABLE . '_legal_case_type_id_foreign';
                    if ($this->foreignKeyExists(self::LEGAL_CASES_TABLE, $foreignKey)) {
                        $table->dropForeign($foreignKey);
                    }

                    if ($this->indexExists(self::LEGAL_CASES_TABLE, self::LEGAL_CASES_TABLE . '_legal_case_type_id_index')) {
                        $table->dropIndex(self::LEGAL_CASES_TABLE . '_legal_case_type_id_index');
                    }

                    $table->dropColumn('legal_case_type_id');
                });
            }
        }

        Schema::dropIfExists(self::MASTER_TABLE);
    }

    public function down(): void
    {
        // Intentionally irreversible: PHASE-5 SSOT does not require the
        // master_legal_case_types lookup table or legal case type fields.
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        return count($connection->select(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
                AND CONSTRAINT_TYPE = ?
                AND CONSTRAINT_NAME = ?',
            [$database, $table, 'FOREIGN KEY', $name]
        )) > 0;
    }

    private function indexExists(string $table, string $name): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        return count($connection->select(
            'SELECT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
                AND INDEX_NAME = ?',
            [$database, $table, $name]
        )) > 0;
    }
};
