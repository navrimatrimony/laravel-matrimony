<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Inserts/updates all Indian states & union territories (English {@code name} + Marathi {@code name_mr}).
 * Requires {@see CountriesMasterSeeder} (India with {@code iso_alpha2} = IN). Idempotent via {@code updateOrCreate}.
 */
class IndianStatesMasterSeeder extends Seeder
{
    public function run(): void
    {
        $india = Country::query()->where('iso_alpha2', 'IN')->first();
        if ($india === null) {
            $this->command?->error('India country missing. Run CountriesMasterSeeder first.');

            return;
        }

        $path = database_path('seeders/data/state_name_mr_india.php');
        if (! File::isReadable($path)) {
            $this->command?->error('Missing state map: '.$path);

            return;
        }

        /** @var array<string, string> $map */
        $map = require $path;
        if (! is_array($map)) {
            return;
        }

        foreach ($map as $englishName => $marathiName) {
            $en = trim((string) $englishName);
            $mr = trim((string) $marathiName);
            if ($en === '' || $mr === '') {
                continue;
            }
            State::updateOrCreate(
                [
                    'country_id' => $india->id,
                    'name' => $en,
                ],
                [
                    'name_mr' => $mr,
                ]
            );
        }

        $this->command?->info('Indian states/UTs seeded: '.count($map).' keys processed.');
    }
}
