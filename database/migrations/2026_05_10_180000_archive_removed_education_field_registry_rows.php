<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product no longer persists specialization / college_id on profiles; archive registry rows so
 * governance and field tooling do not surface them as active CORE fields.
 *
 * Follow-up migration `2026_05_10_190000_delete_removed_education_fields_from_field_registry` removes these rows entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('field_registry')) {
            return;
        }
        $keys = ['specialization', 'college_id'];
        DB::table('field_registry')
            ->whereIn('field_key', $keys)
            ->update([
                'is_archived' => true,
                'is_enabled' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('field_registry')) {
            return;
        }
        DB::table('field_registry')
            ->whereIn('field_key', ['specialization', 'college_id'])
            ->update([
                'is_archived' => false,
                'is_enabled' => true,
                'updated_at' => now(),
            ]);
    }
};
