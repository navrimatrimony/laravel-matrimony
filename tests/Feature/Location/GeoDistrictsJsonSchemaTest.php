<?php

namespace Tests\Feature\Location;

use Tests\TestCase;

class GeoDistrictsJsonSchemaTest extends TestCase
{
    public function test_geo_districts_json_has_statecode_on_every_row(): void
    {
        $path = database_path('seeders/data/geo/districts.json');
        $this->assertFileExists($path);
        $rows = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows);
        foreach ($rows as $i => $row) {
            $this->assertArrayHasKey('statecode', $row, 'Row index '.$i);
            $this->assertNotSame('', trim((string) $row['statecode']));
        }
    }
}
