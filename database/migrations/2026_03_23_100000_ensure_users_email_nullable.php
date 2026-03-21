<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safety net: some MySQL databases may still have NOT NULL email if an older
 * migrate run failed partway or the column was never altered. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
        }
    }

    public function down(): void
    {
        // Intentionally empty: reverting NOT NULL can break accounts with null email.
    }
};
