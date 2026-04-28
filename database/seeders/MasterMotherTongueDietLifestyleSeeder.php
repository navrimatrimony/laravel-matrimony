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

    /** @param  array<string, mixed>  $payload */
    private function applyKeyTable(string $table, string $key, array $payload): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        if (! Schema::hasColumn($table, 'label_mr')) {
            unset($payload['label_mr']);
        }
        DB::table($table)->updateOrInsert(
            ['key' => $key],
            array_merge($payload, ['is_active' => true, 'updated_at' => now()])
        );
    }

    private function seedMotherTongues(): void
    {
        $rows = [
            ['key' => 'marathi', 'label' => 'Marathi', 'label_mr' => 'मराठी', 'sort_order' => 10],
            ['key' => 'hindi', 'label' => 'Hindi', 'label_mr' => 'हिंदी', 'sort_order' => 20],
            ['key' => 'english', 'label' => 'English', 'label_mr' => 'इंग्रजी', 'sort_order' => 30],
            ['key' => 'gujarati', 'label' => 'Gujarati', 'label_mr' => 'गुजराती', 'sort_order' => 40],
            ['key' => 'tamil', 'label' => 'Tamil', 'label_mr' => 'तमिळ', 'sort_order' => 50],
            ['key' => 'telugu', 'label' => 'Telugu', 'label_mr' => 'तेलगू', 'sort_order' => 60],
            ['key' => 'kannada', 'label' => 'Kannada', 'label_mr' => 'कन्नड', 'sort_order' => 70],
            ['key' => 'bengali', 'label' => 'Bengali', 'label_mr' => 'बंगाली', 'sort_order' => 80],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर', 'sort_order' => 999],
        ];
        foreach ($rows as $row) {
            $key = $row['key'];
            unset($row['key']);
            $this->applyKeyTable('master_mother_tongues', $key, $row);
        }
    }

    private function seedDiets(): void
    {
        $rows = [
            ['key' => 'vegetarian', 'label' => 'Vegetarian', 'label_mr' => 'शाकाहारी', 'sort_order' => 10],
            ['key' => 'eggetarian', 'label' => 'Eggetarian', 'label_mr' => 'अंडे खातात (मांस नाही)', 'sort_order' => 20],
            ['key' => 'non_vegetarian', 'label' => 'Non-Vegetarian', 'label_mr' => 'मांसाहारी', 'sort_order' => 30],
            ['key' => 'vegan', 'label' => 'Vegan', 'label_mr' => 'व्हिगन', 'sort_order' => 40],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर', 'sort_order' => 999],
        ];
        foreach ($rows as $row) {
            $key = $row['key'];
            unset($row['key']);
            $this->applyKeyTable('master_diets', $key, $row);
        }
    }

    private function seedSmokingStatuses(): void
    {
        $rows = [
            ['key' => 'no', 'label' => 'No', 'label_mr' => 'नाही', 'sort_order' => 10],
            ['key' => 'yes', 'label' => 'Yes', 'label_mr' => 'होय', 'sort_order' => 20],
            ['key' => 'occasionally', 'label' => 'Occasionally', 'label_mr' => 'कधीतरी', 'sort_order' => 30],
            ['key' => 'prefer_not_to_say', 'label' => 'Prefer not to say', 'label_mr' => 'सांगू इच्छित नाही', 'sort_order' => 999],
        ];
        foreach ($rows as $row) {
            $key = $row['key'];
            unset($row['key']);
            $this->applyKeyTable('master_smoking_statuses', $key, $row);
        }
    }

    private function seedDrinkingStatuses(): void
    {
        $rows = [
            ['key' => 'no', 'label' => 'No', 'label_mr' => 'नाही', 'sort_order' => 10],
            ['key' => 'yes', 'label' => 'Yes', 'label_mr' => 'होय', 'sort_order' => 20],
            ['key' => 'occasionally', 'label' => 'Occasionally', 'label_mr' => 'कधीतरी', 'sort_order' => 30],
            ['key' => 'prefer_not_to_say', 'label' => 'Prefer not to say', 'label_mr' => 'सांगू इच्छित नाही', 'sort_order' => 999],
        ];
        foreach ($rows as $row) {
            $key = $row['key'];
            unset($row['key']);
            $this->applyKeyTable('master_drinking_statuses', $key, $row);
        }
    }

    private function seedMangalStatuses(): void
    {
        $rows = [
            ['key' => 'yes', 'label' => 'Yes (Mangalik)', 'label_mr' => 'होय (मंगळिक)', 'sort_order' => 10],
            ['key' => 'no', 'label' => 'No (Non-Mangalik)', 'label_mr' => 'नाही (नॉन-मंगळिक)', 'sort_order' => 20],
            ['key' => 'don_t_know', 'label' => 'Don\'t know', 'label_mr' => 'माहीत नाही', 'sort_order' => 30],
        ];
        foreach ($rows as $row) {
            $key = $row['key'];
            unset($row['key']);
            $this->applyKeyTable('master_mangal_statuses', $key, $row);
        }
    }

    private function seedMarriageTypePreferences(): void
    {
        $rows = [
            ['key' => 'traditional', 'label' => 'Traditional', 'label_mr' => 'पारंपारिक विवाह', 'sort_order' => 10],
            ['key' => 'court', 'label' => 'Court Marriage', 'label_mr' => 'कोर्ट मॅरेज', 'sort_order' => 20],
            ['key' => 'both', 'label' => 'Open to both', 'label_mr' => 'दोन्ही चालतील', 'sort_order' => 30],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर', 'sort_order' => 999],
        ];
        foreach ($rows as $row) {
            $key = $row['key'];
            unset($row['key']);
            $this->applyKeyTable('master_marriage_type_preferences', $key, $row);
        }
    }
}
