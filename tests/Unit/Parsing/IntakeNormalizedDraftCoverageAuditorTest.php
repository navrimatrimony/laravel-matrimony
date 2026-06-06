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
}
