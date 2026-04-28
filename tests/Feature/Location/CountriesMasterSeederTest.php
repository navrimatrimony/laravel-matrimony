<?php

namespace Tests\Feature\Location;

use App\Models\Country;
use Database\Seeders\CountriesMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CountriesMasterSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_all_iso_countries_from_packaged_json(): void
    {
        $this->seed(CountriesMasterSeeder::class);

        $this->assertGreaterThanOrEqual(240, Country::query()->count());
        $in = Country::query()->where('iso_alpha2', 'IN')->first();
        $this->assertNotNull($in);
        $this->assertSame('India', $in->name);
        $this->assertSame('भारत', $in->name_mr);
        $us = Country::query()->where('iso_alpha2', 'US')->first();
        $this->assertNotNull($us);
        $this->assertSame('United States', $us->name);
        $this->assertSame('अमेरिका', $us->name_mr);
    }
}
