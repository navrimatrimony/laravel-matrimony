<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Taluka;
use Database\Seeders\Support\LocationMarathiLabels;
use Illuminate\Database\Seeder;

/**
 * Phase-4 Location Master Data.
 * Populates location hierarchy so profile create dropdowns are not empty.
 * Requires {@see CountriesMasterSeeder} first (India + United States + full world list by ISO).
 */
class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $india = Country::query()->where('iso_alpha2', 'IN')->firstOrFail();
        $usa = Country::query()->where('iso_alpha2', 'US')->firstOrFail();

        $maharashtra = State::firstOrCreate(
            ['parent_id' => $india->id, 'name' => 'Maharashtra']
        );
        LocationMarathiLabels::applyIfEmpty($maharashtra, $maharashtra->name);
        $karnataka = State::firstOrCreate(
            ['parent_id' => $india->id, 'name' => 'Karnataka']
        );
        LocationMarathiLabels::applyIfEmpty($karnataka, $karnataka->name);
        $gujarat = State::firstOrCreate(
            ['parent_id' => $india->id, 'name' => 'Gujarat']
        );
        LocationMarathiLabels::applyIfEmpty($gujarat, $gujarat->name);

        $pune = District::firstOrCreate(
            ['parent_id' => $maharashtra->id, 'name' => 'Pune']
        );
        LocationMarathiLabels::applyIfEmpty($pune, $pune->name);
        $kolhapur = District::firstOrCreate(
            ['parent_id' => $maharashtra->id, 'name' => 'Kolhapur']
        );
        LocationMarathiLabels::applyIfEmpty($kolhapur, $kolhapur->name);
        $satara = District::firstOrCreate(
            ['parent_id' => $maharashtra->id, 'name' => 'Satara']
        );
        LocationMarathiLabels::applyIfEmpty($satara, $satara->name);

        $haveli = Taluka::firstOrCreate(
            ['parent_id' => $pune->id, 'name' => 'Haveli']
        );
        LocationMarathiLabels::applyIfEmpty($haveli, $haveli->name);
        $mulshi = Taluka::firstOrCreate(
            ['parent_id' => $pune->id, 'name' => 'Mulshi']
        );
        LocationMarathiLabels::applyIfEmpty($mulshi, $mulshi->name);

        City::firstOrCreate(['parent_id' => $haveli->id, 'name' => 'Pune']);
        City::firstOrCreate(['parent_id' => $haveli->id, 'name' => 'Pimpri-Chinchwad']);
        City::firstOrCreate(['parent_id' => $haveli->id, 'name' => 'Chakan']);

        LocationMarathiLabels::syncIndianStateNameMr();
    }
}
