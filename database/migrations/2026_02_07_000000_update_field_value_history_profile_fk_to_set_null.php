<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Day-6 FIX-2: Field history must survive profile deletion (Law 9).
 * Change profile_id FK from CASCADE to SET NULL so history rows are preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_value_history', function (Blueprint $table) {
            $table->dropForeign(['profile_id']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE field_value_history MODIFY profile_id BIGINT UNSIGNED NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE field_value_history ALTER COLUMN profile_id DROP NOT NULL');
        } elseif ($driver === 'sqlite') {
            DB::statement('ALTER TABLE field_value_history ALTER COLUMN profile_id DROP NOT NULL');
        }

        Schema::table('field_value_history', function (Blueprint $table) {
            $table->foreign('profile_id')->references('id')->on('matrimony_profiles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('field_value_history', function (Blueprint $table) {
            $table->dropForeign(['profile_id']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE field_value_history MODIFY profile_id BIGINT UNSIGNED NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE field_value_history ALTER COLUMN profile_id SET NOT NULL');
        }
        // SQLite: down() not reverting nullable (no data loss; FK restored with cascade)

        Schema::table('field_value_history', function (Blueprint $table) {
            $table->foreign('profile_id')->references('id')->on('matrimony_profiles')->cascadeOnDelete();
        });
    }
};
