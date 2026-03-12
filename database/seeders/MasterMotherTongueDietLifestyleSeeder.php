<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5: Seed master_mother_tongues, master_diets, master_smoking_statuses,
 * master_drinking_statuses, master_mangal_statuses, master_marriage_type_preferences.
 */
class MasterMotherTongueDietLifestyleSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedMotherTongues();
        $this->seedDiets();
        $this->seedSmokingStatuses();
        $this->seedDrinkingStatuses();
        $this->seedMangalStatuses();
        $this->seedMarriageTypePreferences();
    }

    private function seedMotherTongues(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('master_mother_tongues')) {
            return;
        }
        $rows = [
            ['key' => 'marathi', 'label' => 'Marathi', 'sort_order' => 10],
            ['key' => 'hindi', 'label' => 'Hindi', 'sort_order' => 20],
            ['key' => 'english', 'label' => 'English', 'sort_order' => 30],
            ['key' => 'gujarati', 'label' => 'Gujarati', 'sort_order' => 40],
            ['key' => 'tamil', 'label' => 'Tamil', 'sort_order' => 50],
            ['key' => 'telugu', 'label' => 'Telugu', 'sort_order' => 60],
            ['key' => 'kannada', 'label' => 'Kannada', 'sort_order' => 70],
            ['key' => 'bengali', 'label' => 'Bengali', 'sort_order' => 80],
            ['key' => 'other', 'label' => 'Other', 'sort_order' => 999],
        ];
        foreach ($rows as $i => $row) {
            DB::table('master_mother_tongues')->updateOrInsert(
                ['key' => $row['key']],
                ['label' => $row['label'], 'is_active' => true, 'sort_order' => $row['sort_order'], 'updated_at' => now()]
            );
        }
    }

    private function seedDiets(): void
    {
        if (! Schema::hasTable('master_diets')) {
            return;
        }
        $rows = [
            ['key' => 'vegetarian', 'label' => 'Vegetarian', 'sort_order' => 10],
            ['key' => 'eggetarian', 'label' => 'Eggetarian', 'sort_order' => 20],
            ['key' => 'non_vegetarian', 'label' => 'Non-Vegetarian', 'sort_order' => 30],
            ['key' => 'vegan', 'label' => 'Vegan', 'sort_order' => 40],
            ['key' => 'other', 'label' => 'Other', 'sort_order' => 999],
        ];
        foreach ($rows as $row) {
            DB::table('master_diets')->updateOrInsert(
                ['key' => $row['key']],
                ['label' => $row['label'], 'is_active' => true, 'sort_order' => $row['sort_order'], 'updated_at' => now()]
            );
        }
    }

    private function seedSmokingStatuses(): void
    {
        if (! Schema::hasTable('master_smoking_statuses')) {
            return;
        }
        $rows = [
            ['key' => 'no', 'label' => 'No', 'sort_order' => 10],
            ['key' => 'yes', 'label' => 'Yes', 'sort_order' => 20],
            ['key' => 'occasionally', 'label' => 'Occasionally', 'sort_order' => 30],
            ['key' => 'prefer_not_to_say', 'label' => 'Prefer not to say', 'sort_order' => 999],
        ];
        foreach ($rows as $row) {
            DB::table('master_smoking_statuses')->updateOrInsert(
                ['key' => $row['key']],
                ['label' => $row['label'], 'is_active' => true, 'sort_order' => $row['sort_order'], 'updated_at' => now()]
            );
        }
    }

    private function seedDrinkingStatuses(): void
    {
        if (! Schema::hasTable('master_drinking_statuses')) {
            return;
        }
        $rows = [
            ['key' => 'no', 'label' => 'No', 'sort_order' => 10],
            ['key' => 'yes', 'label' => 'Yes', 'sort_order' => 20],
            ['key' => 'occasionally', 'label' => 'Occasionally', 'sort_order' => 30],
            ['key' => 'prefer_not_to_say', 'label' => 'Prefer not to say', 'sort_order' => 999],
        ];
        foreach ($rows as $row) {
            DB::table('master_drinking_statuses')->updateOrInsert(
                ['key' => $row['key']],
                ['label' => $row['label'], 'is_active' => true, 'sort_order' => $row['sort_order'], 'updated_at' => now()]
            );
        }
    }

    private function seedMangalStatuses(): void
    {
        if (! Schema::hasTable('master_mangal_statuses')) {
            return;
        }
        $rows = [
            ['key' => 'yes', 'label' => 'Yes (Mangalik)', 'sort_order' => 10],
            ['key' => 'no', 'label' => 'No (Non-Mangalik)', 'sort_order' => 20],
            ['key' => 'don_t_know', 'label' => 'Don\'t know', 'sort_order' => 30],
        ];
        foreach ($rows as $row) {
            DB::table('master_mangal_statuses')->updateOrInsert(
                ['key' => $row['key']],
                ['label' => $row['label'], 'is_active' => true, 'sort_order' => $row['sort_order'], 'updated_at' => now()]
            );
        }
    }

    private function seedMarriageTypePreferences(): void
    {
        if (! Schema::hasTable('master_marriage_type_preferences')) {
            return;
        }
        $rows = [
            ['key' => 'traditional', 'label' => 'Traditional', 'sort_order' => 10],
            ['key' => 'court', 'label' => 'Court Marriage', 'sort_order' => 20],
            ['key' => 'both', 'label' => 'Open to both', 'sort_order' => 30],
            ['key' => 'other', 'label' => 'Other', 'sort_order' => 999],
        ];
        foreach ($rows as $row) {
            DB::table('master_marriage_type_preferences')->updateOrInsert(
                ['key' => $row['key']],
                ['label' => $row['label'], 'is_active' => true, 'sort_order' => $row['sort_order'], 'updated_at' => now()]
            );
        }
    }
}
