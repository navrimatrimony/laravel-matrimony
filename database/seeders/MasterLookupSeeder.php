<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase-5 SSOT: Seed master lookup tables (OPTION-2 Master Table + FK).
 * No ENUMs. Keys are stable; labels are display-only.
 */
class MasterLookupSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedGenders();
        $this->seedMaritalStatuses();
        $this->seedComplexions();
        $this->seedPhysicalBuilds();
        $this->seedBloodGroups();
        $this->seedFamilyTypes();
        $this->seedIncomeCurrencies();
        $this->seedRashis();
        $this->seedNakshatras();
        $this->seedGans();
        $this->seedNadis();
        $this->seedMangalDoshTypes();
        $this->seedYonis();
        $this->seedChildLivingWith();
        $this->seedContactRelations();
        $this->seedAddressTypes();
        $this->seedAssetTypes();
        $this->seedOwnershipTypes();
        $this->seedLegalCaseTypes();
    }

    private function seedGenders(): void
    {
        $rows = [
            ['key' => 'male', 'label' => 'Male'],
            ['key' => 'female', 'label' => 'Female'],
        ];
        foreach ($rows as $row) {
            DB::table('master_genders')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    private function seedMaritalStatuses(): void
    {
        $rows = [
            ['key' => 'never_married', 'label' => 'Never Married'],
            ['key' => 'divorced', 'label' => 'Divorced'],
            ['key' => 'widowed', 'label' => 'Widowed'],
            ['key' => 'separated', 'label' => 'Separated'],
        ];
        foreach ($rows as $row) {
            DB::table('master_marital_statuses')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    private function seedComplexions(): void
    {
        $rows = [
            ['key' => 'very_fair', 'label' => 'Very Fair'],
            ['key' => 'fair', 'label' => 'Fair'],
            ['key' => 'fair_wheatish', 'label' => 'Fair Wheatish'],
            ['key' => 'wheatish', 'label' => 'Wheatish'],
            ['key' => 'wheatish_dark', 'label' => 'Wheatish Dark'],
            ['key' => 'dark', 'label' => 'Dark'],
            ['key' => 'very_dark', 'label' => 'Very Dark'],
            ['key' => 'other', 'label' => 'Other'],
        ];
        foreach ($rows as $row) {
            DB::table('master_complexions')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    private function seedPhysicalBuilds(): void
    {
        $rows = [
            ['key' => 'slim', 'label' => 'Slim'],
            ['key' => 'lean', 'label' => 'Lean'],
            ['key' => 'athletic', 'label' => 'Athletic'],
            ['key' => 'average', 'label' => 'Average'],
            ['key' => 'healthy', 'label' => 'Healthy'],
            ['key' => 'heavy', 'label' => 'Heavy'],
            ['key' => 'muscular', 'label' => 'Muscular'],
        ];
        foreach ($rows as $row) {
            DB::table('master_physical_builds')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    private function seedBloodGroups(): void
    {
        $rows = [
            ['key' => 'A+', 'label' => 'A+'],
            ['key' => 'A-', 'label' => 'A-'],
            ['key' => 'B+', 'label' => 'B+'],
            ['key' => 'B-', 'label' => 'B-'],
            ['key' => 'AB+', 'label' => 'AB+'],
            ['key' => 'AB-', 'label' => 'AB-'],
            ['key' => 'O+', 'label' => 'O+'],
            ['key' => 'O-', 'label' => 'O-'],
        ];
        foreach ($rows as $row) {
            DB::table('master_blood_groups')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    private function seedFamilyTypes(): void
    {
        $rows = [
            ['key' => 'joint', 'label' => 'Joint'],
            ['key' => 'nuclear', 'label' => 'Nuclear'],
            ['key' => 'semi_joint', 'label' => 'Semi Joint'],
            ['key' => 'extended', 'label' => 'Extended'],
            ['key' => 'other', 'label' => 'Other'],
        ];
        foreach ($rows as $row) {
            DB::table('master_family_types')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    private function seedIncomeCurrencies(): void
    {
        $rows = [
            ['code' => 'INR', 'symbol' => '₹', 'is_default' => true],
            ['code' => 'USD', 'symbol' => '$', 'is_default' => false],
            ['code' => 'EUR', 'symbol' => '€', 'is_default' => false],
            ['code' => 'GBP', 'symbol' => '£', 'is_default' => false],
            ['code' => 'AED', 'symbol' => 'د.إ', 'is_default' => false],
            ['code' => 'CAD', 'symbol' => 'C$', 'is_default' => false],
            ['code' => 'AUD', 'symbol' => 'A$', 'is_default' => false],
            ['code' => 'SGD', 'symbol' => 'S$', 'is_default' => false],
        ];
        foreach ($rows as $row) {
            DB::table('master_income_currencies')->updateOrInsert(
                ['code' => $row['code']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    private function seedRashis(): void
    {
        $rows = [
            ['key' => 'mesha', 'label' => 'Mesha (Aries)'],
            ['key' => 'vrishabha', 'label' => 'Vrishabha (Taurus)'],
            ['key' => 'mithuna', 'label' => 'Mithuna (Gemini)'],
            ['key' => 'karka', 'label' => 'Karka (Cancer)'],
            ['key' => 'simha', 'label' => 'Simha (Leo)'],
            ['key' => 'kanya', 'label' => 'Kanya (Virgo)'],
            ['key' => 'tula', 'label' => 'Tula (Libra)'],
            ['key' => 'vrishchika', 'label' => 'Vrishchika (Scorpio)'],
            ['key' => 'dhanu', 'label' => 'Dhanu (Sagittarius)'],
            ['key' => 'makara', 'label' => 'Makara (Capricorn)'],
            ['key' => 'kumbha', 'label' => 'Kumbha (Aquarius)'],
            ['key' => 'meena', 'label' => 'Meena (Pisces)'],
            ['key' => 'other', 'label' => 'Other'],
        ];
        $this->seedKeyLabel('master_rashis', $rows);
    }

    private function seedNakshatras(): void
    {
        $rows = [
            ['key' => 'ashwini', 'label' => 'Ashwini'],
            ['key' => 'bharani', 'label' => 'Bharani'],
            ['key' => 'krittika', 'label' => 'Krittika'],
            ['key' => 'rohini', 'label' => 'Rohini'],
            ['key' => 'mrigashira', 'label' => 'Mrigashira'],
            ['key' => 'ardra', 'label' => 'Ardra'],
            ['key' => 'punarvasu', 'label' => 'Punarvasu'],
            ['key' => 'pushya', 'label' => 'Pushya'],
            ['key' => 'ashlesha', 'label' => 'Ashlesha'],
            ['key' => 'magha', 'label' => 'Magha'],
            ['key' => 'purva_phalguni', 'label' => 'Purva Phalguni'],
            ['key' => 'uttara_phalguni', 'label' => 'Uttara Phalguni'],
            ['key' => 'hasta', 'label' => 'Hasta'],
            ['key' => 'chitra', 'label' => 'Chitra'],
            ['key' => 'swati', 'label' => 'Swati'],
            ['key' => 'vishakha', 'label' => 'Vishakha'],
            ['key' => 'anuradha', 'label' => 'Anuradha'],
            ['key' => 'jyeshtha', 'label' => 'Jyeshtha'],
            ['key' => 'mula', 'label' => 'Mula'],
            ['key' => 'purva_ashadha', 'label' => 'Purva Ashadha'],
            ['key' => 'uttara_ashadha', 'label' => 'Uttara Ashadha'],
            ['key' => 'shravana', 'label' => 'Shravana'],
            ['key' => 'dhanishta', 'label' => 'Dhanishta'],
            ['key' => 'shatabhisha', 'label' => 'Shatabhisha'],
            ['key' => 'purva_bhadrapada', 'label' => 'Purva Bhadrapada'],
            ['key' => 'uttara_bhadrapada', 'label' => 'Uttara Bhadrapada'],
            ['key' => 'revati', 'label' => 'Revati'],
            ['key' => 'other', 'label' => 'Other'],
        ];
        $this->seedKeyLabel('master_nakshatras', $rows);
    }

    private function seedGans(): void
    {
        $this->seedKeyLabel('master_gans', [
            ['key' => 'deva', 'label' => 'Deva'],
            ['key' => 'rakshasa', 'label' => 'Rakshasa'],
            ['key' => 'manav', 'label' => 'Manav'],
            ['key' => 'other', 'label' => 'Other'],
        ]);
    }

    private function seedNadis(): void
    {
        $this->seedKeyLabel('master_nadis', [
            ['key' => 'adi', 'label' => 'Adi'],
            ['key' => 'madhya', 'label' => 'Madhya'],
            ['key' => 'antya', 'label' => 'Antya'],
            ['key' => 'other', 'label' => 'Other'],
        ]);
    }

    private function seedMangalDoshTypes(): void
    {
        $this->seedKeyLabel('master_mangal_dosh_types', [
            ['key' => 'none', 'label' => 'None'],
            ['key' => 'bhumangal', 'label' => 'Bhumangal'],
            ['key' => 'chovamangal', 'label' => 'Chovamangal'],
            ['key' => 'antya_mangal', 'label' => 'Antya Mangal'],
            ['key' => 'other', 'label' => 'Other'],
        ]);
    }

    private function seedYonis(): void
    {
        $rows = [
            ['key' => 'ashwa', 'label' => 'Ashwa'],
            ['key' => 'gaja', 'label' => 'Gaja'],
            ['key' => 'mesha', 'label' => 'Mesha'],
            ['key' => 'sarpa', 'label' => 'Sarpa'],
            ['key' => 'shwan', 'label' => 'Shwan'],
            ['key' => 'marjar', 'label' => 'Marjar'],
            ['key' => 'mushak', 'label' => 'Mushak'],
            ['key' => 'gau', 'label' => 'Gau'],
            ['key' => 'mahish', 'label' => 'Mahish'],
            ['key' => 'vyaghra', 'label' => 'Vyaghra'],
            ['key' => 'mrga', 'label' => 'Mrga'],
            ['key' => 'vanar', 'label' => 'Vanar'],
            ['key' => 'nakul', 'label' => 'Nakul'],
            ['key' => 'singh', 'label' => 'Singh'],
            ['key' => 'other', 'label' => 'Other'],
        ];
        $this->seedKeyLabel('master_yonis', $rows);
    }

    private function seedChildLivingWith(): void
    {
        $this->seedKeyLabel('master_child_living_with', [
            ['key' => 'with_parent', 'label' => 'With me'],
            ['key' => 'with_other_parent', 'label' => 'With other parent'],
            ['key' => 'other', 'label' => 'Other'],
        ]);
    }

    private function seedContactRelations(): void
    {
        $this->seedKeyLabel('master_contact_relations', [
            ['key' => 'self', 'label' => 'Self'],
            ['key' => 'father', 'label' => 'Father'],
            ['key' => 'mother', 'label' => 'Mother'],
            ['key' => 'spouse', 'label' => 'Spouse'],
            ['key' => 'guardian', 'label' => 'Guardian'],
            ['key' => 'sibling', 'label' => 'Sibling'],
            ['key' => 'other', 'label' => 'Other'],
        ]);
    }

    private function seedAddressTypes(): void
    {
        $this->seedKeyLabel('master_address_types', [
            ['key' => 'current', 'label' => 'Current'],
            ['key' => 'permanent', 'label' => 'Permanent'],
            ['key' => 'office', 'label' => 'Office'],
            ['key' => 'other', 'label' => 'Other'],
        ]);
    }

    private function seedAssetTypes(): void
    {
        $this->seedKeyLabel('master_asset_types', [
            ['key' => 'land', 'label' => 'Land'],
            ['key' => 'house', 'label' => 'House'],
            ['key' => 'vehicle', 'label' => 'Vehicle'],
            ['key' => 'gold', 'label' => 'Gold'],
            ['key' => 'financial', 'label' => 'Financial'],
            ['key' => 'other', 'label' => 'Other'],
        ]);
    }

    private function seedOwnershipTypes(): void
    {
        $this->seedKeyLabel('master_ownership_types', [
            ['key' => 'sole', 'label' => 'Sole'],
            ['key' => 'joint', 'label' => 'Joint'],
            ['key' => 'family', 'label' => 'Family'],
            ['key' => 'other', 'label' => 'Other'],
        ]);
    }

    private function seedLegalCaseTypes(): void
    {
        $this->seedKeyLabel('master_legal_case_types', [
            ['key' => 'civil', 'label' => 'Civil'],
            ['key' => 'criminal', 'label' => 'Criminal'],
            ['key' => 'family', 'label' => 'Family'],
            ['key' => 'property', 'label' => 'Property'],
            ['key' => 'other', 'label' => 'Other'],
        ]);
    }

    private function seedKeyLabel(string $table, array $rows): void
    {
        foreach ($rows as $row) {
            DB::table($table)->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
