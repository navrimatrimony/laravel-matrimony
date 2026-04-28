<?php

namespace Tests\Feature\Location;

use App\Models\Country;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CountryIsoColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_countries_table_has_iso_and_marathi_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('countries', 'name_mr'));
        $this->assertTrue(Schema::hasColumn('countries', 'iso_alpha2'));
    }

    public function test_minimal_location_seeder_sets_india_iso_and_marathi(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $in = Country::query()->where('name', 'India')->first();
        $this->assertNotNull($in);
        $this->assertSame('IN', $in->iso_alpha2);
        $this->assertSame('भारत', $in->name_mr);
    }
}
