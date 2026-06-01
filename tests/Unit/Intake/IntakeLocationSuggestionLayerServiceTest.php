<?php

namespace Tests\Unit\Intake;

use App\Models\Location;
use App\Services\Intake\IntakeLocationSuggestionLayerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntakeLocationSuggestionLayerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLocationFixtures();
        app()->setLocale('mr');
    }

    public function test_extracts_intake_birth_place_when_core_has_profile_city_only(): void
    {
        $service = app(IntakeLocationSuggestionLayerService::class);
        $method = new \ReflectionMethod($service, 'unresolvedCandidatesFromSnapshot');
        $method->setAccessible(true);

        $snapshot = [
            'core' => [
                'birth_city_id' => 999,
                'birth_place' => 'कोल्हापूर',
            ],
        ];
        $parsed = [
            'core' => [
                'birth_place' => 'वरकुटे-मलवडी, ता. माण, जि. सातारा',
            ],
        ];

        $rows = $method->invoke($service, $snapshot, 7, $parsed);

        $birth = collect($rows)->firstWhere('field_key', 'birth_place');
        $this->assertNotNull($birth);
        $this->assertStringContainsString('वरकुटे', (string) ($birth['raw_input'] ?? ''));
        $this->assertSame('वरकुटे मलवडी माण', $birth['suggested_search'] ?? '');
        $this->assertCount(1, $birth['options'] ?? [], 'Expected exactly one confident hierarchy match');
        $this->assertTrue($birth['has_confident_match'] ?? false);
    }

    public function test_uses_core_birth_text_when_intake_matches_profile_place(): void
    {
        $service = app(IntakeLocationSuggestionLayerService::class);
        $method = new \ReflectionMethod($service, 'unresolvedCandidatesFromSnapshot');
        $method->setAccessible(true);

        $snapshot = [
            'core' => [
                'birth_place_text' => 'कोल्हापूर',
            ],
        ];
        $parsed = [
            'core' => [
                'birth_place' => 'कोल्हापूर',
            ],
        ];

        $rows = $method->invoke($service, $snapshot, 7, $parsed);

        $birth = collect($rows)->firstWhere('field_key', 'birth_place');
        $this->assertNull($birth, 'No overlay when biodata matches user birth_place_text');
    }

    public function test_shows_suggestion_when_user_city_id_differs_from_biodata(): void
    {
        $kolhapurId = (int) (Location::query()
            ->where('name_mr', 'like', '%कोल्हापूर%')
            ->orWhereRaw('LOWER(name) LIKE ?', ['%kolhapur%'])
            ->value('id') ?? 0);

        $this->assertGreaterThan(0, $kolhapurId, 'Kolhapur location fixture missing');

        $service = app(IntakeLocationSuggestionLayerService::class);
        $method = new \ReflectionMethod($service, 'unresolvedCandidatesFromSnapshot');
        $method->setAccessible(true);

        $snapshot = ['core' => ['birth_city_id' => $kolhapurId]];
        $parsed = ['core' => ['birth_place' => 'वरकुटे-मलवडी, ता. माण, जि. सातारा']];

        $rows = $method->invoke($service, $snapshot, 7, $parsed);
        $birth = collect($rows)->firstWhere('field_key', 'birth_place');

        $this->assertNotNull($birth);
        $this->assertTrue($birth['has_confident_match'] ?? false);
        $this->assertCount(1, $birth['options'] ?? []);
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
                'type' => 'country',
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
                'type' => 'state',
                'parent_id' => 1,
                'level' => 1,
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
                'type' => 'district',
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
                'type' => 'taluka',
                'tag' => 'taluka',
                'parent_id' => 400,
                'level' => 3,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 600,
                'name' => 'Kolhapur',
                'name_mr' => 'कोल्हापूर',
                'name_en' => 'Kolhapur',
                'slug' => 'kolhapur',
                'type' => 'district',
                'tag' => 'town',
                'parent_id' => 2,
                'level' => 2,
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
                'type' => 'village',
                'tag' => 'rural',
                'parent_id' => 500,
                'level' => 5,
                'pincode' => '415509',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]));
    }
}
