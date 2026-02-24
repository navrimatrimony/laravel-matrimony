<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5: profile_contacts — relation_type → contact_relation_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'profile_contacts';
        if (! Schema::hasTable($t)) {
            return;
        }

        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (! Schema::hasColumn($t, 'contact_relation_id')) {
                $schema->unsignedBigInteger('contact_relation_id')->nullable()->after('profile_id');
            }
        });

        $this->migrateByKey($t, 'relation_type', 'contact_relation_id', 'master_contact_relations');

        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (Schema::hasColumn($t, 'relation_type')) {
                $schema->dropColumn('relation_type');
            }
        });

        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (Schema::hasColumn($t, 'contact_relation_id')) {
                $schema->foreign('contact_relation_id')->references('id')->on('master_contact_relations')->nullOnDelete();
                $schema->index('contact_relation_id');
            }
        });
    }

    public function down(): void
    {
        $t = 'profile_contacts';
        if (! Schema::hasTable($t)) {
            return;
        }
        if (Schema::hasColumn($t, 'contact_relation_id')) {
            Schema::table($t, function (Blueprint $schema) use ($t) {
                $fk = "{$t}_contact_relation_id_foreign";
                if ($this->fkExists($t, $fk)) {
                    $schema->dropForeign($fk);
                }
                $schema->dropIndex(['contact_relation_id']);
            });
        }
        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (! Schema::hasColumn($t, 'relation_type')) {
                $schema->string('relation_type')->after('profile_id');
            }
        });
        foreach (DB::table('master_contact_relations')->get() as $master) {
            DB::table($t)->where('contact_relation_id', $master->id)->update(['relation_type' => $master->key]);
        }
        Schema::table($t, function (Blueprint $schema) use ($t) {
            if (Schema::hasColumn($t, 'contact_relation_id')) {
                $schema->dropColumn('contact_relation_id');
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
