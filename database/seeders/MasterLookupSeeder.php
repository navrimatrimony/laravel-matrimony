<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    /** @param  array<string, mixed>  $row */
    private function mergeKeyLabelRow(array $row, string $table): array
    {
        if (! Schema::hasColumn($table, 'label_mr')) {
            unset($row['label_mr']);
        }

        return array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function seedGenders(): void
    {
        $rows = [
            ['key' => 'male', 'label' => 'Male', 'label_mr' => 'पुरुष'],
            ['key' => 'female', 'label' => 'Female', 'label_mr' => 'स्त्री'],
        ];
        foreach ($rows as $row) {
            DB::table('master_genders')->updateOrInsert(
                ['key' => $row['key']],
                $this->mergeKeyLabelRow($row, 'master_genders')
            );
        }
    }

    private function seedMaritalStatuses(): void
    {
        $rows = [
            ['key' => 'never_married', 'label' => 'Never Married', 'label_mr' => 'अविवाहित'],
            ['key' => 'divorced', 'label' => 'Divorced', 'label_mr' => 'घटस्फोटित'],
            ['key' => 'annulled', 'label' => 'Annulled', 'label_mr' => 'विवाह रद्द'],
            ['key' => 'separated', 'label' => 'Separated', 'label_mr' => 'वेगळे राहतात'],
            ['key' => 'widowed', 'label' => 'Widowed', 'label_mr' => 'विधवा / विधुर'],
        ];
        foreach ($rows as $row) {
            DB::table('master_marital_statuses')->updateOrInsert(
                ['key' => $row['key']],
                $this->mergeKeyLabelRow($row, 'master_marital_statuses')
            );
        }
    }

    private function seedComplexions(): void
    {
        $rows = [
            ['key' => 'very_fair', 'label' => 'Very Fair', 'label_mr' => 'अतिशय गोरी'],
            ['key' => 'fair', 'label' => 'Fair', 'label_mr' => 'गोरी'],
            ['key' => 'fair_wheatish', 'label' => 'Fair Wheatish', 'label_mr' => 'गोरी गहिर्या'],
            ['key' => 'wheatish', 'label' => 'Wheatish', 'label_mr' => 'गहिर्या'],
            ['key' => 'wheatish_medium', 'label' => 'Wheatish Medium', 'label_mr' => 'मध्यम गहिर्या'],
            ['key' => 'wheatish_dark', 'label' => 'Wheatish Dark', 'label_mr' => 'गडद गहिर्या'],
            ['key' => 'dusky', 'label' => 'Dusky', 'label_mr' => 'सावळी'],
            ['key' => 'dark', 'label' => 'Dark', 'label_mr' => 'गडद'],
            ['key' => 'very_dark', 'label' => 'Very Dark', 'label_mr' => 'अतिशय गडद'],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर'],
        ];
        foreach ($rows as $row) {
            DB::table('master_complexions')->updateOrInsert(
                ['key' => $row['key']],
                $this->mergeKeyLabelRow($row, 'master_complexions')
            );
        }
    }

    private function seedPhysicalBuilds(): void
    {
        $rows = [
            ['key' => 'slim', 'label' => 'Slim', 'label_mr' => 'पातळ'],
            ['key' => 'lean', 'label' => 'Lean', 'label_mr' => 'दुबळा'],
            ['key' => 'athletic', 'label' => 'Athletic', 'label_mr' => 'अ‍ॅथलेटिक'],
            ['key' => 'average', 'label' => 'Average', 'label_mr' => 'सरासरी'],
            ['key' => 'fit', 'label' => 'Fit', 'label_mr' => 'फिट'],
            ['key' => 'healthy', 'label' => 'Healthy', 'label_mr' => 'निरोगी'],
            ['key' => 'heavy', 'label' => 'Heavy', 'label_mr' => 'जड'],
            ['key' => 'muscular', 'label' => 'Muscular', 'label_mr' => 'स्नायूबद्ध'],
        ];
        foreach ($rows as $row) {
            DB::table('master_physical_builds')->updateOrInsert(
                ['key' => $row['key']],
                $this->mergeKeyLabelRow($row, 'master_physical_builds')
            );
        }
    }

    private function seedBloodGroups(): void
    {
        $rows = [
            ['key' => 'A+', 'label' => 'A+', 'label_mr' => 'ए पॉझिटिव्ह'],
            ['key' => 'A-', 'label' => 'A-', 'label_mr' => 'ए निगेटिव्ह'],
            ['key' => 'B+', 'label' => 'B+', 'label_mr' => 'बी पॉझिटिव्ह'],
            ['key' => 'B-', 'label' => 'B-', 'label_mr' => 'बी निगेटिव्ह'],
            ['key' => 'AB+', 'label' => 'AB+', 'label_mr' => 'एबी पॉझिटिव्ह'],
            ['key' => 'AB-', 'label' => 'AB-', 'label_mr' => 'एबी निगेटिव्ह'],
            ['key' => 'O+', 'label' => 'O+', 'label_mr' => 'ओ पॉझिटिव्ह'],
            ['key' => 'O-', 'label' => 'O-', 'label_mr' => 'ओ निगेटिव्ह'],
            ['key' => 'not_known', 'label' => 'Not Known', 'label_mr' => 'माहीत नाही'],
            ['key' => 'prefer_not_to_say', 'label' => 'Prefer Not To Say', 'label_mr' => 'सांगू इच्छित नाही'],
        ];
        foreach ($rows as $row) {
            DB::table('master_blood_groups')->updateOrInsert(
                ['key' => $row['key']],
                $this->mergeKeyLabelRow($row, 'master_blood_groups')
            );
        }
    }

    private function seedFamilyTypes(): void
    {
        $rows = [
            ['key' => 'joint', 'label' => 'Joint', 'label_mr' => 'संयुक्त कुटुंब'],
            ['key' => 'nuclear', 'label' => 'Nuclear', 'label_mr' => 'अणुकुटुंब'],
            ['key' => 'semi_joint', 'label' => 'Semi Joint', 'label_mr' => 'अर्धसंयुक्त'],
            ['key' => 'extended', 'label' => 'Extended', 'label_mr' => 'विस्तृत कुटुंब'],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर'],
        ];
        foreach ($rows as $row) {
            DB::table('master_family_types')->updateOrInsert(
                ['key' => $row['key']],
                $this->mergeKeyLabelRow($row, 'master_family_types')
            );
        }
    }

    private function seedIncomeCurrencies(): void
    {
        $rows = [
            ['code' => 'INR', 'symbol' => '₹', 'label_mr' => 'भारतीय रुपया', 'is_default' => true],
            ['code' => 'USD', 'symbol' => '$', 'label_mr' => 'अमेरिकन डॉलर', 'is_default' => false],
            ['code' => 'EUR', 'symbol' => '€', 'label_mr' => 'युरो', 'is_default' => false],
            ['code' => 'GBP', 'symbol' => '£', 'label_mr' => 'ब्रिटिश पाउंड', 'is_default' => false],
            ['code' => 'AED', 'symbol' => 'د.إ', 'label_mr' => 'युएई दिरहॅम', 'is_default' => false],
            ['code' => 'CAD', 'symbol' => 'C$', 'label_mr' => 'कॅनेडियन डॉलर', 'is_default' => false],
            ['code' => 'AUD', 'symbol' => 'A$', 'label_mr' => 'ऑस्ट्रेलियन डॉलर', 'is_default' => false],
            ['code' => 'SGD', 'symbol' => 'S$', 'label_mr' => 'सिंगापूर डॉलर', 'is_default' => false],
        ];
        $hasMr = Schema::hasColumn('master_income_currencies', 'label_mr');
        foreach ($rows as $row) {
            if (! $hasMr) {
                unset($row['label_mr']);
            }
            DB::table('master_income_currencies')->updateOrInsert(
                ['code' => $row['code']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    private function seedRashis(): void
    {
        $rows = [
            ['key' => 'mesha', 'label' => 'Mesha (Aries)', 'label_mr' => 'मेष'],
            ['key' => 'vrishabha', 'label' => 'Vrishabha (Taurus)', 'label_mr' => 'वृषभ'],
            ['key' => 'mithuna', 'label' => 'Mithuna (Gemini)', 'label_mr' => 'मिथुन'],
            ['key' => 'karka', 'label' => 'Karka (Cancer)', 'label_mr' => 'कर्क'],
            ['key' => 'simha', 'label' => 'Simha (Leo)', 'label_mr' => 'सिंह'],
            ['key' => 'kanya', 'label' => 'Kanya (Virgo)', 'label_mr' => 'कन्या'],
            ['key' => 'tula', 'label' => 'Tula (Libra)', 'label_mr' => 'तूला'],
            ['key' => 'vrishchika', 'label' => 'Vrishchika (Scorpio)', 'label_mr' => 'वृश्चिक'],
            ['key' => 'dhanu', 'label' => 'Dhanu (Sagittarius)', 'label_mr' => 'धनु'],
            ['key' => 'makara', 'label' => 'Makara (Capricorn)', 'label_mr' => 'मकर'],
            ['key' => 'kumbha', 'label' => 'Kumbha (Aquarius)', 'label_mr' => 'कुंभ'],
            ['key' => 'meena', 'label' => 'Meena (Pisces)', 'label_mr' => 'मीन'],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर'],
        ];
        $this->seedKeyLabel('master_rashis', $rows);
    }

    private function seedNakshatras(): void
    {
        $rows = [
            ['key' => 'ashwini', 'label' => 'Ashwini', 'label_mr' => 'अश्विनी'],
            ['key' => 'bharani', 'label' => 'Bharani', 'label_mr' => 'भरणी'],
            ['key' => 'krittika', 'label' => 'Krittika', 'label_mr' => 'कृत्तिका'],
            ['key' => 'rohini', 'label' => 'Rohini', 'label_mr' => 'रोहिणी'],
            ['key' => 'mrigashira', 'label' => 'Mrigashira', 'label_mr' => 'मृगशिरा'],
            ['key' => 'ardra', 'label' => 'Ardra', 'label_mr' => 'आर्द्रा'],
            ['key' => 'punarvasu', 'label' => 'Punarvasu', 'label_mr' => 'पुनर्वसू'],
            ['key' => 'pushya', 'label' => 'Pushya', 'label_mr' => 'पुष्य'],
            ['key' => 'ashlesha', 'label' => 'Ashlesha', 'label_mr' => 'आश्लेषा'],
            ['key' => 'magha', 'label' => 'Magha', 'label_mr' => 'मघा'],
            ['key' => 'purva_phalguni', 'label' => 'Purva Phalguni', 'label_mr' => 'पूर्व फाल्गुणी'],
            ['key' => 'uttara_phalguni', 'label' => 'Uttara Phalguni', 'label_mr' => 'उत्तर फाल्गुणी'],
            ['key' => 'hasta', 'label' => 'Hasta', 'label_mr' => 'हस्त'],
            ['key' => 'chitra', 'label' => 'Chitra', 'label_mr' => 'चित्रा'],
            ['key' => 'swati', 'label' => 'Swati', 'label_mr' => 'स्वाती'],
            ['key' => 'vishakha', 'label' => 'Vishakha', 'label_mr' => 'विशाखा'],
            ['key' => 'anuradha', 'label' => 'Anuradha', 'label_mr' => 'अनुराधा'],
            ['key' => 'jyeshtha', 'label' => 'Jyeshtha', 'label_mr' => 'ज्येष्ठा'],
            ['key' => 'mula', 'label' => 'Mula', 'label_mr' => 'मूळ'],
            ['key' => 'purva_ashadha', 'label' => 'Purva Ashadha', 'label_mr' => 'पूर्वाषाढा'],
            ['key' => 'uttara_ashadha', 'label' => 'Uttara Ashadha', 'label_mr' => 'उत्तराषाढा'],
            ['key' => 'shravana', 'label' => 'Shravana', 'label_mr' => 'श्रवण'],
            ['key' => 'dhanishta', 'label' => 'Dhanishta', 'label_mr' => 'धनिष्ठा'],
            ['key' => 'shatabhisha', 'label' => 'Shatabhisha', 'label_mr' => 'शतभिषा'],
            ['key' => 'purva_bhadrapada', 'label' => 'Purva Bhadrapada', 'label_mr' => 'पूर्व भाद्रपद'],
            ['key' => 'uttara_bhadrapada', 'label' => 'Uttara Bhadrapada', 'label_mr' => 'उत्तर भाद्रपद'],
            ['key' => 'revati', 'label' => 'Revati', 'label_mr' => 'रेवती'],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर'],
        ];
        $this->seedKeyLabel('master_nakshatras', $rows);
    }

    private function seedGans(): void
    {
        $this->seedKeyLabel('master_gans', [
            ['key' => 'deva', 'label' => 'Deva', 'label_mr' => 'देव'],
            ['key' => 'rakshasa', 'label' => 'Rakshasa', 'label_mr' => 'राक्षस'],
            ['key' => 'manav', 'label' => 'Manav', 'label_mr' => 'मानव'],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर'],
        ]);
    }

    private function seedNadis(): void
    {
        $this->seedKeyLabel('master_nadis', [
            ['key' => 'adi', 'label' => 'Adi', 'label_mr' => 'आदि'],
            ['key' => 'madhya', 'label' => 'Madhya', 'label_mr' => 'मध्य'],
            ['key' => 'antya', 'label' => 'Antya', 'label_mr' => 'अंत्य'],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर'],
        ]);
    }

    private function seedMangalDoshTypes(): void
    {
        $this->seedKeyLabel('master_mangal_dosh_types', [
            ['key' => 'none', 'label' => 'No / Non-Manglik (नाही)', 'label_mr' => 'नाही / नॉन-मंगळिक'],
            ['key' => 'bhumangal', 'label' => 'Yes / Manglik (हो/आहे)', 'label_mr' => 'हो / मंगळिक'],
            ['key' => 'anshik_mangal', 'label' => 'Anshik Mangal / Soumya Mangal (सौम्य मंगळ)', 'label_mr' => 'अंशिक मंगळ / सौम्य मंगळ'],
            ['key' => 'don_t_know', 'label' => 'Don\'t Know (माहित नाही)', 'label_mr' => 'माहीत नाही'],
            ['key' => 'chovamangal', 'label' => 'Chovamangal', 'label_mr' => 'चोवमंगळ'],
            ['key' => 'antya_mangal', 'label' => 'Antya Mangal', 'label_mr' => 'अंत्य मंगळ'],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर'],
        ]);
    }

    /** Plain yoni names only (profile entry scope). No male/female variants, no duplicates. */
    private function seedYonis(): void
    {
        $rows = [
            ['key' => 'horse', 'label' => 'Horse', 'label_mr' => 'घोडा'],
            ['key' => 'elephant', 'label' => 'Elephant', 'label_mr' => 'हत्ती'],
            ['key' => 'sheep', 'label' => 'Sheep', 'label_mr' => 'मेंढी'],
            ['key' => 'serpent', 'label' => 'Serpent', 'label_mr' => 'साप'],
            ['key' => 'dog', 'label' => 'Dog', 'label_mr' => 'कुत्रा'],
            ['key' => 'cat', 'label' => 'Cat', 'label_mr' => 'मांजर'],
            ['key' => 'rat', 'label' => 'Rat', 'label_mr' => 'उंदीर'],
            ['key' => 'cow', 'label' => 'Cow', 'label_mr' => 'गाय'],
            ['key' => 'buffalo', 'label' => 'Buffalo', 'label_mr' => 'म्हैस'],
            ['key' => 'tiger', 'label' => 'Tiger', 'label_mr' => 'वाघ'],
            ['key' => 'deer', 'label' => 'Deer', 'label_mr' => 'हरीण'],
            ['key' => 'monkey', 'label' => 'Monkey', 'label_mr' => 'वानर'],
            ['key' => 'lion', 'label' => 'Lion', 'label_mr' => 'सिंह'],
            ['key' => 'mongoose', 'label' => 'Mongoose', 'label_mr' => 'नेवाळा'],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर'],
        ];
        $this->seedKeyLabel('master_yonis', $rows);
    }

    private function seedChildLivingWith(): void
    {
        $this->seedKeyLabel('master_child_living_with', [
            ['key' => 'with_parent', 'label' => 'With me', 'label_mr' => 'माझ्याबरोबर'],
            ['key' => 'with_other_parent', 'label' => 'With other parent', 'label_mr' => 'दुसऱ्या पालकाबरोबर'],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर'],
        ]);
    }

    private function seedContactRelations(): void
    {
        $this->seedKeyLabel('master_contact_relations', [
            ['key' => 'self', 'label' => 'Self', 'label_mr' => 'स्वतः'],
            ['key' => 'father', 'label' => 'Father', 'label_mr' => 'वडील'],
            ['key' => 'mother', 'label' => 'Mother', 'label_mr' => 'आई'],
            ['key' => 'spouse', 'label' => 'Spouse', 'label_mr' => 'जोडीदार'],
            ['key' => 'guardian', 'label' => 'Guardian', 'label_mr' => 'पालक'],
            ['key' => 'sibling', 'label' => 'Sibling', 'label_mr' => 'भाऊबहीण'],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर'],
        ]);
    }

    private function seedAddressTypes(): void
    {
        $this->seedKeyLabel('master_address_types', [
            ['key' => 'current', 'label' => 'Current', 'label_mr' => 'सध्याचे'],
            ['key' => 'permanent', 'label' => 'Permanent', 'label_mr' => 'कायमचे'],
            ['key' => 'office', 'label' => 'Office', 'label_mr' => 'कार्यालय'],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर'],
        ]);
    }

    private function seedAssetTypes(): void
    {
        $this->seedKeyLabel('master_asset_types', [
            ['key' => 'land', 'label' => 'Land', 'label_mr' => 'जमीन'],
            ['key' => 'house', 'label' => 'House', 'label_mr' => 'घर'],
            ['key' => 'vehicle', 'label' => 'Vehicle', 'label_mr' => 'वाहन'],
            ['key' => 'gold', 'label' => 'Gold', 'label_mr' => 'सोने'],
            ['key' => 'financial', 'label' => 'Financial', 'label_mr' => 'आर्थिक'],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर'],
        ]);
    }

    private function seedOwnershipTypes(): void
    {
        $this->seedKeyLabel('master_ownership_types', [
            ['key' => 'sole', 'label' => 'Sole', 'label_mr' => 'एकटे मालकी'],
            ['key' => 'joint', 'label' => 'Joint', 'label_mr' => 'संयुक्त मालकी'],
            ['key' => 'family', 'label' => 'Family', 'label_mr' => 'कौटुंबिक'],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर'],
        ]);
    }

    private function seedLegalCaseTypes(): void
    {
        $this->seedKeyLabel('master_legal_case_types', [
            ['key' => 'civil', 'label' => 'Civil', 'label_mr' => 'दिवाणी'],
            ['key' => 'criminal', 'label' => 'Criminal', 'label_mr' => 'फौजदारी'],
            ['key' => 'family', 'label' => 'Family', 'label_mr' => 'कौटुंबिक'],
            ['key' => 'property', 'label' => 'Property', 'label_mr' => 'मालमत्ता'],
            ['key' => 'other', 'label' => 'Other', 'label_mr' => 'इतर'],
        ]);
    }

    private function seedKeyLabel(string $table, array $rows): void
    {
        foreach ($rows as $row) {
            DB::table($table)->updateOrInsert(
                ['key' => $row['key']],
                $this->mergeKeyLabelRow($row, $table)
            );
        }
    }
}
