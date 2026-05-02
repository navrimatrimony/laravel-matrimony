<?php

namespace Database\Seeders\Location;

use App\Models\Country;
use App\Models\Location;
use Database\Seeders\Support\LocationMarathiLabels;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * पहिला पाऊल: {@code locations} मध्ये root country (slug {@code india}).
 * जर legacy {@code countries} तालिकेत India (IN) आधीच भरलेला असेल तर नाव/मराठी तिथून घेतात;
 * नसेल तर पॅकेजमधून India / भारत.
 */
class LocationCountrySeeder extends Seeder
{
    public function run(): void
    {
        $name = 'India';
        $nameMr = LocationMarathiLabels::englishToMarathi()['India'] ?? 'भारत';

        if (Schema::hasTable('countries')) {
            $legacy = Country::query()->where('iso_alpha2', 'IN')->first();
            if ($legacy !== null) {
                $en = trim((string) $legacy->name);
                if ($en !== '') {
                    $name = $en;
                }
                if (Schema::hasColumn('countries', 'name_mr') && filled($legacy->name_mr)) {
                    $nameMr = trim((string) $legacy->name_mr);
                }
            }
        }

        $attributes = [
            'name' => $name,
            'type' => 'country',
            'parent_id' => null,
            'level' => 0,
            'state_code' => null,
            'district_code' => null,
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
