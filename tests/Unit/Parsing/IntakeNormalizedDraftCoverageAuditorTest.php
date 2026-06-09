<?php

namespace Tests\Unit\Parsing;

use App\Services\Parsing\IntakeNormalizedDraftCoverageAuditor;
use Tests\TestCase;

class IntakeNormalizedDraftCoverageAuditorTest extends TestCase
{
    public function test_missing_fact_is_reported_with_exact_source_line_and_suggested_section(): void
    {
        $audit = app(IntakeNormalizedDraftCoverageAuditor::class)->audit([
            'normalized' => [
                'core' => [],
                'contacts' => [],
            ],
            'extracted_facts' => [[
                'fact_type' => 'phone_number',
                'value' => '8655211728',
                'source_line_no' => 9,
                'source_text' => 'भ्रमणध्वनी – ८६५५२११७२८',
                'target_section' => 'basic-info',
                'target_field' => 'core.primary_contact_number',
            ]],
        ]);

        $this->assertSame([[
            'fact_type' => 'phone_number',
            'value' => '8655211728',
            'target_section' => 'basic-info',
            'target_field' => 'core.primary_contact_number',
            'source_line_no' => 9,
            'source_text' => 'भ्रमणध्वनी – ८६५५२११७२८',
        ]], $audit['missing_facts'] ?? []);

        $this->assertSame('coverage_missing_fact', $audit['review_flags'][0]['reason'] ?? null);
        $this->assertSame(9, $audit['review_flags'][0]['source_line_no'] ?? null);
        $this->assertSame('basic-info', $audit['review_flags'][0]['suggested_section'] ?? null);
    }

    public function test_parenthetical_nokari_phone_occupation_is_not_reported_as_missing_when_draft_has_occupation(): void
    {
        $draft = app(\App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
नाव :- विशाल पांडुरंग डाकवे
वडिलांचे नाव : पांडुरंग लक्ष्मण डाकवे (नोकरी-9322202146)
आईचे नाव : सुवर्णा पांडुरंग डाकवे (नोकरी-9527610122)
TXT);

        $audit = $draft['coverage_audit'] ?? [];
        $missingFields = array_map(
            static fn (array $fact): string => (string) ($fact['target_field'] ?? ''),
            is_array($audit['missing_facts'] ?? null) ? $audit['missing_facts'] : []
        );
        $flagFields = array_map(
            static fn (array $flag): string => (string) ($flag['field'] ?? ''),
            is_array($audit['review_flags'] ?? null) ? $audit['review_flags'] : []
        );

        $this->assertSame('नोकरी', $draft['normalized']['core']['father_occupation'] ?? null);
        $this->assertSame('नोकरी', $draft['normalized']['core']['mother_occupation'] ?? null);
        $this->assertNotContains('core.father_occupation', $missingFields);
        $this->assertNotContains('core.mother_occupation', $missingFields);
        $this->assertNotContains('core.father_occupation', $flagFields);
        $this->assertNotContains('core.mother_occupation', $flagFields);
    }

    public function test_comma_separated_chulte_names_are_not_reported_as_missing_when_draft_has_each_name(): void
    {
        $draft = app(\App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
चुलते : कै. शामराव लक्ष्मण डाकवे, कृष्णा लक्ष्मण डाकवे,
हरि लक्ष्मण डाकवे.
TXT);

        $audit = $draft['coverage_audit'] ?? [];
        $missingFields = array_map(
            static fn (array $fact): string => (string) ($fact['target_field'] ?? ''),
            is_array($audit['missing_facts'] ?? null) ? $audit['missing_facts'] : []
        );
        $flagFields = array_map(
            static fn (array $flag): string => (string) ($flag['field'] ?? ''),
            is_array($audit['review_flags'] ?? null) ? $audit['review_flags'] : []
        );

        $this->assertNotContains('relatives.paternal_uncle.name', $missingFields);
        $this->assertNotContains('relatives.paternal_uncle.name', $flagFields);
    }

    public function test_decomposed_caste_line_is_not_reported_as_missing_when_religion_caste_and_sub_caste_are_mapped(): void
    {
        $audit = app(IntakeNormalizedDraftCoverageAuditor::class)->audit([
            'normalized' => [
                'core' => [
                    'religion' => 'हिंदू',
                    'caste' => 'मराठा',
                    'sub_caste' => '96 कुळी',
                ],
            ],
            'extracted_facts' => [[
                'fact_type' => 'field_value',
                'value' => '96 कुळी हिंदू-मराठा',
                'source_line_no' => 14,
                'source_text' => 'जात :- 96 कुळी हिंदू-मराठा',
                'target_section' => 'basic-info',
                'target_field' => 'core.caste',
            ]],
        ]);

        $this->assertSame([], $audit['missing_facts'] ?? []);
        $this->assertSame([], $audit['review_flags'] ?? []);
    }
}
