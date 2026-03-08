<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds master_education, master_occupation_types, master_employment_statuses
 * for the Education–Career–Income engine (PHASE-5 SSOT).
 */
class MasterEducationOccupationSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedMasterEducation();
        $this->seedMasterOccupationTypes();
        $this->seedMasterEmploymentStatuses();
    }

    private function seedMasterEducation(): void
    {
        $rows = [
            ['name' => '10th / SSC', 'code' => 'ssc', 'group' => 'school', 'sort_order' => 10],
            ['name' => '12th / HSC', 'code' => 'hsc', 'group' => 'school', 'sort_order' => 20],
            ['name' => 'ITI', 'code' => 'iti', 'group' => 'school', 'sort_order' => 30],
            ['name' => 'Diploma', 'code' => 'diploma', 'group' => 'diploma', 'sort_order' => 40],
            ['name' => 'Bachelor – Arts', 'code' => 'ba', 'group' => 'bachelor', 'sort_order' => 50],
            ['name' => 'Bachelor – Commerce', 'code' => 'bcom', 'group' => 'bachelor', 'sort_order' => 60],
            ['name' => 'Bachelor – Science', 'code' => 'bsc', 'group' => 'bachelor', 'sort_order' => 70],
            ['name' => 'Bachelor – Engineering / Technology', 'code' => 'be_btech', 'group' => 'bachelor', 'sort_order' => 80],
            ['name' => 'Bachelor – Computer Applications', 'code' => 'bca', 'group' => 'bachelor', 'sort_order' => 90],
            ['name' => 'Bachelor – Business / Management', 'code' => 'bba', 'group' => 'bachelor', 'sort_order' => 100],
            ['name' => 'Bachelor – Pharmacy', 'code' => 'bpharm', 'group' => 'bachelor', 'sort_order' => 110],
            ['name' => 'Bachelor – Law', 'code' => 'llb', 'group' => 'bachelor', 'sort_order' => 120],
            ['name' => 'Bachelor – Medical', 'code' => 'mbbs', 'group' => 'bachelor', 'sort_order' => 130],
            ['name' => 'Bachelor – Dental', 'code' => 'bds', 'group' => 'bachelor', 'sort_order' => 140],
            ['name' => 'Bachelor – Agriculture', 'code' => 'bsc_agri', 'group' => 'bachelor', 'sort_order' => 150],
            ['name' => 'Master – Arts', 'code' => 'ma', 'group' => 'master', 'sort_order' => 160],
            ['name' => 'Master – Commerce', 'code' => 'mcom', 'group' => 'master', 'sort_order' => 170],
            ['name' => 'Master – Science', 'code' => 'msc', 'group' => 'master', 'sort_order' => 180],
            ['name' => 'Master – Engineering / Technology', 'code' => 'me_mtech', 'group' => 'master', 'sort_order' => 190],
            ['name' => 'MBA / PGDM', 'code' => 'mba', 'group' => 'master', 'sort_order' => 200],
            ['name' => 'MCA', 'code' => 'mca', 'group' => 'master', 'sort_order' => 210],
            ['name' => 'M.Pharm', 'code' => 'mpharm', 'group' => 'master', 'sort_order' => 220],
            ['name' => 'M.Ed', 'code' => 'med', 'group' => 'master', 'sort_order' => 230],
            ['name' => 'LLM', 'code' => 'llm', 'group' => 'master', 'sort_order' => 240],
            ['name' => 'MD / MS', 'code' => 'md_ms', 'group' => 'master', 'sort_order' => 250],
            ['name' => 'MDS', 'code' => 'mds', 'group' => 'master', 'sort_order' => 260],
            ['name' => 'PhD / Doctorate', 'code' => 'phd', 'group' => 'doctorate', 'sort_order' => 270],
            ['name' => 'CA / CS / CMA', 'code' => 'ca_cs_cma', 'group' => 'professional', 'sort_order' => 280],
            ['name' => 'Other', 'code' => 'other', 'group' => 'other', 'sort_order' => 999],
        ];
        foreach ($rows as $row) {
            DB::table('master_education')->updateOrInsert(
                ['name' => $row['name']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    private function seedMasterOccupationTypes(): void
    {
        $rows = [
            ['name' => 'Private Job', 'code' => 'private_job', 'sort_order' => 10],
            ['name' => 'Government Job', 'code' => 'government_job', 'sort_order' => 20],
            ['name' => 'Semi-Government', 'code' => 'semi_government', 'sort_order' => 30],
            ['name' => 'Business', 'code' => 'business', 'sort_order' => 40],
            ['name' => 'Self Employed', 'code' => 'self_employed', 'sort_order' => 50],
            ['name' => 'Professional Practice', 'code' => 'professional_practice', 'sort_order' => 60],
            ['name' => 'Agriculture', 'code' => 'agriculture', 'sort_order' => 70],
            ['name' => 'Freelancer', 'code' => 'freelancer', 'sort_order' => 80],
            ['name' => 'Student', 'code' => 'student', 'sort_order' => 90],
            ['name' => 'Not Working', 'code' => 'not_working', 'sort_order' => 100],
            ['name' => 'Other', 'code' => 'other', 'sort_order' => 999],
        ];
        foreach ($rows as $row) {
            DB::table('master_occupation_types')->updateOrInsert(
                ['code' => $row['code']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    private function seedMasterEmploymentStatuses(): void
    {
        $rows = [
            ['name' => 'Full Time', 'code' => 'full_time', 'sort_order' => 10],
            ['name' => 'Part Time', 'code' => 'part_time', 'sort_order' => 20],
            ['name' => 'Contract', 'code' => 'contract', 'sort_order' => 30],
            ['name' => 'Own Business', 'code' => 'own_business', 'sort_order' => 40],
            ['name' => 'Practice', 'code' => 'practice', 'sort_order' => 50],
            ['name' => 'Seasonal', 'code' => 'seasonal', 'sort_order' => 60],
            ['name' => 'Not Working', 'code' => 'not_working', 'sort_order' => 70],
            ['name' => 'Studying', 'code' => 'studying', 'sort_order' => 80],
        ];
        foreach ($rows as $row) {
            DB::table('master_employment_statuses')->updateOrInsert(
                ['code' => $row['code']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
