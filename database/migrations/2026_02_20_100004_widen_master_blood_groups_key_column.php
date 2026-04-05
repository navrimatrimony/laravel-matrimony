<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Original table used string('key', 8) — too short for not_known (9) and prefer_not_to_say (18).
 * MasterLookupSeeder (invoked from 100008) fails on MySQL without this.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('master_blood_groups')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `master_blood_groups` MODIFY `key` VARCHAR(32) NOT NULL');
            DB::statement('ALTER TABLE `master_blood_groups` MODIFY `label` VARCHAR(64) NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE master_blood_groups ALTER COLUMN key TYPE VARCHAR(32)');
            DB::statement('ALTER TABLE master_blood_groups ALTER COLUMN label TYPE VARCHAR(64)');
        }
        // SQLite: length limits are not enforced like MySQL; tests skip ALTER.
    }

    public function down(): void
    {
        if (! Schema::hasTable('master_blood_groups')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `master_blood_groups` MODIFY `label` VARCHAR(16) NOT NULL');
            DB::statement('ALTER TABLE `master_blood_groups` MODIFY `key` VARCHAR(8) NOT NULL');
        }
    }
};
