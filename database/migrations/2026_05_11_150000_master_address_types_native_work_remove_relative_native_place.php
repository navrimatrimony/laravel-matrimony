<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product: native village/town is modeled via {@code addresses} + {@code master_address_types} (key {@code native}),
 * not as a pseudo-row in {@code profile_relatives} ({@code relation_type = native_place}).
 *
 * - Rename {@code office} → {@code work} (labels updated).
 * - Insert {@code native} when missing (5 types: current, permanent, native, work, other).
 * - Remove legacy {@code profile_relatives} rows that used the dropped wizard option.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('master_address_types')) {
            return;
        }

        $now = now();

        if (DB::table('master_address_types')->where('key', 'office')->exists()) {
            $row = ['key' => 'work', 'label' => 'Work', 'updated_at' => $now];
            if (Schema::hasColumn('master_address_types', 'label_mr')) {
                $row['label_mr'] = 'काम';
            }
            DB::table('master_address_types')->where('key', 'office')->update($row);
        }

        if (! DB::table('master_address_types')->where('key', 'native')->exists()) {
            $insert = [
                'key' => 'native',
                'label' => 'Native',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('master_address_types', 'label_mr')) {
                $insert['label_mr'] = 'मूळ';
            }
            DB::table('master_address_types')->insert($insert);
        }

        if (Schema::hasTable('profile_relatives')) {
            DB::table('profile_relatives')->where('relation_type', 'native_place')->delete();
        }
    }

    public function down(): void
    {
        // Intentionally empty: reverting key renames / re-inserting removed relative rows is unsafe.
    }
};
