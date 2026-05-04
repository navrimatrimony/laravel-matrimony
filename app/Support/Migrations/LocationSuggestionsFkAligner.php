<?php

namespace App\Support\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-point {@code location_suggestions} hierarchy FKs to {@code addresses} (SSOT).
 * Legacy parallel tables ({@code states}, …) are dropped by migration; this only fixes FKs + orphan rows.
 */
final class LocationSuggestionsFkAligner
{
    private const HIERARCHY_COLUMNS = ['country_id', 'state_id', 'district_id', 'taluka_id'];

    public static function alignToAddresses(): void
    {
        if (! Schema::hasTable('location_suggestions') || ! Schema::hasTable('addresses')) {
            return;
        }

        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        $db = $connection->getDatabaseName();

        self::dropHierarchyForeignKeys($connection, $db);
        self::deleteRowsWithInvalidHierarchyIds($connection);
        self::addHierarchyForeignKeys();
    }

    private static function dropHierarchyForeignKeys($connection, string $db): void
    {
        foreach (self::HIERARCHY_COLUMNS as $column) {
            $row = $connection->selectOne(
                'SELECT CONSTRAINT_NAME
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                   AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$db, 'location_suggestions', $column]
            );

            if ($row) {
                $connection->statement(
                    'ALTER TABLE location_suggestions DROP FOREIGN KEY `'.$row->CONSTRAINT_NAME.'`'
                );
            }
        }
    }

    /**
     * Remove rows that do not reference valid {@code addresses} hierarchy IDs.
     */
    private static function deleteRowsWithInvalidHierarchyIds($connection): void
    {
        $connection->statement(
            'DELETE ls FROM location_suggestions ls
             LEFT JOIN addresses ac ON ac.id = ls.country_id AND ac.type = \'country\'
             LEFT JOIN addresses ast ON ast.id = ls.state_id AND ast.type = \'state\'
             LEFT JOIN addresses ad ON ad.id = ls.district_id AND ad.type = \'district\'
             LEFT JOIN addresses at ON at.id = ls.taluka_id AND at.type = \'taluka\'
             WHERE ac.id IS NULL OR ast.id IS NULL OR ad.id IS NULL OR at.id IS NULL'
        );
    }

    private static function addHierarchyForeignKeys(): void
    {
        $connection = Schema::getConnection();
        $db = $connection->getDatabaseName();

        foreach (self::HIERARCHY_COLUMNS as $column) {
            $row = $connection->selectOne(
                'SELECT CONSTRAINT_NAME
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                   AND REFERENCED_TABLE_NAME = \'addresses\'',
                [$db, 'location_suggestions', $column]
            );

            if ($row) {
                continue;
            }

            Schema::table('location_suggestions', function (Blueprint $table) use ($column) {
                $table->foreign($column)->references('id')->on('addresses')->restrictOnDelete();
            });
        }
    }
}
