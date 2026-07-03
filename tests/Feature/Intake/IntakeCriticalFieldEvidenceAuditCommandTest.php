<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('command detects phone like evidence without printing phone', function () {
    $intake = createCriticalEvidenceAuditIntake([
        'raw_ocr_text' => "Name: Phone Missing Candidate\nDOB: 12/04/1996\nMobile: 9876543210",
        'field_confidence_json' => [
            'primary_contact_number' => ['score' => 0.1, 'present' => false, 'reason' => 'missing_parsed_value'],
        ],
        'routing_recommendation_json' => criticalEvidenceAuditRecommendation([
            'signals' => [
                'low_confidence_critical_fields' => ['primary_contact_number'],
                'low_confidence_fields' => ['primary_contact_number'],
                'quality_score' => 0.95,
            ],
        ]),
    ]);

    $payload = criticalEvidenceAuditJson();

    expect($payload['summary']['total_scanned'])->toBe(1)
        ->and($payload['rows'][0]['intake_id'])->toBe($intake->id)
        ->and($payload['rows'][0]['phone_like_present'])->toBeTrue()
        ->and($payload['rows'][0]['phone_like_count'])->toBe(1)
        ->and($payload['rows'][0]['parser_missed_likely_fields'])->toBe(['primary_contact_number'])
        ->and(json_encode($payload))->not->toContain('9876543210')
        ->and(json_encode($payload))->not->toContain('Phone Missing Candidate');
});

test('command detects date like evidence without printing raw text', function () {
    $intake = createCriticalEvidenceAuditIntake([
        'raw_ocr_text' => "Candidate profile\nDOB: 12 एप्रिल 1996\nMobile hidden",
        'field_confidence_json' => [
            'date_of_birth' => ['score' => 0.1, 'present' => false, 'reason' => 'missing_parsed_value'],
        ],
        'routing_recommendation_json' => criticalEvidenceAuditRecommendation([
            'signals' => [
                'low_confidence_critical_fields' => ['date_of_birth'],
                'low_confidence_fields' => ['date_of_birth'],
                'quality_score' => 0.9,
            ],
        ]),
    ]);

    $payload = criticalEvidenceAuditJson();

    expect($payload['rows'][0]['intake_id'])->toBe($intake->id)
        ->and($payload['rows'][0]['date_like_present'])->toBeTrue()
        ->and($payload['rows'][0]['date_like_pattern_types'])->toBe(['marathi_month_name'])
        ->and($payload['rows'][0]['parser_missed_likely_fields'])->toBe(['date_of_birth'])
        ->and(json_encode($payload))->not->toContain('12 एप्रिल 1996')
        ->and(json_encode($payload))->not->toContain('Candidate profile');
});

test('command detects name like evidence without printing name', function () {
    $intake = createCriticalEvidenceAuditIntake([
        'raw_ocr_text' => "Name: Secret Candidate\nEducation: MCA\nDOB missing",
        'field_confidence_json' => [
            'full_name' => ['score' => 0.1, 'present' => false, 'reason' => 'missing_parsed_value'],
        ],
        'routing_recommendation_json' => criticalEvidenceAuditRecommendation([
            'signals' => [
                'low_confidence_critical_fields' => ['full_name'],
                'low_confidence_fields' => ['full_name'],
                'quality_score' => 0.93,
            ],
        ]),
    ]);

    $payload = criticalEvidenceAuditJson();

    expect($payload['rows'][0]['intake_id'])->toBe($intake->id)
        ->and($payload['rows'][0]['name_like_present'])->toBeTrue()
        ->and($payload['rows'][0]['name_like_line_count'])->toBeGreaterThanOrEqual(1)
        ->and($payload['rows'][0]['name_like_word_count'])->toBeGreaterThanOrEqual(2)
        ->and($payload['rows'][0]['parser_missed_likely_fields'])->toBe(['full_name'])
        ->and(json_encode($payload))->not->toContain('Secret Candidate');
});

test('field filter works', function () {
    $phone = createCriticalEvidenceAuditIntake([
        'raw_ocr_text' => 'Mobile: 9876543210',
        'field_confidence_json' => [
            'primary_contact_number' => ['score' => 0.1, 'present' => false],
        ],
        'routing_recommendation_json' => criticalEvidenceAuditRecommendation([
            'signals' => ['low_confidence_critical_fields' => ['primary_contact_number']],
        ]),
    ]);
    createCriticalEvidenceAuditIntake([
        'raw_ocr_text' => 'DOB: 12/04/1996',
        'field_confidence_json' => [
            'date_of_birth' => ['score' => 0.1, 'present' => false],
        ],
        'routing_recommendation_json' => criticalEvidenceAuditRecommendation([
            'signals' => ['low_confidence_critical_fields' => ['date_of_birth']],
        ]),
    ]);

    $payload = criticalEvidenceAuditJson(['--field' => 'primary_contact_number']);

    expect($payload['summary']['total_scanned'])->toBe(1)
        ->and($payload['filters']['field'])->toBe('primary_contact_number')
        ->and($payload['rows'][0]['intake_id'])->toBe($phone->id);
});

