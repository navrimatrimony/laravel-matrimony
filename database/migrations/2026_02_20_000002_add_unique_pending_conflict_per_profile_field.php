<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5B: Enforce at most one PENDING conflict per (profile_id, field_name).
 * Composite unique on (profile_id, field_name, resolution_status) ensures one row per status per field;
 * in practice we only ever have one PENDING per profile+field (resolved rows are updated in place).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conflict_records', function (Blueprint $table) {
            $table->unique(
                ['profile_id', 'field_name', 'resolution_status'],
                'conflict_records_profile_field_status_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('conflict_records', function (Blueprint $table) {
            $table->dropUnique('conflict_records_profile_field_status_unique');
        });
    }
};
