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
            // SQLite does not support ALTER COLUMN; rebuild table (zero data loss).
            // Index names are database-wide: drop index on renamed table so new table can use same name.
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement('ALTER TABLE field_value_history RENAME TO field_value_history__old');
            DB::statement('DROP INDEX IF EXISTS field_value_history_profile_id_field_key_changed_at_index');
            Schema::create('field_value_history', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('profile_id')->nullable();
                $table->string('field_key', 64);
                $table->string('field_type', 16); // was enum
                $table->text('old_value')->nullable();
                $table->text('new_value')->nullable();
                $table->string('changed_by', 32);
                $table->timestamp('changed_at');
                $table->timestamps();
                $table->foreign('profile_id')->references('id')->on('matrimony_profiles')->nullOnDelete();
                $table->index(['profile_id', 'field_key', 'changed_at']);
            });
            DB::statement('INSERT INTO field_value_history (id, profile_id, field_key, field_type, old_value, new_value, changed_by, changed_at, created_at, updated_at) SELECT id, profile_id, field_key, field_type, old_value, new_value, changed_by, changed_at, created_at, updated_at FROM field_value_history__old');
            DB::statement('DROP TABLE field_value_history__old');
            DB::statement('PRAGMA foreign_keys = ON');
        } else {
            DB::statement('ALTER TABLE field_value_history ALTER COLUMN profile_id DROP NOT NULL');
        }

        if ($driver !== 'sqlite') {
            Schema::table('field_value_history', function (Blueprint $table) {
                $table->foreign('profile_id')->references('id')->on('matrimony_profiles')->nullOnDelete();
            });
        }
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
