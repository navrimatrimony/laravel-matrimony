<?php

namespace Database\Seeders\Location;

use App\Models\Location;
use Database\Seeders\Support\LocationMarathiLabels;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Root country row in {@code addresses} (slug {@code india}) — SSOT only; no parallel {@code countries} table.
 */
class LocationCountrySeeder extends Seeder
{
    public function run(): void
    {
        $name = 'India';
        $nameMr = LocationMarathiLabels::englishToMarathi()['India'] ?? 'भारत';

        $attributes = [
            'name' => $name,
            'name_en' => $name,
            'hierarchy' => 'country',
            'parent_id' => null,
            'level' => 0,
            'is_active' => true,
        ];

        if (Schema::hasColumn(Location::geoTable(), 'name_mr')) {
            $attributes['name_mr'] = $nameMr;
        }

        Location::query()->updateOrCreate(
            ['slug' => 'india'],
            $attributes
        );
    }
}