test('action filter works', function () {
    $sarvam = createCriticalEvidenceAuditIntake([
        'routing_recommendation_json' => criticalEvidenceAuditRecommendation([
            'recommended_action' => 'call_sarvam',
            'signals' => ['low_confidence_critical_fields' => ['full_name']],
        ]),
    ]);
    createCriticalEvidenceAuditIntake([
        'routing_recommendation_json' => criticalEvidenceAuditRecommendation([
            'recommended_action' => 'manual_review',
            'signals' => ['low_confidence_critical_fields' => ['full_name']],
        ]),
    ]);

    $payload = criticalEvidenceAuditJson(['--action' => 'call_sarvam']);

    expect($payload['summary']['total_scanned'])->toBe(1)
        ->and($payload['filters']['action'])->toBe('call_sarvam')
        ->and($payload['rows'][0]['intake_id'])->toBe($sarvam->id);
});

test('json output is valid', function () {
    createCriticalEvidenceAuditIntake();

    $exitCode = Artisan::call('intake:critical-field-evidence-audit', ['--json' => true]);
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($payload['success'])->toBeTrue()
        ->and($payload['rows'])->toHaveCount(1);
});

test('summary counts are correct', function () {
    createCriticalEvidenceAuditIntake([
        'raw_ocr_text' => 'Name: Summary Candidate',
        'field_confidence_json' => [
            'full_name' => ['score' => 0.1, 'present' => false],
        ],
        'routing_recommendation_json' => criticalEvidenceAuditRecommendation([
            'signals' => ['low_confidence_critical_fields' => ['full_name']],
        ]),
    ]);
    createCriticalEvidenceAuditIntake([
        'raw_ocr_text' => 'DOB: 12/04/1996',
        'field_confidence_json' => [
            'date_of_birth' => ['score' => 0.1, 'present' => false],
        ],
        'routing_recommendation_json' => criticalEvidenceAuditRecommendation([
            'signals' => ['low_confidence_critical_fields' => ['date_of_birth']],
        ]),
    ]);
    createCriticalEvidenceAuditIntake([
        'raw_ocr_text' => 'No critical evidence in this stored text',
        'field_confidence_json' => [
            'primary_contact_number' => ['score' => 0.1, 'present' => false],
        ],
        'routing_recommendation_json' => criticalEvidenceAuditRecommendation([
            'signals' => ['low_confidence_critical_fields' => ['primary_contact_number']],
        ]),
    ]);

    $payload = criticalEvidenceAuditJson();

    expect($payload['summary']['total_scanned'])->toBe(3)
        ->and($payload['summary']['call_sarvam_count'])->toBe(3)
        ->and($payload['summary']['critical_missing_field_counts'])->toBe([
            'full_name' => 1,
            'date_of_birth' => 1,
            'primary_contact_number' => 1,
        ])
        ->and($payload['summary']['raw_evidence_likely_present_counts'])->toBe([
            'full_name' => 1,
            'date_of_birth' => 1,
            'primary_contact_number' => 0,
        ])
        ->and($payload['summary']['parser_missed_likely_count'])->toBe(2)
        ->and($payload['summary']['raw_evidence_absent_count'])->toBe(1)
        ->and($payload['summary']['needs_provider_count'])->toBe(1)
        ->and($payload['summary']['parser_mapping_candidate_count'])->toBe(2);
});

test('command does not mutate intake routing quality ocr attempts parse status or profile', function () {
    $member = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before Critical Evidence Audit',
    ]);
    $parsed = criticalEvidenceAuditParsed('Read Only Candidate', '9876543210');
    $fieldConfidence = [
        'primary_contact_number' => ['score' => 0.1, 'present' => false, 'reason' => 'missing_parsed_value'],
    ];
    $quality = ['score' => 0.95];
    $routing = criticalEvidenceAuditRecommendation([
        'signals' => ['low_confidence_critical_fields' => ['primary_contact_number']],
    ]);
    $intake = createCriticalEvidenceAuditIntake([
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

    $payload = criticalEvidenceAuditJson();

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
        ->and($profile->full_name)->toBe('Profile Before Critical Evidence Audit');
});

test('non matching action rows are excluded when action filter is call sarvam', function () {
    createCriticalEvidenceAuditIntake([
        'routing_recommendation_json' => criticalEvidenceAuditRecommendation([
            'recommended_action' => 'manual_review',
            'signals' => ['low_confidence_critical_fields' => ['date_of_birth']],
        ]),
    ]);

    $payload = criticalEvidenceAuditJson(['--action' => 'call_sarvam']);

    expect($payload['summary']['total_scanned'])->toBe(0)
        ->and($payload['rows'])->toBe([]);
});

function criticalEvidenceAuditJson(array $parameters = []): array
{
    $exitCode = Artisan::call('intake:critical-field-evidence-audit', array_merge($parameters, ['--json' => true]));
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);

    return $payload;
}

function createCriticalEvidenceAuditIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Name: Parsed Candidate',
        'parsed_json' => criticalEvidenceAuditParsed('Parsed Candidate', '9876543210'),
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
        'routing_recommendation_json' => criticalEvidenceAuditRecommendation(),
        'routing_telemetry_json' => [
            'mode' => 'dry_run',
            'last_quality_score' => 0.95,
        ],
    ], $overrides));
}

function criticalEvidenceAuditRecommendation(array $overrides = []): array
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
            'low_confidence_important_fields' => [],
            'low_confidence_optional_fields' => [],
            'field_confidence_routing_severity' => 'critical',
            'paid_vision_reasonable_for_field_confidence' => true,
            'has_raw_ocr_text' => true,
            'has_parsed_json' => true,
        ],
    ], $overrides);
}

function criticalEvidenceAuditParsed(string $name, string $phone): array
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
