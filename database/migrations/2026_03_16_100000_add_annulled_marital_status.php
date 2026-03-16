<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add "Annulled" (नाममात्र घटस्फोटीत) marital status between Divorced and Separated.
     * Additive only: no schema change.
     */
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('master_marital_statuses')) {
            return;
        }
        $exists = DB::table('master_marital_statuses')->where('key', 'annulled')->exists();
        if (! $exists) {
            DB::table('master_marital_statuses')->insert([
                'key' => 'annulled',
                'label' => 'Annulled',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('master_marital_statuses')->where('key', 'annulled')->delete();
    }
};
