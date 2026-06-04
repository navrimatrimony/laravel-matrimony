<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds master_employment_statuses for the Education–Career–Income engine.
 *
 * Catalog degrees live in {@code master_education} (formerly {@code education_degrees}), seeded via
 * admin / importers — not this class. {@see canonicalMasterEducationRows()} is only referenced by migration
 * {@code 2026_05_10_160000_deactivate_stale_master_education_and_reseed_canonical} on pre-unification databases.
 */
class MasterEducationOccupationSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('master_employment_statuses')) {
            return;
        }

        $this->seedMasterEmploymentStatuses();
    }

    /**
     * Canonical PHASE-5 education master rows (code is the stable key for upsert).
     *
     * @return list<array{name:string,name_mr:string,code:string,group:string,sort_order:int}>
     */
    public static function canonicalMasterEducationRows(): array
    {
        return [
            ['name' => '10th / SSC', 'name_mr' => 'दहावी / एस.एस.सी.', 'code' => 'ssc', 'group' => 'school', 'sort_order' => 10],
            ['name' => '12th / HSC', 'name_mr' => 'बारावी / एच.एस.सी.', 'code' => 'hsc', 'group' => 'school', 'sort_order' => 20],
            ['name' => 'ITI', 'name_mr' => 'आय.टी.आय.', 'code' => 'iti', 'group' => 'school', 'sort_order' => 30],
            ['name' => 'Diploma', 'name_mr' => 'पदविका', 'code' => 'diploma', 'group' => 'diploma', 'sort_order' => 40],
            ['name' => 'Bachelor of Arts', 'name_mr' => 'कला पदवी (बी.ए.)', 'code' => 'ba', 'group' => 'bachelor', 'sort_order' => 50],
            ['name' => 'Bachelor of Commerce', 'name_mr' => 'वाणिज्य पदवी (बी.कॉम.)', 'code' => 'bcom', 'group' => 'bachelor', 'sort_order' => 60],
            ['name' => 'Bachelor of Science', 'name_mr' => 'विज्ञान पदवी (बी.एस्सी.)', 'code' => 'bsc', 'group' => 'bachelor', 'sort_order' => 70],
            ['name' => 'Bachelor of Engineering / Technology', 'name_mr' => 'अभियांत्रिकी / तंत्रज्ञान पदवी', 'code' => 'be_btech', 'group' => 'bachelor', 'sort_order' => 80],
            ['name' => 'Bachelor of Computer Applications', 'name_mr' => 'संगणक अनुप्रयोग पदवी (बी.सी.ए.)', 'code' => 'bca', 'group' => 'bachelor', 'sort_order' => 90],
            ['name' => 'Bachelor of Business Administration / Management', 'name_mr' => 'व्यवसाय प्रशासन पदवी (बी.बी.ए.)', 'code' => 'bba', 'group' => 'bachelor', 'sort_order' => 100],
            ['name' => 'Bachelor of Pharmacy', 'name_mr' => 'फार्मसी पदवी (बी.फार्म.)', 'code' => 'bpharm', 'group' => 'bachelor', 'sort_order' => 110],
            ['name' => 'Bachelor of Laws', 'name_mr' => 'विधी पदवी (एल.एल.बी.)', 'code' => 'llb', 'group' => 'bachelor', 'sort_order' => 120],
            ['name' => 'Bachelor of Medicine and Bachelor of Surgery', 'name_mr' => 'एम.बी.बी.एस.', 'code' => 'mbbs', 'group' => 'bachelor', 'sort_order' => 130],
            ['name' => 'Bachelor of Dental Surgery', 'name_mr' => 'दंत शस्त्रक्रिया पदवी (बी.डी.एस.)', 'code' => 'bds', 'group' => 'bachelor', 'sort_order' => 140],
            ['name' => 'Bachelor of Science in Agriculture', 'name_mr' => 'कृषी विज्ञान पदवी', 'code' => 'bsc_agri', 'group' => 'bachelor', 'sort_order' => 150],
            ['name' => 'Master of Arts', 'name_mr' => 'कला पदव्युत्तर (एम.ए.)', 'code' => 'ma', 'group' => 'master', 'sort_order' => 160],
            ['name' => 'Master of Commerce', 'name_mr' => 'वाणिज्य पदव्युत्तर (एम.कॉम.)', 'code' => 'mcom', 'group' => 'master', 'sort_order' => 170],
            ['name' => 'Master of Science', 'name_mr' => 'विज्ञान पदव्युत्तर (एम.एस्सी.)', 'code' => 'msc', 'group' => 'master', 'sort_order' => 180],
            ['name' => 'Master of Engineering / Technology', 'name_mr' => 'अभियांत्रिकी / तंत्रज्ञान पदव्युत्तर', 'code' => 'me_mtech', 'group' => 'master', 'sort_order' => 190],
            ['name' => 'MBA / PGDM', 'name_mr' => 'व्यवसाय प्रशासन पदव्युत्तर (एम.बी.ए.)', 'code' => 'mba', 'group' => 'master', 'sort_order' => 200],
            ['name' => 'MCA', 'name_mr' => 'संगणक अनुप्रयोग पदव्युत्तर (एम.सी.ए.)', 'code' => 'mca', 'group' => 'master', 'sort_order' => 210],
            ['name' => 'M.Pharm', 'name_mr' => 'फार्मसी पदव्युत्तर (एम.फार्म.)', 'code' => 'mpharm', 'group' => 'master', 'sort_order' => 220],
            ['name' => 'M.Ed', 'name_mr' => 'शिक्षण पदव्युत्तर (एम.एड.)', 'code' => 'med', 'group' => 'master', 'sort_order' => 230],
            ['name' => 'LLM', 'name_mr' => 'विधी पदव्युत्तर (एल.एल.एम.)', 'code' => 'llm', 'group' => 'master', 'sort_order' => 240],
            ['name' => 'MD / MS', 'name_mr' => 'एम.डी. / एम.एस.', 'code' => 'md_ms', 'group' => 'master', 'sort_order' => 250],
            ['name' => 'MDS', 'name_mr' => 'एम.डी.एस.', 'code' => 'mds', 'group' => 'master', 'sort_order' => 260],
            ['name' => 'PhD / Doctorate', 'name_mr' => 'पीएच.डी. / डॉक्टरेट', 'code' => 'phd', 'group' => 'doctorate', 'sort_order' => 270],
            ['name' => 'CA / CS / CMA', 'name_mr' => 'सी.ए. / सी.एस. / सी.एम.ए.', 'code' => 'ca_cs_cma', 'group' => 'professional', 'sort_order' => 280],
            ['name' => 'Other', 'name_mr' => 'इतर', 'code' => 'other', 'group' => 'other', 'sort_order' => 999],
        ];
    }

    /**
     * @return list<string>
     */
    public static function canonicalMasterEducationCodes(): array
    {
        return array_values(array_map(static fn (array $r) => (string) $r['code'], self::canonicalMasterEducationRows()));
    }

    private function seedMasterEmploymentStatuses(): void
    {
        $rows = [
            ['name' => 'Full Time', 'name_mr' => 'पूर्ण वेळ', 'code' => 'full_time', 'sort_order' => 10],
            ['name' => 'Part Time', 'name_mr' => 'अर्धवेळ', 'code' => 'part_time', 'sort_order' => 20],
            ['name' => 'Contract', 'name_mr' => 'करार', 'code' => 'contract', 'sort_order' => 30],
            ['name' => 'Own Business', 'name_mr' => 'स्वतःचा व्यवसाय', 'code' => 'own_business', 'sort_order' => 40],
            ['name' => 'Practice', 'name_mr' => 'स्वतंत्र सराव', 'code' => 'practice', 'sort_order' => 50],
            ['name' => 'Seasonal', 'name_mr' => 'हंगामी', 'code' => 'seasonal', 'sort_order' => 60],
            ['name' => 'Not Working', 'name_mr' => 'कार्यरत नाही', 'code' => 'not_working', 'sort_order' => 70],
            ['name' => 'Studying', 'name_mr' => 'अभ्यास करत आहे', 'code' => 'studying', 'sort_order' => 80],
        ];
        $hasMr = Schema::hasColumn('master_employment_statuses', 'name_mr');
        foreach ($rows as $row) {
            if (! $hasMr) {
                unset($row['name_mr']);
            }
            DB::table('master_employment_statuses')->updateOrInsert(
                ['code' => $row['code']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
