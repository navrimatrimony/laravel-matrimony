<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product: education is {@code matrimony_profiles.highest_education} only — remove obsolete
 * {@code specialization} and {@code college_id} rows from {@code field_registry} entirely
 * (after {@see \Database\Migrations\2026_05_10_180000_archive_removed_education_field_registry_rows}
 * may have archived them on already-deployed DBs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('field_registry')) {
            return;
        }

        $keys = ['specialization', 'college_id'];
        $ids = DB::table('field_registry')->whereIn('field_key', $keys)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        if (Schema::hasColumn('field_registry', 'replaced_by_field')) {
            DB::table('field_registry')
                ->whereIn('replaced_by_field', $ids->all())
                ->update(['replaced_by_field' => null]);
        }

        DB::table('field_registry')->whereIn('field_key', $keys)->delete();
    }

    public function down(): void
    {
        // Intentionally empty — re-seed via FieldRegistryCoreSeeder on fresh installs; no stable row restore.
    }
};
