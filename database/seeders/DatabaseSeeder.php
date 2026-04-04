<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        $this->call([
            FieldRegistryCoreSeeder::class,
            ReligionCasteSubCasteSeeder::class,
            MasterLookupSeeder::class,
            MasterMotherTongueDietLifestyleSeeder::class,
            NakshatraPadaRashiRuleSeeder::class,
            NakshatraAttributesSeeder::class,
            AshtakootaMasterSeeder::class,
            MasterEducationOccupationSeeder::class,
            EducationSeeder::class,
            EducationCareerTemporarySeeder::class,
            TestAdminRolesSeeder::class,
            GeoSeeder::class,
            SubscriptionPlansSeeder::class,
        ]);
    }
}
