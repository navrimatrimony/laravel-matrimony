<?php

namespace Tests\Feature\Location;

use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CountryHierarchyColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_addresses_geo_table_uses_hierarchy_and_drops_legacy_type_and_iso_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('addresses', 'name_mr'));
        $this->assertTrue(Schema::hasColumn('addresses', 'name_en'));
        $this->assertTrue(Schema::hasColumn('addresses', 'hierarchy'));
        $this->assertFalse(Schema::hasColumn('addresses', 'type'));
        $this->assertFalse(Schema::hasColumn('addresses', 'iso_alpha2'));
    }

    public function test_minimal_location_seeder_sets_india_hierarchy_and_marathi(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $in = DB::table('addresses')->where('slug', 'india')->first();
        $this->assertNotNull($in);
        $this->assertSame('country', $in->hierarchy);
        $this->assertNull($in->tag);
        $this->assertSame('भारत', $in->name_mr);
    }

    public function test_location_typeahead_component_uses_hierarchy_country_without_iso_column(): void
    {
        $this->seed(MinimalLocationSeeder::class);

        $html = Blade::render('<x-profile.location-typeahead context="residence" />');

        $this->assertStringContainsString('data-default-country-id=', $html);
        $this->assertStringNotContainsString('iso_alpha2', $html);
    }
}
