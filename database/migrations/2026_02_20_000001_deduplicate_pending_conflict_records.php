<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase-5B: One-time cleanup — keep only latest PENDING conflict per (profile_id, field_name).
 * Deletes older duplicates to prevent conflict spam.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Keep only latest PENDING per (profile_id, field_name). Delete older duplicates.
        $keepIds = DB::table('conflict_records')
            ->where('resolution_status', 'PENDING')
            ->selectRaw('MAX(id) as id')
            ->groupBy('profile_id', 'field_name')
            ->pluck('id');
        if ($keepIds->isEmpty()) {
            return;
        }
        DB::table('conflict_records')
            ->where('resolution_status', 'PENDING')
            ->whereNotIn('id', $keepIds->all())
            ->delete();
    }

    public function down(): void
    {
        // One-time cleanup; no reversible data restore.
    }
};
