<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5: profile_addresses — address_type → address_type_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'profile_addresses';
        if (! Schema::hasTable($t)) {
            return;
        }

        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (! Schema::hasColumn($t, 'address_type_id')) {
                $schema->unsignedBigInteger('address_type_id')->nullable()->after('profile_id');
            }
        });

        $this->migrateByKey($t, 'address_type', 'address_type_id', 'master_address_types');

        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (Schema::hasColumn($t, 'address_type')) {
                $schema->dropColumn('address_type');
            }
        });

        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (Schema::hasColumn($t, 'address_type_id')) {
                $schema->foreign('address_type_id')->references('id')->on('master_address_types')->nullOnDelete();
                $schema->index('address_type_id');
            }
        });
    }

    public function down(): void
    {
        $t = 'profile_addresses';
        if (! Schema::hasTable($t)) {
            return;
        }
        if (Schema::hasColumn($t, 'address_type_id')) {
            Schema::table($t, function (Blueprint $schema) use ($t) {
                $fk = "{$t}_address_type_id_foreign";
                if ($this->fkExists($t, $fk)) {
                    $schema->dropForeign($fk);
                }
                $schema->dropIndex(['address_type_id']);
            });
        }
        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (! Schema::hasColumn($t, 'address_type')) {
                $schema->string('address_type')->after('profile_id');
            }
        });
        foreach (DB::table('master_address_types')->get() as $master) {
            DB::table($t)->where('address_type_id', $master->id)->update(['address_type' => $master->key]);
        }
        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (Schema::hasColumn($t, 'address_type_id')) {
                $schema->dropColumn('address_type_id');
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
