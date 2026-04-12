<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('matching_fields')) {
            return;
        }

        $rows = [
            [
                'field_key' => 'marital_status',
                'label' => 'Marital status fit',
                'type' => 'similarity',
                'category' => 'core',
                'is_active' => false,
                'weight' => 9,
                'max_weight' => 25,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'field_key' => 'height',
                'label' => 'Height fit',
                'type' => 'similarity',
                'category' => 'core',
                'is_active' => false,
                'weight' => 8,
                'max_weight' => 25,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'field_key' => 'diet',
                'label' => 'Diet fit',
                'type' => 'similarity',
                'category' => 'core',
                'is_active' => false,
                'weight' => 8,
                'max_weight' => 25,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('matching_fields')->where('field_key', $row['field_key'])->exists();
            if (! $exists) {
                DB::table('matching_fields')->insert($row);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('matching_fields')) {
            return;
        }
        DB::table('matching_fields')->whereIn('field_key', ['marital_status', 'height', 'diet'])->delete();
    }
};
