<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            ['name' => 'Private Company', 'name_mr' => 'खाजगी कंपनी', 'slug' => 'private_company', 'sort_order' => 10],
            ['name' => 'Government / Public Sector', 'name_mr' => 'शासकीय / सार्वजनिक क्षेत्र', 'slug' => 'government_public_sector', 'sort_order' => 20],
            ['name' => 'Defense / Civil Services', 'name_mr' => 'संरक्षण / लोकसेवा', 'slug' => 'defense_civil_services', 'sort_order' => 30],
            ['name' => 'Business / Self Employed', 'name_mr' => 'व्यवसाय / स्वयंरोजगार', 'slug' => 'business_self_employed', 'sort_order' => 40],
            ['name' => 'Not Working', 'name_mr' => 'नोकरी नाही', 'slug' => 'not_working', 'sort_order' => 50],
        ];
        $hasMr = Schema::hasColumn('working_with_types', 'name_mr');
        foreach ($rows as $row) {
            if (! $hasMr) {
                unset($row['name_mr']);
            }
            DB::table('working_with_types')->updateOrInsert(
                ['slug' => $row['slug']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    /**
     * Profession name => working_with_type slug (one-to-one for dependency).
     *
     * @return array<string, string>
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

    /**
     * @return array<string, string>
     */
    private function professionNameMrByEnglish(): array
    {
        return [
            'Software Professional' => 'सॉफ्टवेअर व्यावसायिक',
            'Advertising Professional' => 'जाहिरात व्यावसायिक',
            'Banking Professional' => 'बँकिंग व्यावसायिक',
            'Teacher' => 'शिक्षक',
            'Professor' => 'प्राध्यापक',
            'Doctor' => 'डॉक्टर',
            'Nurse' => 'नर्स',
            'Engineer' => 'अभियंता',
            'Civil Engineer' => 'सिव्हिल अभियंता',
            'Mechanical Engineer' => 'यांत्रिक अभियंता',
            'Accountant' => 'लेखापाल',
            'Chartered Accountant' => 'चार्टर्ड अकाउंटंट',
            'Lawyer' => 'वकील',
            'Architect' => 'वास्तुविशारद',
            'Designer' => 'डिझायनर',
            'Fashion Designer' => 'फॅशन डिझायनर',
            'Admin Professional' => 'प्रशासकीय व्यावसायिक',
            'HR Professional' => 'मानव संसाधन व्यावसायिक',
            'Sales Professional' => 'विक्री व्यावसायिक',
            'Marketing Professional' => 'विपणन व्यावसायिक',
            'Consultant' => 'सल्लागार',
            'Entrepreneur' => 'उद्योजक',
            'Business Owner' => 'व्यवसाय मालक',
            'Hotel Professional' => 'हॉटेल व्यावसायिक',
            'Media Professional' => 'माध्यम व्यावसायिक',
            'Journalist' => 'पत्रकार',
            'Scientist' => 'शास्त्रज्ञ',
            'Research Scholar' => 'संशोधन विद्यार्थी',
            'Pharmacist' => 'फार्मासिस्ट',
            'Therapist' => 'चिकित्सक',
            'Police Officer' => 'पोलीस अधिकारी',
            'Army Personnel' => 'लष्करी जवान',
            'Clerk' => 'लिपिक',
            'Student' => 'विद्यार्थी',
            'Not Working' => 'नोकरी नाही',
        ];
    }

    private function seedProfessions(): void
    {
        $typeIdsBySlug = DB::table('working_with_types')->pluck('id', 'slug')->toArray();
        $mapping = $this->getProfessionMapping();
        $mrMap = $this->professionNameMrByEnglish();
        $hasMr = Schema::hasColumn('professions', 'name_mr');
        $sort = 10;
        foreach ($mapping as $name => $workingWithSlug) {
            $slug = Str::slug($name);
            $workingWithTypeId = isset($typeIdsBySlug[$workingWithSlug]) ? $typeIdsBySlug[$workingWithSlug] : null;
            $payload = [
                'working_with_type_id' => $workingWithTypeId,
                'name' => $name,
                'slug' => $slug,
                'sort_order' => $sort,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($hasMr && isset($mrMap[$name])) {
                $payload['name_mr'] = $mrMap[$name];
            }
            DB::table('professions')->updateOrInsert(
                ['slug' => $slug],
                $payload
            );
            $sort += 10;
        }
    }

    private function seedIncomeRanges(): void
    {
        $rows = [
            ['name' => 'No Income', 'name_mr' => 'उत्पन्न नाही', 'slug' => 'no_income', 'sort_order' => 10],
            ['name' => 'INR 1 to 2 Lakh yearly', 'name_mr' => 'वार्षिक १ ते २ लाख रु.', 'slug' => 'inr_1_2_lakh', 'sort_order' => 20],
            ['name' => 'INR 2 to 4 Lakh yearly', 'name_mr' => 'वार्षिक २ ते ४ लाख रु.', 'slug' => 'inr_2_4_lakh', 'sort_order' => 30],
            ['name' => 'INR 4 to 7 Lakh yearly', 'name_mr' => 'वार्षिक ४ ते ७ लाख रु.', 'slug' => 'inr_4_7_lakh', 'sort_order' => 40],
            ['name' => 'INR 7 to 10 Lakh yearly', 'name_mr' => 'वार्षिक ७ ते १० लाख रु.', 'slug' => 'inr_7_10_lakh', 'sort_order' => 50],
            ['name' => 'INR 10 to 15 Lakh yearly', 'name_mr' => 'वार्षिक १० ते १५ लाख रु.', 'slug' => 'inr_10_15_lakh', 'sort_order' => 60],
            ['name' => 'INR 15 to 20 Lakh yearly', 'name_mr' => 'वार्षिक १५ ते २० लाख रु.', 'slug' => 'inr_15_20_lakh', 'sort_order' => 70],
            ['name' => 'INR 20 to 30 Lakh yearly', 'name_mr' => 'वार्षिक २० ते ३० लाख रु.', 'slug' => 'inr_20_30_lakh', 'sort_order' => 80],
            ['name' => 'INR 30 to 50 Lakh yearly', 'name_mr' => 'वार्षिक ३० ते ५० लाख रु.', 'slug' => 'inr_30_50_lakh', 'sort_order' => 90],
            ['name' => 'INR 50 Lakh and above', 'name_mr' => '५० लाखांपेक्षा जास्त', 'slug' => 'inr_50_lakh_above', 'sort_order' => 100],
        ];
        $hasMr = Schema::hasColumn('income_ranges', 'name_mr');
        foreach ($rows as $row) {
            if (! $hasMr) {
                unset($row['name_mr']);
            }
            DB::table('income_ranges')->updateOrInsert(
                ['slug' => $row['slug']],
                array_merge($row, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    private function seedColleges(): void
    {
        $rows = [
            ['name' => 'Savitribai Phule Pune University', 'name_mr' => 'सावित्रीबाई फुले पुणे विद्यापीठ', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 10],
            ['name' => 'Shivaji University', 'name_mr' => 'शिवाजी विद्यापीठ', 'city' => 'Kolhapur', 'state' => 'Maharashtra', 'sort_order' => 20],
            ['name' => 'Mumbai University', 'name_mr' => 'मुंबई विद्यापीठ', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'sort_order' => 30],
            ['name' => 'COEP Technological University', 'name_mr' => 'सीओईपी तांत्रिक विद्यापीठ', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 40],
            ['name' => 'Government College of Engineering, Pune', 'name_mr' => 'सरकारी अभियांत्रिकी महाविद्यालय, पुणे', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 50],
            ['name' => 'Fergusson College', 'name_mr' => 'फर्ग्युसन महाविद्यालय', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 60],
            ['name' => 'Modern College', 'name_mr' => 'मॉडर्न महाविद्यालय', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 70],
            ['name' => 'Wadia College', 'name_mr' => 'वाडिया महाविद्यालय', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 80],
            ['name' => 'Symbiosis International University', 'name_mr' => 'सिंबायॉसिस आंतरराष्ट्रीय विद्यापीठ', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 90],
            ['name' => 'Bharati Vidyapeeth', 'name_mr' => 'भारती विद्यापीठ', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 100],
            ['name' => 'MIT World Peace University', 'name_mr' => 'एमआयटी विश्व शांती विद्यापीठ', 'city' => 'Pune', 'state' => 'Maharashtra', 'sort_order' => 110],
            ['name' => 'College Not Listed', 'name_mr' => 'महाविद्यालय सूचीत नाही', 'city' => null, 'state' => null, 'sort_order' => 999],
        ];
        $hasMr = Schema::hasColumn('colleges', 'name_mr');
        foreach ($rows as $row) {
            if (! $hasMr) {
                unset($row['name_mr']);
            }
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
