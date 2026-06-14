<?php

namespace Tests\Unit\Location;

use App\Services\Location\PlaceIntakeSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlaceIntakeSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLocationFixtures();
        app()->setLocale('mr');
    }

    public function test_tasgaon_sangli_returns_town_taluka_with_pincode(): void
    {
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
        $rows = app(PlaceIntakeSearchService::class)->search('वरकुटे-मलवडी, ता. माण, जि. सातारा', 10);

        $this->assertNotEmpty($rows);
        $this->assertSame(6316, (int) ($rows[0]['city_id'] ?? 0));
    }

    public function test_confident_match_varkute_returns_single_rural_row(): void
    {
        $row = app(PlaceIntakeSearchService::class)->confidentMatch('वरकुटे-मलवडी, ता. माण, जि. सातारा');

        $this->assertIsArray($row);
        $this->assertSame(6316, (int) ($row['city_id'] ?? 0));
        $this->assertStringContainsString('415509', (string) ($row['display_label'] ?? ''));
    }

    public function test_tasgaon_sangli_simple_comma_form(): void
    {
        $rows = app(PlaceIntakeSearchService::class)->search('तासगाव, सांगली', 5);

        $this->assertNotEmpty($rows);
        $this->assertSame(374, (int) ($rows[0]['city_id'] ?? 0));
    }

    private function seedLocationFixtures(): void
    {
        $now = now();

        DB::table('addresses')->insert(array_map(static fn (array $row): array => array_merge([
            'tag' => null,
            'pincode' => null,
        ], $row), [
            [
                'id' => 1,
                'name' => 'India',
                'name_mr' => 'भारत',
                'name_en' => 'India',
                'slug' => 'india',
                'hierarchy' => 'country',
                'parent_id' => null,
                'level' => 0,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'name' => 'Maharashtra',
                'name_mr' => 'महाराष्ट्र',
                'name_en' => 'Maharashtra',
                'slug' => 'maharashtra',
                'hierarchy' => 'state',
                'parent_id' => 1,
                'level' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 300,
                'name' => 'Sangli',
                'name_mr' => 'सांगली',
                'name_en' => 'Sangli',
                'slug' => 'sangli',
                'hierarchy' => 'district',
                'parent_id' => 2,
                'level' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 374,
                'name' => 'Tasgaon',
                'name_mr' => 'तासगाव',
                'name_en' => 'Tasgaon',
                'slug' => 'tasgaon-town',
                'hierarchy' => 'taluka',
                'tag' => 'rural',
                'parent_id' => 300,
                'level' => 3,
                'pincode' => '416312',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 400,
                'name' => 'Satara',
                'name_mr' => 'सातारा',
                'name_en' => 'Satara',
                'slug' => 'satara',
                'hierarchy' => 'district',
                'parent_id' => 2,
                'level' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 500,
                'name' => 'Man',
                'name_mr' => 'माण',
                'name_en' => 'Man',
                'slug' => 'man-taluka',
                'hierarchy' => 'taluka',
                'tag' => 'city',
                'parent_id' => 400,
                'level' => 3,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 6316,
                'name' => 'Varkute Malavdi',
                'name_mr' => 'वरकुटे मलवडी',
                'name_en' => 'Varkute Malavdi',
                'slug' => 'varkute-malavdi',
                'hierarchy' => 'village',
                'tag' => 'rural',
                'parent_id' => 500,
                'level' => 4,
                'pincode' => '415509',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]));
    }
}
