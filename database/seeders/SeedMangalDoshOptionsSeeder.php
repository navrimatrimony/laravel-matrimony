<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed/update only master_mangal_dosh_types with the 4 main options + legacy.
 */
class SeedMangalDoshOptionsSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('master_mangal_dosh_types')) {
            return;
        }
        $rows = [
            ['key' => 'none', 'label' => 'No / Non-Manglik (नाही)'],
            ['key' => 'bhumangal', 'label' => 'Yes / Manglik (हो/आहे)'],
            ['key' => 'anshik_mangal', 'label' => 'Anshik Mangal / Soumya Mangal (सौम्य मंगळ)'],
            ['key' => 'don_t_know', 'label' => 'Don\'t Know (माहित नाही)'],
            ['key' => 'chovamangal', 'label' => 'Chovamangal'],
            ['key' => 'antya_mangal', 'label' => 'Antya Mangal'],
            ['key' => 'other', 'label' => 'Other'],
        ];
        foreach ($rows as $row) {
            DB::table('master_mangal_dosh_types')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, ['is_active' => true, 'updated_at' => now()])
            );
        }
    }
}
