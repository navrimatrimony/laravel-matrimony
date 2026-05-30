<?php

namespace Tests\Unit\Intake;

use App\Services\Intake\IntakePreviewSelfAddressRows;
use Tests\TestCase;

class IntakePreviewSelfAddressRowsTest extends TestCase
{
    public function test_builds_current_row_from_snapshot_core(): void
    {
        $snapshot = [
            'core' => [
                'location_id' => 42,
                'address_line' => 'Main road',
            ],
        ];

        $rows = app(IntakePreviewSelfAddressRows::class)->rows(null, $snapshot, []);

        $this->assertNotEmpty($rows);
        $this->assertSame('current', $rows[0]['address_type_key']);
        $this->assertSame('Main road', $rows[0]['address_line']);
        $this->assertSame('42', $rows[0]['location_id']);
    }

    public function test_builds_rows_from_intake_addresses_snapshot(): void
    {
        $snapshot = [
            'addresses' => [
                ['address_line' => 'Varkute Malvadi', 'city_id' => 99],
            ],
        ];

        $rows = app(IntakePreviewSelfAddressRows::class)->rows(null, $snapshot, []);

        $this->assertCount(1, $rows);
        $this->assertSame('Varkute Malvadi', $rows[0]['address_line']);
        $this->assertSame('99', $rows[0]['location_id']);
    }

    public function test_appends_permanent_row_when_profile_has_only_current(): void
    {
        $base = [[
            'id' => 1,
            'address_type_key' => 'current',
            'address_line' => 'miraj road',
            'location_id' => '100',
            'display' => 'Alsand, Khanapur',
            'rid' => 'db-1',
        ]];
        $parsed = [
            'addresses' => [
                ['address_line' => 'miraj road', 'type' => 'current', 'city_id' => 100],
                ['address_line' => 'Village X, Taluka Y', 'type' => 'residential'],
            ],
        ];

        $ref = new \ReflectionClass(IntakePreviewSelfAddressRows::class);
        $method = $ref->getMethod('appendMissingBiodataSelfRows');
        $method->setAccessible(true);
        $rows = $method->invoke(app(IntakePreviewSelfAddressRows::class), $base, [], $parsed);

        $this->assertCount(2, $rows);
        $permanent = collect($rows)->firstWhere('address_type_key', 'permanent');
        $this->assertNotNull($permanent);
        $this->assertTrue($permanent['from_biodata'] ?? false);
        $this->assertStringContainsString('Village X', (string) ($permanent['address_line'] ?? ''));
    }

    public function test_marks_biodata_line_on_existing_type_when_place_differs(): void
    {
        $base = [[
            'address_type_key' => 'current',
            'address_line' => 'miraj road',
            'location_id' => '100',
            'rid' => 'db-1',
        ]];
        $parsed = [
            'addresses' => [
                ['address_line' => 'miraj road', 'type' => 'current'],
                ['address_line' => 'Different village, Taluka Z', 'type' => 'current'],
            ],
        ];

        $ref = new \ReflectionClass(IntakePreviewSelfAddressRows::class);
        $method = $ref->getMethod('appendMissingBiodataSelfRows');
        $method->setAccessible(true);
        $rows = $method->invoke(app(IntakePreviewSelfAddressRows::class), $base, [], $parsed);

        $this->assertSame('Different village, Taluka Z', $rows[0]['biodata_intake_line'] ?? '');
    }
}
