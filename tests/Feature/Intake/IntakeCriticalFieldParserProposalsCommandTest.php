<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('command proposes masked phone without printing full phone', function () {
    $intake = createCriticalParserProposalIntake([
        'raw_ocr_text' => "Name: Phone Proposal Candidate\nMobile: 9876543210",
        'field_confidence_json' => [
            'primary_contact_number' => ['score' => 0.1, 'present' => false],
        ],
        'routing_recommendation_json' => criticalParserProposalRecommendation([
            'signals' => ['low_confidence_critical_fields' => ['primary_contact_number']],
        ]),
    ]);

    $payload = criticalParserProposalJson(['--show-safe-values' => true]);

    expect($payload['rows'][0]['intake_id'])->toBe($intake->id)
        ->and($payload['rows'][0]['phone_proposed'])->toBe('yes')
        ->and($payload['rows'][0]['phone_candidate_count'])->toBe(1)
        ->and($payload['rows'][0]['masked_phone'])->toBe('******3210')
        ->and($payload['rows'][0]['phone_confidence'])->toBe('high')
        ->and($payload['rows'][0]['parser_proposal_outcome'])->toBe('parser_improvement_candidate')
        ->and($payload['rows'][0]['estimated_paid_vision_avoidable'])->toBeTrue()
        ->and($payload['rows'][0]['missing_critical_fields_resolved_by_proposal'])->toBeTrue()
        ->and($payload['rows'][0]['has_ambiguous_critical_proposal'])->toBeFalse()
        ->and($payload['rows'][0]['raw_evidence_absent_fields'])->toBe([])
        ->and(json_encode($payload))->not->toContain('9876543210')
        ->and(json_encode($payload))->not->toContain('Phone Proposal Candidate');
});

test('command proposes normalized dob for numeric date without printing raw text', function () {
    $intake = createCriticalParserProposalIntake([
        'raw_ocr_text' => "Candidate DOB: 13/04/1996",
        'field_confidence_json' => [
            'date_of_birth' => ['score' => 0.1, 'present' => false],
        ],
        'routing_recommendation_json' => criticalParserProposalRecommendation([
            'signals' => ['low_confidence_critical_fields' => ['date_of_birth']],
        ]),
    ]);

    $payload = criticalParserProposalJson(['--show-safe-values' => true]);

    expect($payload['rows'][0]['intake_id'])->toBe($intake->id)
        ->and($payload['rows'][0]['dob_proposed'])->toBe('yes')
        ->and($payload['rows'][0]['dob_pattern_type'])->toBe('numeric_date')
        ->and($payload['rows'][0]['dob_normalized'])->toBe('1996-04-13')
        ->and($payload['rows'][0]['dob_confidence'])->toBe('high')
        ->and(json_encode($payload))->not->toContain('13/04/1996');
});

test('command detects marathi month dob proposal', function () {
    $payload = criticalParserProposalJsonAfterCreating([
        'raw_ocr_text' => "DOB: 13 एप्रिल 1996",
        'field_confidence_json' => [
            'date_of_birth' => ['score' => 0.1, 'present' => false],
        ],
        'routing_recommendation_json' => criticalParserProposalRecommendation([
            'signals' => ['low_confidence_critical_fields' => ['date_of_birth']],
        ]),
    ], ['--show-safe-values' => true]);

    expect($payload['rows'][0]['dob_proposed'])->toBe('yes')
        ->and($payload['rows'][0]['dob_pattern_type'])->toBe('marathi_month_name')
        ->and($payload['rows'][0]['dob_normalized'])->toBe('1996-04-13')
        ->and(json_encode($payload))->not->toContain('13 एप्रिल 1996');
});

test('command detects name proposal without printing name', function () {
    $payload = criticalParserProposalJsonAfterCreating([
        'raw_ocr_text' => "Name: Secret Candidate\nEducation: MCA",
        'field_confidence_json' => [
            'full_name' => ['score' => 0.1, 'present' => false],
        ],
        'routing_recommendation_json' => criticalParserProposalRecommendation([
            'signals' => ['low_confidence_critical_fields' => ['full_name']],
        ]),
    ]);

    expect($payload['rows'][0]['full_name_proposed'])->toBe('yes')
        ->and($payload['rows'][0]['full_name_candidate_line_count'])->toBeGreaterThanOrEqual(1)
        ->and($payload['rows'][0]['full_name_word_count'])->toBeGreaterThanOrEqual(2)
        ->and($payload['rows'][0]['full_name_confidence'])->toBe('high')
        ->and(json_encode($payload))->not->toContain('Secret Candidate');
});

