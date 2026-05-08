<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensures {@code data_engine_enabled} exists in {@code admin_settings} (additive; no new table).
     */
    public function up(): void
    {
        if (! Schema::hasTable('admin_settings')) {
            return;
        }

        $exists = DB::table('admin_settings')->where('key', 'data_engine_enabled')->exists();
        if (! $exists) {
            DB::table('admin_settings')->insert([
                'key' => 'data_engine_enabled',
                'value' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('admin_settings')) {
            return;
        }

        DB::table('admin_settings')->where('key', 'data_engine_enabled')->delete();
    }
};
