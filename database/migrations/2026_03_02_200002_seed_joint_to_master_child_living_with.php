<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Additive: seed 'joint' into master_child_living_with for MaritalEngine living_with options.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('master_child_living_with')) {
            return;
        }
        $exists = DB::table('master_child_living_with')->where('key', 'joint')->exists();
        if (! $exists) {
            DB::table('master_child_living_with')->insert([
                'key' => 'joint',
                'label' => 'Joint',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('master_child_living_with')) {
            DB::table('master_child_living_with')->where('key', 'joint')->delete();
        }
    }
};
