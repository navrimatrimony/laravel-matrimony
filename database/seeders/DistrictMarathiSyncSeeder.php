<?php

namespace Database\Seeders;

use Database\Seeders\Support\LocationMarathiLabels;
use Illuminate\Database\Seeder;

/**
 * Overwrites {@code districts.name_mr} for India from packaged UTF-8 sources
 * ({@code database/seeders/data/geo/districts.json} + {@code location_label_mr.php}).
 * Fixes NULL, mojibake (e.g. {@code à¤¯à¤µà¤¤à¤®à¤¾à¤³}), and stale values when English {@code name} matches a map key.
 *
 * Safe anytime: {@code php artisan db:seed --class=Database\\Seeders\\DistrictMarathiSyncSeeder}.
 */
class DistrictMarathiSyncSeeder extends Seeder
{
    public function run(): void
    {
        LocationMarathiLabels::syncIndianDistrictNameMr();
    }
}
