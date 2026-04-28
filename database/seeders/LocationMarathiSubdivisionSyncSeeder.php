<?php

namespace Database\Seeders;

use Database\Seeders\Support\LocationMarathiLabels;
use Illuminate\Database\Seeder;

/**
 * Step 2 (sub-district layer): village {@code name_mr} from {@code geo/villages.json}, then
 * {@code cities.name_mr} mirrored from {@code villages} (requires migration {@code add_name_mr_to_cities_table}).
 *
 * Skips the large villages JSON parse during PHPUnit to avoid memory/time spikes; taluka MR is applied in {@see GeoSeeder}.
 *
 * {@code php artisan db:seed --class=Database\\Seeders\\LocationMarathiSubdivisionSyncSeeder}
 */
class LocationMarathiSubdivisionSyncSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        LocationMarathiLabels::syncIndianVillageNameMrFromGeoJson();
        LocationMarathiLabels::syncIndianCityNameMrFromVillageMirror();
    }
}
