<?php

namespace Tests\Unit\Intake;

use App\Models\Country;
use App\Models\Location;
use App\Services\Intake\IntakeLocationSuggestionLayerService;
use Tests\TestCase;

class IntakeLocationSuggestionLayerServiceTest extends TestCase
{
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
        if (Country::query()->count() === 0) {
            $this->markTestSkipped('No location data');
        }

        $kolhapurId = (int) (Location::query()
            ->where('name_mr', 'like', '%कोल्हापूर%')
            ->orWhereRaw('LOWER(name) LIKE ?', ['%kolhapur%'])
            ->value('id') ?? 0);

        if ($kolhapurId < 1) {
            $this->markTestSkipped('Kolhapur location missing');
        }

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
}
