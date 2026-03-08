<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Temporary placeholder data for Education & Career engine.
 * Replace with exact Shaadi-style data later. Reseed-safe.
 * Career dependency: professions.working_with_type_id links to working_with_types.
 */
class EducationCareerTemporarySeeder extends Seeder
{
    public function run(): void
    {
        $this->seedWorkingWithTypes();
        $this->seedProfessions();
        $this->seedIncomeRanges();
        $this->seedColleges();
    }

    private function seedWorkingWithTypes(): void
    {
        $rows = [
            ['name' => 'Private Company', 'slug' => 'private_company', 'sort_order' => 10],
            ['name' => 'Government / Public Sector', 'slug' => 'government_public_sector', 'sort_order' => 20],
            ['name' => 'Defense / Civil Services', 'slug' => 'defense_civil_services', 'sort_order' => 30],
            ['name' => 'Business / Self Employed', 'slug' => 'business_self_employed', 'sort_order' => 40],
            ['name' => 'Not Working', 'slug' => 'not_working', 'sort_order' => 50],
        ];
        foreach ($rows as $row) {
            DB::table('working_with_types')->updateOrInsert(
                ['slug' => $row['slug']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    /**
     * Profession name => working_with_type slug (one-to-one for dependency).
     * Each profession maps to exactly one working_with_type.
     */
    private function getProfessionMapping(): array
    {
        return [
            'Software Professional' => 'private_company',
            'Advertising Professional' => 'private_company',
            'Banking Professional' => 'private_company',
            'Teacher' => 'private_company',
            'Professor' => 'private_company',
            'Doctor' => 'government_public_sector',
            'Nurse' => 'private_company',
            'Engineer' => 'private_company',
            'Civil Engineer' => 'private_company',
            'Mechanical Engineer' => 'private_company',
            'Accountant' => 'private_company',
            'Chartered Accountant' => 'private_company',
            'Lawyer' => 'government_public_sector',
            'Architect' => 'government_public_sector',
            'Designer' => 'business_self_employed',
            'Fashion Designer' => 'business_self_employed',
            'Admin Professional' => 'government_public_sector',
            'HR Professional' => 'private_company',
            'Sales Professional' => 'private_company',
            'Marketing Professional' => 'private_company',
            'Consultant' => 'business_self_employed',
            'Entrepreneur' => 'business_self_employed',
            'Business Owner' => 'business_self_employed',
            'Hotel Professional' => 'private_company',
            'Media Professional' => 'private_company',
            'Journalist' => 'private_company',
            'Scientist' => 'government_public_sector',
            'Research Scholar' => 'government_public_sector',
            'Pharmacist' => 'private_company',
            'Therapist' => 'private_company',
            'Police Officer' => 'defense_civil_services',
            'Army Personnel' => 'defense_civil_services',
            'Clerk' => 'private_company',
            'Student' => 'not_working',
            'Not Working' => 'not_working',
        ];
    }

    private function seedProfessions(): void
    {
        $typeIdsBySlug = DB::table('working_with_types')->pluck('id', 'slug')->toArray();
        $mapping = $this->getProfessionMapping();
        $sort = 10;
        foreach ($mapping as $name => $workingWithSlug) {
            $slug = Str::slug($name);
            $workingWithTypeId = isset($typeIdsBySlug[$workingWithSlug]) ? $typeIdsBySlug[$workingWithSlug] : null;
            DB::table('professions')->updateOrInsert(
                ['slug' => $slug],
                [
                    'working_with_type_id' => $workingWithTypeId,
                    'name' => $name,
                    'slug' => $slug,
                    'sort_order' => $sort,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $sort += 10;
        }
    }

    private function seedIncomeRanges(): void
    {
        $rows = [
            ['name' => 'No Income', 'slug' => 'no_income', 'sort_order' => 10],
            ['name' => 'INR 1 to 2 Lakh yearly', 'slug' => 'inr_1_2_lakh', 'sort_order' => 20],
            ['name' => 'INR 2 to 4 Lakh yearly', 'slug' => 'inr_2_4_lakh', 'sort_order' => 30],
            ['name' => 'INR 4 to 7 Lakh yearly', 'slug' => 'inr_4_7_lakh', 'sort_order' => 40],
            ['name' => 'INR 7 to 10 Lakh yearly', 'slug' => 'inr_7_10_lakh', 'sort_order' => 50],
            ['name' => 'INR 10 to 15 Lakh yearly', 'slug' => 'inr_10_15_lakh', 'sort_order' => 60],
            ['name' => 'INR 15 to 20 Lakh yearly', 'slug' => 'inr_15_20_lakh', 'sort_order' => 70],
            ['name' => 'INR 20 to 30 Lakh yearly', 'slug' => 'inr_20_30_lakh', 'sort_order' => 80],
            ['name' => 'INR 30 to 50 Lakh yearly', 'slug' => 'inr_30_50_lakh', 'sort_order' => 90],
            ['name' => 'INR 50 Lakh and above', 'slug' => 'inr_50_lakh_above', 'sort_order' => 100],
        ];
        foreach ($rows as $row) {
            DB::table('income_ranges')->updateOrInsert(
                ['slug' => $row['slug']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    private function seedColleges(): void
    {
        $rows = [
            ['name' => 'Savitribai Phule Pune University', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 10],
            ['name' => 'Shivaji University', 'city' => 'Kolhapur', 'state' => 'Maharashtra', 'sort_order' => 20],
            ['name' => 'Mumbai University', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'sort_order' => 30],
            ['name' => 'COEP Technological University', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 40],
            ['name' => 'Government College of Engineering, Pune', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 50],
            ['name' => 'Fergusson College', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 60],
            ['name' => 'Modern College', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 70],
            ['name' => 'Wadia College', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 80],
            ['name' => 'Symbiosis International University', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 90],
            ['name' => 'Bharati Vidyapeeth', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 100],
            ['name' => 'MIT World Peace University', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 110],
            ['name' => 'College Not Listed', 'city' => null, 'state' => null, 'sort_order' => 999],
        ];
        foreach ($rows as $row) {
            $slug = Str::slug($row['name']);
            DB::table('colleges')->updateOrInsert(
                ['slug' => $slug],
                array_merge($row, [
                    'slug' => $slug,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
