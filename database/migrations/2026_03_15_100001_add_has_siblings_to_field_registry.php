<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add has_siblings to field_registry so MutationService applies it from snapshot core.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('field_registry')) {
            return;
        }
        $exists = DB::table('field_registry')->where('field_key', 'has_siblings')->exists();
        if ($exists) {
            return;
        }
        DB::table('field_registry')->insert([
            'field_key' => 'has_siblings',
            'field_type' => 'CORE',
            'data_type' => 'boolean',
            'is_mandatory' => 0,
            'is_searchable' => 0,
            'is_user_editable' => 1,
            'is_system_overwritable' => 1,
            'lock_after_user_edit' => 0,
            'display_label' => 'Has Siblings',
            'display_order' => 2255,
            'category' => 'family',
            'is_archived' => 0,
            'replaced_by_field' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('field_registry')) {
            DB::table('field_registry')->where('field_key', 'has_siblings')->delete();
        }
    }
};
