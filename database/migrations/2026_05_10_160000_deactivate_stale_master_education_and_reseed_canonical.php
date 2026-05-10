<?php

use Database\Seeders\MasterEducationOccupationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy / mojibake master_education rows (missing or non-canonical {@see code}) confuse bulk showcase
 * and can break downstream validation. Canonical rows are re-upserted from
 * {@see MasterEducationOccupationSeeder::canonicalMasterEducationRows()}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('master_education')) {
            return;
        }
        // After {@see 2026_05_10_210000_consolidate_education_tables_into_master_prefix}, `master_education`
        // is the former `education_degrees` table — do not run legacy duplicate-row cleanup here.
        if (Schema::hasColumn('master_education', 'category_id')) {
            return;
        }

        $codes = MasterEducationOccupationSeeder::canonicalMasterEducationCodes();

        DB::table('master_education')
            ->where(function ($q) use ($codes) {
                $q->whereNull('code')->orWhereNotIn('code', $codes);
            })
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        (new MasterEducationOccupationSeeder)->run();

        foreach ($codes as $code) {
            $ids = DB::table('master_education')->where('code', $code)->orderBy('id')->pluck('id')->all();
            if (count($ids) <= 1) {
                continue;
            }
            array_shift($ids);
            DB::table('master_education')->whereIn('id', $ids)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
        }

        (new MasterEducationOccupationSeeder)->run();
    }

    public function down(): void
    {
        // Non-reversible: we do not know which inactive rows should be restored.
    }
};
