<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Taluka;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds large-scale location data for stress testing.
 * Adds 3 states (Gujarat, Karnataka, Madhya Pradesh), each with 5 districts,
 * 10 talukas per district, 20 villages (cities) per taluka.
 * Deterministic naming only. No schema changes.
 */
class LocationStressTestSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $india = Country::firstOrCreate(['name' => 'India']);

            $stateNames = ['Gujarat', 'Karnataka', 'Madhya Pradesh'];
            foreach ($stateNames as $stateName) {
                $state = State::firstOrCreate([
                    'country_id' => $india->id,
                    'name' => $stateName,
                ]);

                for ($d = 1; $d <= 5; $d++) {
                    $districtName = $stateName . '-' . $d;
                    $district = District::firstOrCreate([
                        'state_id' => $state->id,
                        'name' => $districtName,
                    ]);

                    for ($t = 1; $t <= 10; $t++) {
                        $talukaName = 'Taluka-' . $d . '-' . $t;
                        $taluka = Taluka::firstOrCreate([
                            'district_id' => $district->id,
                            'name' => $talukaName,
                        ]);

                        for ($v = 1; $v <= 20; $v++) {
                            $villageName = 'Village-' . $d . '-' . $t . '-' . $v;
                            $pincode = sprintf('%02d%02d%02d', $d, $t, $v);
                            City::firstOrCreate(
                                [
                                    'taluka_id' => $taluka->id,
                                    'name' => $villageName,
                                ],
                                [
                                    'pincode' => $pincode,
                                ]
                            );
                        }
                    }
                }
            }
        });
    }
}
