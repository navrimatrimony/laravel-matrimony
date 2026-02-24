<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\State;
use App\Models\District;
use App\Models\Taluka;
use App\Models\City;
use Illuminate\Database\Seeder;

/**
 * Phase-4 Location Master Data.
 * Populates location hierarchy so profile create dropdowns are not empty.
 * Uses firstOrCreate so safe to run after MinimalLocationSeeder / LocationEnrichmentSeeder.
 */
class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $india = Country::firstOrCreate(['name' => 'India']);
        $usa = Country::firstOrCreate(['name' => 'USA']);

        $maharashtra = State::firstOrCreate(
            ['country_id' => $india->id, 'name' => 'Maharashtra']
        );
        $karnataka = State::firstOrCreate(
            ['country_id' => $india->id, 'name' => 'Karnataka']
        );
        $gujarat = State::firstOrCreate(
            ['country_id' => $india->id, 'name' => 'Gujarat']
        );

        $pune = District::firstOrCreate(
            ['state_id' => $maharashtra->id, 'name' => 'Pune']
        );
        $kolhapur = District::firstOrCreate(
            ['state_id' => $maharashtra->id, 'name' => 'Kolhapur']
        );
        $satara = District::firstOrCreate(
            ['state_id' => $maharashtra->id, 'name' => 'Satara']
        );

        $haveli = Taluka::firstOrCreate(
            ['district_id' => $pune->id, 'name' => 'Haveli']
        );
        $mulshi = Taluka::firstOrCreate(
            ['district_id' => $pune->id, 'name' => 'Mulshi']
        );

        City::firstOrCreate(['taluka_id' => $haveli->id, 'name' => 'Pune']);
        City::firstOrCreate(['taluka_id' => $haveli->id, 'name' => 'Pimpri-Chinchwad']);
        City::firstOrCreate(['taluka_id' => $haveli->id, 'name' => 'Chakan']);
    }
}
