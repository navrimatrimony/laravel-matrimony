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
 * Minimal demo hierarchy for tests/early bootstrap. Idempotent composite keys:
 * Country `iso_alpha2`, State `country_id` + `name`, District `state_id` + `name`,
 * Taluka `district_id` + `name`, City `taluka_id` + `name`.
 */
class MinimalLocationSeeder extends Seeder
{
    public function run(): void
    {
        $mr = LocationMarathiLabels::englishToMarathi();
        $india = Country::updateOrCreate(
            ['iso_alpha2' => 'IN'],
            [
                'name' => 'India',
                'name_mr' => $mr['India'] ?? 'भारत',
            ]
        );
        $maharashtra = State::firstOrCreate(
            ['country_id' => $india->id, 'name' => 'Maharashtra'],
            ['name_mr' => $mr['Maharashtra'] ?? null]
        );
        LocationMarathiLabels::applyIfEmpty($maharashtra, $maharashtra->name);
        $gujarat = State::firstOrCreate(
            ['country_id' => $india->id, 'name' => 'Gujarat'],
            ['name_mr' => $mr['Gujarat'] ?? null]
        );
        LocationMarathiLabels::applyIfEmpty($gujarat, $gujarat->name);

        $pune = District::firstOrCreate(
            ['state_id' => $maharashtra->id, 'name' => 'Pune'],
            ['name_mr' => $mr['Pune'] ?? null]
        );
        LocationMarathiLabels::applyIfEmpty($pune, $pune->name);

        $ahmedabad = District::firstOrCreate(
            ['state_id' => $gujarat->id, 'name' => 'Ahmedabad'],
            ['name_mr' => $mr['Ahmedabad'] ?? null]
        );
        LocationMarathiLabels::applyIfEmpty($ahmedabad, $ahmedabad->name);

        $haveli = Taluka::firstOrCreate(
            ['district_id' => $pune->id, 'name' => 'Haveli'],
            ['name_mr' => $mr['Haveli'] ?? null]
        );
        LocationMarathiLabels::applyIfEmpty($haveli, $haveli->name);
        $daskroi = Taluka::firstOrCreate(
            ['district_id' => $ahmedabad->id, 'name' => 'Daskroi'],
            ['name_mr' => $mr['Daskroi'] ?? null]
        );
        LocationMarathiLabels::applyIfEmpty($daskroi, $daskroi->name);

        City::firstOrCreate(['taluka_id' => $haveli->id, 'name' => 'Pune City']);
        City::firstOrCreate(['taluka_id' => $daskroi->id, 'name' => 'Ahmedabad City']);

        LocationMarathiLabels::syncIndianStateNameMr();
    }
}