test('ambiguous dob is marked ambiguous', function () {
    $payload = criticalParserProposalJsonAfterCreating([
        'raw_ocr_text' => 'DOB: 04/05/1996',
        'field_confidence_json' => [
            'date_of_birth' => ['score' => 0.1, 'present' => false],
        ],
        'routing_recommendation_json' => criticalParserProposalRecommendation([
            'signals' => ['low_confidence_critical_fields' => ['date_of_birth']],
        ]),
    ], ['--show-safe-values' => true]);

    expect($payload['rows'][0]['dob_proposed'])->toBe('ambiguous')
        ->and($payload['rows'][0]['dob_normalized'])->toBeNull()
        ->and($payload['rows'][0]['dob_confidence'])->toBe('low')
        ->and($payload['rows'][0]['suggested_next_action'])->toBe('manual_review')
        ->and($payload['rows'][0]['parser_proposal_outcome'])->toBe('manual_review')
        ->and($payload['rows'][0]['estimated_paid_vision_avoidable'])->toBeFalse()
        ->and($payload['rows'][0]['missing_critical_fields_resolved_by_proposal'])->toBeFalse()
        ->and($payload['rows'][0]['has_ambiguous_critical_proposal'])->toBeTrue()
        ->and($payload['rows'][0]['raw_evidence_absent_fields'])->toBe([])
        ->and(json_encode($payload))->not->toContain('04/05/1996');
});

test('field filter works', function () {
    $phone = createCriticalParserProposalIntake([
        'raw_ocr_text' => 'Mobile: 9876543210',
        'field_confidence_json' => [
            'primary_contact_number' => ['score' => 0.1, 'present' => false],
        ],
        'routing_recommendation_json' => criticalParserProposalRecommendation([
            'signals' => ['low_confidence_critical_fields' => ['primary_contact_number']],
        ]),
    ]);
    createCriticalParserProposalIntake([
        'raw_ocr_text' => 'DOB: 13/04/1996',
        'field_confidence_json' => [
            'date_of_birth' => ['score' => 0.1, 'present' => false],
        ],
        'routing_recommendation_json' => criticalParserProposalRecommendation([
            'signals' => ['low_confidence_critical_fields' => ['date_of_birth']],
        ]),
    ]);

    $payload = criticalParserProposalJson(['--field' => 'primary_contact_number']);

    expect($payload['summary']['total_scanned'])->toBe(1)
        ->and($payload['filters']['field'])->toBe('primary_contact_number')
        ->and($payload['rows'][0]['intake_id'])->toBe($phone->id);
});

test('action filter works', function () {
    $sarvam = createCriticalParserProposalIntake([
        'routing_recommendation_json' => criticalParserProposalRecommendation([
            'recommended_action' => 'call_sarvam',
            'signals' => ['low_confidence_critical_fields' => ['full_name']],
        ]),
    ]);
    createCriticalParserProposalIntake([
        'routing_recommendation_json' => criticalParserProposalRecommendation([
            'recommended_action' => 'manual_review',
            'signals' => ['low_confidence_critical_fields' => ['full_name']],
        ]),
    ]);

    $payload = criticalParserProposalJson(['--action' => 'call_sarvam']);

    expect($payload['summary']['total_scanned'])->toBe(1)
        ->and($payload['filters']['action'])->toBe('call_sarvam')
        ->and($payload['rows'][0]['intake_id'])->toBe($sarvam->id);
});

test('json output is valid', function () {
    createCriticalParserProposalIntake();

    $exitCode = Artisan::call('intake:critical-field-parser-proposals', ['--json' => true]);
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($payload['success'])->toBeTrue()
        ->and($payload['rows'])->toHaveCount(1);
});

test('summary counts are correct', function () {
    createCriticalParserProposalIntake([
        'raw_ocr_text' => 'Name: Summary Candidate',
        'field_confidence_json' => [
            'full_name' => ['score' => 0.1, 'present' => false],
        ],
        'routing_recommendation_json' => criticalParserProposalRecommendation([
            'signals' => ['low_confidence_critical_fields' => ['full_name']],
        ]),
    ]);
    createCriticalParserProposalIntake([
        'raw_ocr_text' => 'DOB: 13/04/1996',
        'field_confidence_json' => [
            'date_of_birth' => ['score' => 0.1, 'present' => false],
        ],
        'routing_recommendation_json' => criticalParserProposalRecommendation([
            'signals' => ['low_confidence_critical_fields' => ['date_of_birth']],
        ]),
    ]);
    createCriticalParserProposalIntake([
        'raw_ocr_text' => 'DOB: 04/05/1996',
        'field_confidence_json' => [
            'date_of_birth' => ['score' => 0.1, 'present' => false],
        ],
        'routing_recommendation_json' => criticalParserProposalRecommendation([
            'signals' => ['low_confidence_critical_fields' => ['date_of_birth']],
        ]),
    ]);
    createCriticalParserProposalIntake([
        'raw_ocr_text' => 'No critical proposal in this stored text',
        'field_confidence_json' => [
            'primary_contact_number' => ['score' => 0.1, 'present' => false],
        ],
        'routing_recommendation_json' => criticalParserProposalRecommendation([
            'signals' => ['low_confidence_critical_fields' => ['primary_contact_number']],
        ]),
    ]);

    $payload = criticalParserProposalJson();

    expect($payload['summary']['total_scanned'])->toBe(4)
        ->and($payload['summary']['proposals_found_count'])->toBe(2)
        ->and($payload['summary']['no_proposal_count'])->toBe(1)
        ->and($payload['summary']['ambiguous_count'])->toBe(1)
        ->and($payload['summary']['proposal_field_counts'])->toBe([
            'full_name' => 1,
            'date_of_birth' => 2,
            'primary_contact_number' => 0,
        ])
        ->and($payload['summary']['confidence_counts'])->toBe([
            'high' => 2,
            'medium' => 0,
            'low' => 1,
            'none' => 1,
        ])
        ->and($payload['summary']['estimated_sarvam_avoidable_count'])->toBe(2)
        ->and($payload['summary']['estimated_provider_needed_count'])->toBe(1);
});

