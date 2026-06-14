<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECK_NAME = 'addresses_lgd_code_village_only_chk';

    public function up(): void
    {
        if (! Schema::hasTable('addresses') || ! Schema::hasColumn('addresses', 'lgd_code')) {
            return;
        }

        $hierarchyColumn = Schema::hasColumn('addresses', 'hierarchy') ? 'hierarchy' : (Schema::hasColumn('addresses', 'type') ? 'type' : null);
        if ($hierarchyColumn === null) {
            return;
        }

        DB::table('addresses')
            ->whereIn($hierarchyColumn, ['country', 'state', 'district', 'taluka'])
            ->whereNotNull('lgd_code')
            ->update(['lgd_code' => null]);

        if (DB::getDriverName() === 'mysql' && $hierarchyColumn === 'hierarchy' && ! $this->mysqlCheckExists()) {
            DB::statement('ALTER TABLE `addresses` ADD CONSTRAINT `'.self::CHECK_NAME.'` CHECK (`hierarchy` = \'village\' OR `lgd_code` IS NULL)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && $this->mysqlCheckExists()) {
            DB::statement('ALTER TABLE `addresses` DROP CHECK `'.self::CHECK_NAME.'`');
        }
    }

    private function mysqlCheckExists(): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS total
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            ['addresses', self::CHECK_NAME, 'CHECK']
        );

        return (int) ($row->total ?? 0) > 0;
    }
};
