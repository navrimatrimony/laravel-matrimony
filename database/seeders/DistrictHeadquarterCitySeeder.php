<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Creates the missing headquarter-city leaf for Maharashtra districts whose
 * city shares the district's name.
 *
 * The census import produced districts and talukas but, for 20 of the 35
 * Maharashtra districts, no leaf row named after the city itself — so a member
 * from Pune, Nashik or Kolhapur had nothing saveable to pick: typing the name
 * surfaced only the district row, which profile saves rightly reject.
 *
 * Only cities that really exist are listed. Raigad, Sindhudurg and Mumbai
 * suburban are absent on purpose — no city carries those names (their HQs are
 * Alibag, Oros and n/a) — and a member there picks the real leaf instead.
 *
 * Idempotent: rows that already exist (by name under the target taluka) are
 * left untouched.
 *
 * {@code php artisan db:seed --class=Database\\Seeders\\DistrictHeadquarterCitySeeder}
 */
class DistrictHeadquarterCitySeeder extends Seeder
{
    /**
     * District name → taluka (under that district) the HQ city belongs to.
     * Taluka differs from district where the census names it differently:
     * Kolhapur city sits in Karvir, Solapur city in Solapur North,
     * Ahilyanagar city in Nagar, and spellings drift (Buldana, Gondiya).
     */
    private const HQ_CITY_TALUKA_BY_DISTRICT = [
        'Ahilyanagar' => 'Nagar',
        'Amravati' => 'Amravati',
        'Beed' => 'Beed',
        'Bhandara' => 'Bhandara',
        'Buldhana' => 'Buldana',
        'Chandrapur' => 'Chandrapur',
        'Gondia' => 'Gondiya',
        'Jalgaon' => 'Jalgaon',
        'Kolhapur' => 'Karvir',
        'Latur' => 'Latur',
        'Nagpur' => 'Nagpur (city)',
        'Nashik' => 'Nashik',
        'Pune' => 'Pune City',
        'Ratnagiri' => 'Ratnagiri',
        'Solapur' => 'Solapur North',
        'Wardha' => 'Wardha',
        'Washim' => 'Washim',
    ];

    public function run(): void
    {
        $geo = Location::geoTable();
        $maharashtra = DB::table($geo)
            ->where('hierarchy', 'state')
            ->where('name', 'Maharashtra')
            ->first();
        if ($maharashtra === null) {
            $this->command?->warn('Maharashtra state row not found; nothing to do.');

            return;
        }

        foreach (self::HQ_CITY_TALUKA_BY_DISTRICT as $districtName => $talukaName) {
            $district = DB::table($geo)
                ->where('hierarchy', 'district')
                ->where('parent_id', $maharashtra->id)
                ->where('name', $districtName)
                ->first();
            if ($district === null) {
                $this->command?->warn($districtName.': district not found, skipped.');

                continue;
            }

            $taluka = DB::table($geo)
                ->where('hierarchy', 'taluka')
                ->where('parent_id', $district->id)
                ->where('name', $talukaName)
                ->first();
            if ($taluka === null) {
                $this->command?->warn($districtName.': taluka "'.$talukaName.'" not found, skipped.');

                continue;
            }

            $exists = City::query()
                ->where('parent_id', $taluka->id)
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($districtName, 'UTF-8')])
                ->exists();
            if ($exists) {
                continue;
            }

            $city = City::query()->create([
                'taluka_id' => (int) $taluka->id,
                'name' => $districtName,
            ]);
            // The city is the district's namesake, so the district row already
            // carries the Marathi spelling to reuse.
            if (filled($district->name_mr ?? null)) {
                $city->name_mr = $district->name_mr;
                $city->save();
            }
            $this->command?->info($districtName.': city leaf created under '.$talukaName.' (id '.$city->id.').');
        }
    }
}
