<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'relation_to_profile')) {
                $table->dropColumn('relation_to_profile');
            }
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            // Native ALTER avoids relying on column-change edge cases; matches other user-table migrations.
            DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email')->nullable()->change();
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('mobile');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['mobile']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN email SET NOT NULL');
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email')->nullable(false)->change();
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('relation_to_profile', 32)->nullable()->after('registering_for');
        });
    }
};
