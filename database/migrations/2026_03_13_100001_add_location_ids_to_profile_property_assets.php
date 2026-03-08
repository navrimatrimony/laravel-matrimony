<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5 additive: profile_property_assets — city_id, taluka_id, district_id, state_id
 * for centralized location-typeahead. Existing column "location" (string) unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'profile_property_assets';
        if (! Schema::hasTable($t)) {
            return;
        }

        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (! Schema::hasColumn($t, 'city_id')) {
                $schema->unsignedBigInteger('city_id')->nullable()->after('location');
            }
            if (! Schema::hasColumn($t, 'taluka_id')) {
                $schema->unsignedBigInteger('taluka_id')->nullable()->after('city_id');
            }
            if (! Schema::hasColumn($t, 'district_id')) {
                $schema->unsignedBigInteger('district_id')->nullable()->after('taluka_id');
            }
            if (! Schema::hasColumn($t, 'state_id')) {
                $schema->unsignedBigInteger('state_id')->nullable()->after('district_id');
            }
        });

        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (Schema::hasColumn($t, 'city_id')) {
                $schema->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
            }
            if (Schema::hasColumn($t, 'taluka_id')) {
                $schema->foreign('taluka_id')->references('id')->on('talukas')->nullOnDelete();
            }
            if (Schema::hasColumn($t, 'district_id')) {
                $schema->foreign('district_id')->references('id')->on('districts')->nullOnDelete();
            }
            if (Schema::hasColumn($t, 'state_id')) {
                $schema->foreign('state_id')->references('id')->on('states')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        $t = 'profile_property_assets';
        if (! Schema::hasTable($t)) {
            return;
        }
        foreach (['city_id', 'taluka_id', 'district_id', 'state_id'] as $col) {
            if (Schema::hasColumn($t, $col)) {
                Schema::table($t, function (Blueprint $schema) use ($t, $col) {
                    $fk = $t . '_' . $col . '_foreign';
                    if ($this->fkExists($t, $fk)) {
                        $schema->dropForeign($fk);
                    }
                    $schema->dropColumn($col);
                });
            }
        }
    }

    private function fkExists(string $table, string $name): bool
    {
        $conn = Schema::getConnection();
        if ($conn->getDriverName() === 'sqlite') {
            $r = $conn->select("SELECT sql FROM sqlite_master WHERE type = 'table' AND tbl_name = ?", [$table]);
            $sql = $r[0]->sql ?? '';
            return str_contains($sql, 'REFERENCES') && str_contains($sql, $name);
        }
        $db = $conn->getDatabaseName();
        $result = $conn->select(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = ?",
            [$db, $table, $name]
        );
        return count($result) > 0;
    }
};
