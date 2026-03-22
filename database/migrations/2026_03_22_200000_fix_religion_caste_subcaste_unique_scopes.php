<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->dropMysqlIndexIfExists('castes', 'castes_key_unique');
            if (! $this->mysqlIndexExists('castes', 'castes_religion_id_key_unique')) {
                DB::statement('ALTER TABLE castes ADD UNIQUE KEY castes_religion_id_key_unique (religion_id, `key`)');
            }
            if (! $this->mysqlIndexExists('sub_castes', 'sub_castes_caste_id_key_unique')) {
                DB::statement('ALTER TABLE sub_castes ADD UNIQUE KEY sub_castes_caste_id_key_unique (caste_id, `key`)');
            }
            if (! $this->mysqlIndexExists('sub_castes', 'sub_castes_status_index')) {
                DB::statement('ALTER TABLE sub_castes ADD INDEX sub_castes_status_index (status)');
            }

            return;
        }

        Schema::table('castes', function (Blueprint $table) {
            if (Schema::hasIndex('castes', 'castes_key_unique')) {
                $table->dropIndex('castes_key_unique');
            }
        });

        Schema::table('castes', function (Blueprint $table) {
            if (! Schema::hasIndex('castes', 'castes_religion_id_key_unique')) {
                $table->unique(['religion_id', 'key'], 'castes_religion_id_key_unique');
            }
        });

        Schema::table('sub_castes', function (Blueprint $table) {
            if (! Schema::hasIndex('sub_castes', 'sub_castes_caste_id_key_unique')) {
                $table->unique(['caste_id', 'key'], 'sub_castes_caste_id_key_unique');
            }
            if (! Schema::hasIndex('sub_castes', 'sub_castes_status_index')) {
                $table->index('status', 'sub_castes_status_index');
            }
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->dropMysqlIndexIfExists('sub_castes', 'sub_castes_status_index');
            $this->dropMysqlIndexIfExists('sub_castes', 'sub_castes_caste_id_key_unique');
            $this->dropMysqlIndexIfExists('castes', 'castes_religion_id_key_unique');
            if (! $this->mysqlIndexExists('castes', 'castes_key_unique')) {
                DB::statement('ALTER TABLE castes ADD UNIQUE KEY castes_key_unique (`key`)');
            }

            return;
        }

        Schema::table('sub_castes', function (Blueprint $table) {
            if (Schema::hasIndex('sub_castes', 'sub_castes_status_index')) {
                $table->dropIndex('sub_castes_status_index');
            }
            if (Schema::hasIndex('sub_castes', 'sub_castes_caste_id_key_unique')) {
                $table->dropUnique('sub_castes_caste_id_key_unique');
            }
        });

        Schema::table('castes', function (Blueprint $table) {
            if (Schema::hasIndex('castes', 'castes_religion_id_key_unique')) {
                $table->dropUnique('castes_religion_id_key_unique');
            }
        });

        Schema::table('castes', function (Blueprint $table) {
            if (! Schema::hasIndex('castes', 'castes_key_unique')) {
                $table->unique('key', 'castes_key_unique');
            }
        });
    }

    private function mysqlIndexExists(string $table, string $indexName): bool
    {
        $db = DB::getDatabaseName();
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$db, $table, $indexName]
        );

        return $row && (int) $row->c > 0;
    }

    private function dropMysqlIndexIfExists(string $table, string $indexName): void
    {
        if ($this->mysqlIndexExists($table, $indexName)) {
            DB::statement('ALTER TABLE `'.$table.'` DROP INDEX `'.$indexName.'`');
        }
    }
};
