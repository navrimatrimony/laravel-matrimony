<?php

namespace Database\Seeders;

use Database\Seeders\Support\LocationMarathiLabels;
use Illuminate\Database\Seeder;

/**
 * Overwrites {@code states.name_mr} for India from packaged UTF-8 map (fixes NULL, mojibake, or stale values).
 * Does **not** insert new states — only updates rows that already exist. To create all states/UTs, run
 * {@see IndianStatesMasterSeeder} after {@see CountriesMasterSeeder}.
 *
 * Safe anytime: `php artisan db:seed --class=StateMarathiSyncSeeder`. {@see GeoSeeder} also runs this at the end of a geo import.
 */
class StateMarathiSyncSeeder extends Seeder
{
    public function run(): void
    {
        LocationMarathiLabels::syncIndianStateNameMr();
    }
}
