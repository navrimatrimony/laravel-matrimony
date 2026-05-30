<?php

namespace Tests\Unit\Location;

use App\Models\Country;
use App\Services\Location\PlaceIntakeSearchService;
use Tests\TestCase;

class PlaceIntakeSearchServiceTest extends TestCase
{
    public function test_tasgaon_sangli_returns_town_taluka_with_pincode(): void
    {
        if (Country::query()->count() === 0) {
            $this->markTestSkipped('No location data');
        }

        app()->setLocale('mr');
        $rows = app(PlaceIntakeSearchService::class)->search('तासगाव ता. - तासगाव, जि. - सांगली', 10);

        $this->assertNotEmpty($rows);
        $ids = collect($rows)->pluck('city_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains(374, $ids, 'Expected Tasgaon town taluka (id 374) in results');

        $top = $rows[0];
        $this->assertStringContainsString('416312', (string) ($top['display_label'] ?? ''));
        $this->assertStringContainsString('सांगली', (string) ($top['display_label'] ?? ''));
    }

    public function test_varkute_malavdi_man_satara_finds_village(): void
    {
        if (Country::query()->count() === 0) {
            $this->markTestSkipped('No location data');
        }

        app()->setLocale('mr');
        $rows = app(PlaceIntakeSearchService::class)->search('वरकुटे-मलवडी, ता. माण, जि. सातारा', 10);

        $this->assertNotEmpty($rows);
        $this->assertSame(6316, (int) ($rows[0]['city_id'] ?? 0));
    }

    public function test_confident_match_varkute_returns_single_rural_row(): void
    {
        if (Country::query()->count() === 0) {
            $this->markTestSkipped('No location data');
        }

        app()->setLocale('mr');
        $row = app(PlaceIntakeSearchService::class)->confidentMatch('वरकुटे-मलवडी, ता. माण, जि. सातारा');

        $this->assertIsArray($row);
        $this->assertSame(6316, (int) ($row['city_id'] ?? 0));
        $this->assertStringContainsString('415509', (string) ($row['display_label'] ?? ''));
    }

    public function test_tasgaon_sangli_simple_comma_form(): void
    {
        if (Country::query()->count() === 0) {
            $this->markTestSkipped('No location data');
        }

        app()->setLocale('mr');
        $rows = app(PlaceIntakeSearchService::class)->search('तासगाव, सांगली', 5);

        $this->assertNotEmpty($rows);
        $this->assertSame(374, (int) ($rows[0]['city_id'] ?? 0));
    }
}
