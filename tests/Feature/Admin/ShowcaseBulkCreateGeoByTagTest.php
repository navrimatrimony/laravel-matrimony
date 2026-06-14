<?php

namespace Tests\Feature\Admin;

use App\Services\Showcase\ShowcaseAddressEligibility;
use App\Services\Showcase\ShowcaseBulkCreateSettings;
use App\Services\ShowcaseProfileDefaultsService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class ShowcaseBulkCreateGeoByTagTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_policy_empty_hierarchies_uses_default_hierarchies_for_context(): void
    {
        $policy = ShowcaseBulkCreateSettings::normalize([
            'eligible_address_hierarchies' => [],
            'eligible_address_tags' => ['city'],
        ]);

        $this->assertSame([], $policy['eligible_address_hierarchies']);
        $this->assertSame(['district', 'village'], ShowcaseAddressEligibility::hierarchiesForContext($policy));
    }

    public function test_pick_showcase_hierarchy_from_address_tags_without_member_district_pool(): void
    {
        $this->seed(MinimalLocationSeeder::class);

        $policy = ShowcaseBulkCreateSettings::normalize([
            'eligible_address_tags' => ['city'],
        ]);

        $m = new ReflectionMethod(ShowcaseProfileDefaultsService::class, 'pickShowcaseHierarchyFromAddressTags');
        $m->setAccessible(true);
        /** @var array<string, int|null>|null $loc */
        $loc = $m->invoke(null, $policy);

        $this->assertIsArray($loc);
        $this->assertNotNull($loc['district_id']);
        $this->assertNotNull($loc['city_id']);
        $this->assertGreaterThan(0, (int) $loc['district_id']);
        $this->assertGreaterThan(0, (int) $loc['city_id']);
        $this->assertSame((int) $loc['city_id'], (int) ($loc['work_city_id'] ?? 0));
    }
}
