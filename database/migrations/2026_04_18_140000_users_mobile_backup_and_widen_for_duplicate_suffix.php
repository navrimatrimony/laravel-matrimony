<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: backup + FK for duplicate resolution; widen mobile to hold {@code 10digits_dup_id} safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'mobile_backup')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('mobile_backup', 32)->nullable()->after('mobile');
            });
        }

        if (! Schema::hasColumn('users', 'mobile_duplicate_of_user_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('mobile_duplicate_of_user_id')->nullable()->after('mobile_backup')->constrained('users')->nullOnDelete();
            });
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE users MODIFY mobile VARCHAR(64) NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN mobile TYPE VARCHAR(64)');
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->string('mobile', 64)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (Schema::hasColumn('users', 'mobile_duplicate_of_user_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('mobile_duplicate_of_user_id');
            });
        }

        if (Schema::hasColumn('users', 'mobile_backup')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('mobile_backup');
            });
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE users MODIFY mobile VARCHAR(20) NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN mobile TYPE VARCHAR(20)');
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->string('mobile', 20)->nullable()->change();
            });
        }
    }
};
