<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persists {@code admin_bypass_mode} in existing {@code admin_settings} (no new table).
     */
    public function up(): void
    {
        if (! Schema::hasTable('admin_settings')) {
            return;
        }

        $exists = DB::table('admin_settings')->where('key', 'admin_bypass_mode')->exists();
        if (! $exists) {
            DB::table('admin_settings')->insert([
                'key' => 'admin_bypass_mode',
                'value' => '0',
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

        DB::table('admin_settings')->where('key', 'admin_bypass_mode')->delete();
    }
};