test('command does not mutate intake routing quality ocr attempts parse status or profile', function () {
    $member = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before Critical Parser Proposal',
    ]);
    $parsed = criticalParserProposalParsed('Read Only Candidate', '9876543210');
    $fieldConfidence = [
        'primary_contact_number' => ['score' => 0.1, 'present' => false, 'reason' => 'missing_parsed_value'],
    ];
    $quality = ['score' => 0.95];
    $routing = criticalParserProposalRecommendation([
        'signals' => ['low_confidence_critical_fields' => ['primary_contact_number']],
    ]);
    $intake = createCriticalParserProposalIntake([
        'uploaded_by' => $member->id,
        'matrimony_profile_id' => $profile->id,
        'raw_ocr_text' => 'Original OCR text 9876543210',
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
        'quality_summary_json' => $quality,
        'field_confidence_json' => $fieldConfidence,
        'routing_recommendation_json' => $routing,
    ]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'source' => 'mobile_app',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'Name: Candidate',
        'quality_score' => 0.55,
        'cost_units' => 0,
        'is_primary' => false,
    ]);
    $attemptCountBefore = BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count();

    $payload = criticalParserProposalJson();

    $intake->refresh();
    $profile->refresh();

    expect($payload['summary']['total_scanned'])->toBe(1)
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($intake->raw_ocr_text)->toBe('Original OCR text 9876543210')
        ->and($intake->field_confidence_json)->toEqual($fieldConfidence)
        ->and($intake->quality_summary_json)->toEqual($quality)
        ->and($intake->routing_recommendation_json)->toEqual($routing)
        ->and($intake->parse_status)->toBe('parsed')
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe($attemptCountBefore)
        ->and($profile->full_name)->toBe('Profile Before Critical Parser Proposal');
});

test('non matching action rows are excluded when action filter is call sarvam', function () {
    createCriticalParserProposalIntake([
        'routing_recommendation_json' => criticalParserProposalRecommendation([
            'recommended_action' => 'manual_review',
            'signals' => ['low_confidence_critical_fields' => ['date_of_birth']],
        ]),
    ]);

    $payload = criticalParserProposalJson(['--action' => 'call_sarvam']);

    expect($payload['summary']['total_scanned'])->toBe(0)
        ->and($payload['rows'])->toBe([]);
});

function criticalParserProposalJsonAfterCreating(array $overrides = [], array $parameters = []): array
{
    createCriticalParserProposalIntake($overrides);

    return criticalParserProposalJson($parameters);
}

function criticalParserProposalJson(array $parameters = []): array
{
    $exitCode = Artisan::call('intake:critical-field-parser-proposals', array_merge($parameters, ['--json' => true]));
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);

    return $payload;
}

function createCriticalParserProposalIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Name: Parsed Candidate',
        'parsed_json' => criticalParserProposalParsed('Parsed Candidate', '9876543210'),
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
        'quality_summary_json' => ['score' => 0.95],
        'field_confidence_json' => [
            'full_name' => ['score' => 0.1, 'present' => false, 'reason' => 'missing_parsed_value'],
        ],
        'routing_recommendation_json' => criticalParserProposalRecommendation(),
        'routing_telemetry_json' => [
            'mode' => 'dry_run',
            'last_quality_score' => 0.95,
        ],
    ], $overrides));
}

function criticalParserProposalRecommendation(array $overrides = []): array
{
    return array_replace_recursive([
        'mode' => 'dry_run',
        'recommended_action' => 'call_sarvam',
        'reason_codes' => ['field_confidence_low', 'critical_field_confidence_low'],
        'confidence' => 0.7,
        'would_skip_paid_vision' => false,
        'would_call_paid_vision' => true,
        'signals' => [
            'quality_score' => 0.95,
            'low_confidence_fields' => ['full_name'],
            'low_confidence_critical_fields' => ['full_name'],
            'field_confidence_routing_severity' => 'critical',
            'paid_vision_reasonable_for_field_confidence' => true,
            'has_raw_ocr_text' => true,
            'has_parsed_json' => true,
        ],
    ], $overrides);
}

function criticalParserProposalParsed(string $name, string $phone): array
{
    return [
        'core' => [
            'full_name' => $name,
            'date_of_birth' => '1996-04-12',
            'primary_contact_number' => $phone,
        ],
        'contacts' => [
            [
                'phone_number' => $phone,
                'is_primary' => true,
            ],
        ],
    ];
}
