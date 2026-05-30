<?php

namespace Tests\Unit\Intake;

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
                'birth_city_id' => 999,
                'birth_place' => 'कोल्हापूर',
            ],
        ];
        $parsed = [
            'core' => [
                'birth_place' => 'कोल्हापूर',
            ],
        ];

        $rows = $method->invoke($service, $snapshot, 7, $parsed);

        $birth = collect($rows)->firstWhere('field_key', 'birth_place');
        $this->assertNotNull($birth);
        $this->assertSame('कोल्हापूर', $birth['raw_input'] ?? '');
        $this->assertFalse($birth['has_confident_match'] ?? true);
        $this->assertSame([], $birth['options'] ?? null);
    }

    public function test_birth_place_without_full_hierarchy_shows_no_initial_options(): void
    {
        $service = app(IntakeLocationSuggestionLayerService::class);
        $method = new \ReflectionMethod($service, 'unresolvedCandidatesFromSnapshot');
        $method->setAccessible(true);

        $snapshot = ['core' => ['birth_city_id' => 11]];
        $parsed = ['core' => ['birth_place' => 'पुणे']];

        $rows = $method->invoke($service, $snapshot, 7, $parsed);
        $birth = collect($rows)->firstWhere('field_key', 'birth_place');

        if ($birth !== null) {
            $this->assertFalse($birth['has_confident_match'] ?? true);
            $this->assertSame([], $birth['options'] ?? null);
        } else {
            $this->assertTrue(true);
        }
    }
}
