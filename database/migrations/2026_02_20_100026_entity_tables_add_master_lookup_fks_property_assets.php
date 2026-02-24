<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5: profile_property_assets — asset_type_id, ownership_type_id.
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
            if (! Schema::hasColumn($t, 'asset_type_id')) {
                $schema->unsignedBigInteger('asset_type_id')->nullable()->after('profile_id');
            }
            if (! Schema::hasColumn($t, 'ownership_type_id')) {
                $schema->unsignedBigInteger('ownership_type_id')->nullable();
            }
        });

        $this->migrateByKey($t, 'asset_type', 'asset_type_id', 'master_asset_types');
        $this->migrateByKey($t, 'ownership_type', 'ownership_type_id', 'master_ownership_types');

        Schema::table($t, function (Blueprint $schema) use ($t) {
            $drops = [];
            if (Schema::hasColumn($t, 'asset_type')) {
                $drops[] = 'asset_type';
            }
            if (Schema::hasColumn($t, 'ownership_type')) {
                $drops[] = 'ownership_type';
            }
            if ($drops !== []) {
                $schema->dropColumn($drops);
            }
        });

        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (Schema::hasColumn($t, 'asset_type_id')) {
                $schema->foreign('asset_type_id')->references('id')->on('master_asset_types')->nullOnDelete();
                $schema->index('asset_type_id');
            }
            if (Schema::hasColumn($t, 'ownership_type_id')) {
                $schema->foreign('ownership_type_id')->references('id')->on('master_ownership_types')->nullOnDelete();
                $schema->index('ownership_type_id');
            }
        });
    }

    public function down(): void
    {
        $t = 'profile_property_assets';
        if (! Schema::hasTable($t)) {
            return;
        }
        foreach (['asset_type_id', 'ownership_type_id'] as $col) {
            if (Schema::hasColumn($t, $col)) {
                Schema::table($t, function (Blueprint $schema) use ($t, $col) {
                    $fk = $t . '_' . $col . '_foreign';
                    if ($this->fkExists($t, $fk)) {
                        $schema->dropForeign($fk);
                    }
                    $schema->dropIndex([$col]);
                });
            }
        }
        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (! Schema::hasColumn($t, 'asset_type')) {
                $schema->string('asset_type')->after('profile_id');
            }
            if (! Schema::hasColumn($t, 'ownership_type')) {
                $schema->string('ownership_type');
            }
        });
        foreach (DB::table('master_asset_types')->get() as $master) {
            DB::table($t)->where('asset_type_id', $master->id)->update(['asset_type' => $master->key]);
        }
        foreach (DB::table('master_ownership_types')->get() as $master) {
            DB::table($t)->where('ownership_type_id', $master->id)->update(['ownership_type' => $master->key]);
        }
        Schema::table($t, function (Blueprint $schema) use ($t) {
            $drops = array_filter(['asset_type_id', 'ownership_type_id'], fn ($c) => Schema::hasColumn($t, $c));
            if ($drops !== []) {
                $schema->dropColumn($drops);
            }
        });
    }

    private function migrateByKey(string $table, string $col, string $idCol, string $masterTable): void
    {
        if (! Schema::hasColumn($table, $col)) {
            return;
        }
        foreach (DB::table($masterTable)->get() as $master) {
            DB::table($table)
                ->whereRaw('LOWER(TRIM(REPLACE(' . $col . ', " ", "_"))) = ?', [$master->key])
                ->orWhere($col, $master->key)
                ->orWhere($col, $master->label)
                ->update([$idCol => $master->id]);
        }
    }

    private function fkExists(string $table, string $name): bool
    {
        $conn = Schema::getConnection();
        $db = $conn->getDatabaseName();
        $result = $conn->select(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = ?",
            [$db, $table, $name]
        );
        return count($result) > 0;
    }
};
