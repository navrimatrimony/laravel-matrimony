<?php

use App\Models\BiodataIntake;
use App\Services\Intake\IntakeCriticalFieldParserProposalService;

test('service returns safe critical parser proposal metadata without raw values', function () {
    $intake = new BiodataIntake([
        'raw_ocr_text' => "Name: Secret Candidate\nDOB: 13/04/1996\nMobile: 9876543210",
        'field_confidence_json' => [
            'full_name' => ['score' => 0.1, 'present' => false],
            'date_of_birth' => ['score' => 0.1, 'present' => false],
            'primary_contact_number' => ['score' => 0.1, 'present' => false],
        ],
    ]);

    $proposal = app(IntakeCriticalFieldParserProposalService::class)->analyze($intake, [
        'low_confidence_critical_fields' => [
            'full_name',
            'date_of_birth',
            'primary_contact_number',
        ],
    ]);
    $encoded = json_encode($proposal, JSON_UNESCAPED_UNICODE);

    expect($proposal['parser_proposal_outcome'])->toBe('parser_improvement_candidate')
        ->and($proposal['all_missing_critical_fields_have_safe_proposal'])->toBeTrue()
        ->and($proposal['missing_critical_fields_resolved_by_proposal'])->toBeTrue()
        ->and($proposal['has_ambiguous_critical_proposal'])->toBeFalse()
        ->and($proposal['raw_evidence_absent_fields'])->toBe([])
        ->and($proposal['primary_contact_number_masked'])->toBe('******3210')
        ->and($proposal['date_of_birth_normalized'])->toBe('1996-04-13')
        ->and($encoded)->not->toContain('9876543210')
        ->and($encoded)->not->toContain('Secret Candidate')
        ->and($encoded)->not->toContain('Name:');
});
