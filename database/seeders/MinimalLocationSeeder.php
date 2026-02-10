<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Country;
use App\Models\State;
use App\Models\District;
use App\Models\Taluka;
use App\Models\City;

class MinimalLocationSeeder extends Seeder
{
    public function run(): void
    {
        $india = Country::create(['name' => 'India']);
        $maharashtra = State::create(['country_id' => $india->id, 'name' => 'Maharashtra']);
        $gujarat = State::create(['country_id' => $india->id, 'name' => 'Gujarat']);
        $pune = District::create(['state_id' => $maharashtra->id, 'name' => 'Pune']);
        $ahmedabad = District::create(['state_id' => $gujarat->id, 'name' => 'Ahmedabad']);
        $haveli = Taluka::create(['district_id' => $pune->id, 'name' => 'Haveli']);
        $daskroi = Taluka::create(['district_id' => $ahmedabad->id, 'name' => 'Daskroi']);
        City::create(['taluka_id' => $haveli->id, 'name' => 'Pune City']);
        City::create(['taluka_id' => $daskroi->id, 'name' => 'Ahmedabad City']);
    }
}