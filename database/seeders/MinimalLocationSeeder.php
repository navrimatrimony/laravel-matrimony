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
 * Country `iso_alpha2`, State `parent_id` (country row) + `name`, District `parent_id` (state) + `name`,
 * Taluka `parent_id` (district) + `name`, City `parent_id` (taluka) + `name` — all rows live in `addresses`.
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
            ['parent_id' => $india->id, 'name' => 'Maharashtra'],
            ['name_mr' => $mr['Maharashtra'] ?? null]
        );
        LocationMarathiLabels::applyIfEmpty($maharashtra, $maharashtra->name);
        $gujarat = State::firstOrCreate(
            ['parent_id' => $india->id, 'name' => 'Gujarat'],
            ['name_mr' => $mr['Gujarat'] ?? null]
        );
        LocationMarathiLabels::applyIfEmpty($gujarat, $gujarat->name);

        $pune = District::firstOrCreate(
            ['parent_id' => $maharashtra->id, 'name' => 'Pune'],
            ['name_mr' => $mr['Pune'] ?? null]
        );
        LocationMarathiLabels::applyIfEmpty($pune, $pune->name);

        $ahmedabad = District::firstOrCreate(
            ['parent_id' => $gujarat->id, 'name' => 'Ahmedabad'],
            ['name_mr' => $mr['Ahmedabad'] ?? null]
        );
        LocationMarathiLabels::applyIfEmpty($ahmedabad, $ahmedabad->name);

        $haveli = Taluka::firstOrCreate(
            ['parent_id' => $pune->id, 'name' => 'Haveli'],
            ['name_mr' => $mr['Haveli'] ?? null]
        );
        LocationMarathiLabels::applyIfEmpty($haveli, $haveli->name);
        $daskroi = Taluka::firstOrCreate(
            ['parent_id' => $ahmedabad->id, 'name' => 'Daskroi'],
            ['name_mr' => $mr['Daskroi'] ?? null]
        );
        LocationMarathiLabels::applyIfEmpty($daskroi, $daskroi->name);

        City::firstOrCreate(['parent_id' => $haveli->id, 'name' => 'Pune City']);
        City::firstOrCreate(['parent_id' => $daskroi->id, 'name' => 'Ahmedabad City']);

        LocationMarathiLabels::syncIndianStateNameMr();
    }
}
