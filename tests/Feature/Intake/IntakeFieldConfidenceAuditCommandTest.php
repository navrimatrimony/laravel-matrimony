<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('command lists low confidence fields', function () {
    $intake = createFieldConfidenceAuditIntake([
        'quality_summary_json' => ['score' => 0.92],
        'field_confidence_json' => [
            'date_of_birth' => ['score' => 0.2, 'present' => false, 'reason' => 'missing_parsed_value', 'source_path' => null],
            'education' => ['score' => 0.6, 'present' => true, 'reason' => 'parsed_value_present', 'source_path' => 'core.education'],
            'full_name' => ['score' => 0.85, 'present' => true, 'reason' => 'parsed_value_present', 'source_path' => 'core.full_name'],
        ],
        'routing_recommendation_json' => fieldConfidenceAuditRecommendation([
            'signals' => [
                'quality_score' => 0.92,
                'low_confidence_fields' => ['date_of_birth', 'education'],
            ],
        ]),
    ]);

    $payload = fieldConfidenceAuditJson();

    expect($payload['summary']['total_scanned'])->toBe(1)
        ->and($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['intake_id'])->toBe($intake->id)
        ->and($payload['rows'][0]['recommended_action'])->toBe('call_sarvam')
        ->and($payload['rows'][0]['low_confidence_fields'])->toBe(['date_of_birth', 'education'])
        ->and($payload['rows'][0]['field_confidence']['date_of_birth']['score'])->toBe(0.2)
        ->and($payload['rows'][0]['field_confidence']['date_of_birth']['present'])->toBeFalse()
        ->and($payload['rows'][0]['field_confidence']['date_of_birth']['reason'])->toBe('missing_parsed_value')
        ->and($payload['rows'][0]['field_confidence']['education']['score'])->toBe(0.6)
        ->and($payload['rows'][0]['missing_fields'])->toBe(['date_of_birth'])
        ->and($payload['rows'][0]['low_confidence_critical_fields'])->toBe(['date_of_birth'])
        ->and($payload['rows'][0]['low_confidence_important_fields'])->toBe(['education'])
        ->and($payload['rows'][0]['low_confidence_optional_fields'])->toBe([])
        ->and($payload['rows'][0]['field_confidence_routing_severity'])->toBe('critical')
        ->and($payload['rows'][0]['paid_vision_reasonable_for_field_confidence'])->toBeTrue()
        ->and($payload['rows'][0]['notes'])->toContain('high_quality_low_field_confidence');
});

test('field filter works', function () {
    $primaryContactLow = createFieldConfidenceAuditIntake([
        'field_confidence_json' => [
            'primary_contact_number' => ['score' => 0.45, 'present' => true, 'reason' => 'parsed_value_present', 'source_path' => 'contacts.0.phone_number'],
        ],
        'routing_recommendation_json' => fieldConfidenceAuditRecommendation([
            'signals' => ['low_confidence_fields' => ['primary_contact_number']],
        ]),
    ]);
    createFieldConfidenceAuditIntake([
        'field_confidence_json' => [
            'date_of_birth' => ['score' => 0.2, 'present' => false, 'reason' => 'missing_parsed_value', 'source_path' => null],
        ],
        'routing_recommendation_json' => fieldConfidenceAuditRecommendation([
            'signals' => ['low_confidence_fields' => ['date_of_birth']],
        ]),
    ]);

    $payload = fieldConfidenceAuditJson(['--field' => 'primary_contact_number']);

    expect($payload['summary']['total_scanned'])->toBe(1)
        ->and($payload['filters']['field'])->toBe('primary_contact_number')
        ->and($payload['rows'][0]['intake_id'])->toBe($primaryContactLow->id)
        ->and($payload['rows'][0]['low_confidence_fields'])->toBe(['primary_contact_number'])
        ->and($payload['rows'][0]['low_confidence_critical_fields'])->toBe(['primary_contact_number'])
        ->and($payload['rows'][0]['field_confidence_routing_severity'])->toBe('critical');
});

test('action filter works', function () {
    $sarvam = createFieldConfidenceAuditIntake([
        'routing_recommendation_json' => fieldConfidenceAuditRecommendation([
            'recommended_action' => 'call_sarvam',
            'reason_codes' => ['field_confidence_low'],
        ]),
    ]);
    createFieldConfidenceAuditIntake([
        'routing_recommendation_json' => fieldConfidenceAuditRecommendation([
            'recommended_action' => 'manual_review',
            'reason_codes' => ['field_confidence_low'],
        ]),
    ]);

    $payload = fieldConfidenceAuditJson(['--action' => 'call_sarvam']);

    expect($payload['summary']['total_scanned'])->toBe(1)
        ->and($payload['filters']['action'])->toBe('call_sarvam')
        ->and($payload['rows'][0]['intake_id'])->toBe($sarvam->id);
});

test('json output is valid', function () {
    createFieldConfidenceAuditIntake();

    $exitCode = Artisan::call('intake:field-confidence-audit', ['--json' => true]);
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($payload['success'])->toBeTrue()
        ->and($payload['rows'])->toHaveCount(1);
});

test('summary counts are correct', function () {
    createFieldConfidenceAuditIntake([
        'field_confidence_json' => [
            'full_name' => ['score' => 0.1, 'present' => false, 'reason' => 'missing_parsed_value', 'source_path' => null],
        ],
        'routing_recommendation_json' => fieldConfidenceAuditRecommendation([
            'recommended_action' => 'call_sarvam',
            'reason_codes' => ['field_confidence_low', 'parser_no_fields'],
            'signals' => ['low_confidence_fields' => ['full_name'], 'quality_score' => 0.4],
        ]),
    ]);
    createFieldConfidenceAuditIntake([
        'field_confidence_json' => [
            'date_of_birth' => ['score' => 0.3, 'present' => true, 'reason' => 'parsed_value_present', 'source_path' => 'core.date_of_birth'],
        ],
        'routing_recommendation_json' => fieldConfidenceAuditRecommendation([
            'recommended_action' => 'manual_review',
            'reason_codes' => ['field_confidence_low'],
            'signals' => ['low_confidence_fields' => ['date_of_birth'], 'quality_score' => 0.7],
        ]),
    ]);
    createFieldConfidenceAuditIntake([
        'field_confidence_json' => [
            'education' => ['score' => 0.6, 'present' => true, 'reason' => 'parsed_value_present', 'source_path' => 'core.education'],
        ],
        'routing_recommendation_json' => fieldConfidenceAuditRecommendation([
            'recommended_action' => 'call_sarvam',
            'reason_codes' => ['field_confidence_low'],
            'signals' => ['low_confidence_fields' => ['education'], 'quality_score' => 0.9],
        ]),
    ]);
    createFieldConfidenceAuditIntake([
        'field_confidence_json' => [
            'address' => ['score' => 0.8, 'present' => false, 'status' => 'missing', 'reason' => 'missing_parsed_value', 'source_path' => null],
        ],
        'routing_recommendation_json' => fieldConfidenceAuditRecommendation([
            'recommended_action' => 'unknown',
            'reason_codes' => ['no_signal'],
            'signals' => ['low_confidence_fields' => ['address'], 'quality_score' => 0.95],
        ]),
    ]);
    createFieldConfidenceAuditIntake([
        'field_confidence_json' => [
            'caste' => ['score' => 0.95, 'present' => false, 'status' => 'missing', 'reason' => 'missing_parsed_value', 'source_path' => null],
        ],
        'routing_recommendation_json' => fieldConfidenceAuditRecommendation([
            'recommended_action' => 'unknown',
            'reason_codes' => ['no_signal'],
            'signals' => ['low_confidence_fields' => ['caste'], 'quality_score' => 0.95],
        ]),
    ]);
    createFieldConfidenceAuditIntake([
        'field_confidence_json' => [
            'custom_optional_field' => ['score' => 0.1, 'present' => false, 'status' => 'missing', 'reason' => 'missing_parsed_value', 'source_path' => null],
        ],
        'routing_recommendation_json' => fieldConfidenceAuditRecommendation([
            'recommended_action' => 'unknown',
            'reason_codes' => ['no_signal'],
            'signals' => ['low_confidence_fields' => ['custom_optional_field'], 'quality_score' => 0.95],
        ]),
    ]);

    $payload = fieldConfidenceAuditJson();

    expect($payload['summary']['total_scanned'])->toBe(6)
        ->and($payload['summary']['field_counts'])->toBe([
            'address' => 1,
            'caste' => 1,
            'custom_optional_field' => 1,
            'date_of_birth' => 1,
            'education' => 1,
            'full_name' => 1,
        ])
        ->and($payload['summary']['confidence_bucket_counts']['0-0.24'])->toBe(2)
        ->and($payload['summary']['confidence_bucket_counts']['0.25-0.49'])->toBe(1)
        ->and($payload['summary']['confidence_bucket_counts']['0.5-0.74'])->toBe(1)
        ->and($payload['summary']['confidence_bucket_counts']['0.75-0.89'])->toBe(1)
        ->and($payload['summary']['confidence_bucket_counts']['0.9+'])->toBe(1)
        ->and($payload['summary']['recommended_action_counts'])->toBe([
            'call_sarvam' => 2,
            'manual_review' => 1,
            'unknown' => 3,
        ])
        ->and($payload['summary']['reason_code_counts']['field_confidence_low'])->toBe(3)
        ->and($payload['summary']['reason_code_counts']['parser_no_fields'])->toBe(1)
        ->and($payload['summary']['reason_code_counts']['no_signal'])->toBe(3)
        ->and($payload['summary']['field_confidence_severity_counts']['critical'])->toBe(2)
        ->and($payload['summary']['field_confidence_severity_counts']['important_only'])->toBe(3)
        ->and($payload['summary']['field_confidence_severity_counts']['optional_only'])->toBe(1)
        ->and($payload['summary']['paid_vision_reasonable_counts'])->toBe(['yes' => 2, 'no' => 4]);
});

test('raw ocr text phone provider payloads full address and hashes are not printed', function () {
    $intake = createFieldConfidenceAuditIntake([
        'raw_ocr_text' => 'Sensitive OCR text 9876543210 sk-proj-secret',
        'parsed_json' => fieldConfidenceAuditParsed('Sensitive Candidate', '9876543210', '123 Secret Road, Pune'),
        'content_hash' => 'hash-that-should-not-print',
        'field_confidence_json' => [
            'primary_contact_number' => [
                'score' => 0.45,
                'present' => true,
                'reason' => 'invalid phone 9876543210 123 Secret Road Pune',
                'source_path' => 'contacts.0.phone_number',
            ],
        ],
        'routing_recommendation_json' => fieldConfidenceAuditRecommendation([
            'reason_codes' => ['field_confidence_low'],
            'signals' => [
                'quality_score' => 0.55,
                'low_confidence_fields' => ['primary_contact_number'],
                'provider_payload' => 'sk-proj-provider-payload',
                'matched_hash_type' => 'content_hash',
                'normalized_text_hash_present' => true,
                'image_hash_present' => true,
            ],
        ]),
    ]);

    $exitCode = Artisan::call('intake:field-confidence-audit');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain((string) $intake->id)
        ->and($output)->toContain('primary_contact_number')
        ->and($output)->toContain('Field severity')
        ->and($output)->toContain('Paid vision reasonable')
        ->and($output)->not->toContain('Sensitive OCR text')
        ->and($output)->not->toContain('9876543210')
        ->and($output)->not->toContain('123 Secret Road')
        ->and($output)->not->toContain('sk-proj-secret')
        ->and($output)->not->toContain('sk-proj-provider-payload')
        ->and($output)->not->toContain('hash-that-should-not-print')
        ->and($output)->not->toContain('content_hash');
});

test('command does not mutate stored intake signals ocr attempts parse status or profile', function () {
    $member = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before Field Confidence Audit',
    ]);
    $parsed = fieldConfidenceAuditParsed('Read Only Candidate', '9876543210');
    $fieldConfidence = [
        'date_of_birth' => ['score' => 0.2, 'present' => false, 'reason' => 'missing_parsed_value', 'source_path' => null],
    ];
    $quality = ['score' => 0.55, 'layout_score' => 0.7];
    $failureCodes = [BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED];
    $routing = fieldConfidenceAuditRecommendation([
        'reason_codes' => ['field_confidence_low'],
        'signals' => ['quality_score' => 0.55, 'low_confidence_fields' => ['date_of_birth']],
    ]);
    $intake = createFieldConfidenceAuditIntake([
        'uploaded_by' => $member->id,
        'matrimony_profile_id' => $profile->id,
        'raw_ocr_text' => 'Original OCR text 9876543210',
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
        'quality_summary_json' => $quality,
        'failure_codes_json' => $failureCodes,
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

    $payload = fieldConfidenceAuditJson();

    $intake->refresh();
    $profile->refresh();

    expect($payload['summary']['total_scanned'])->toBe(1)
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($intake->raw_ocr_text)->toBe('Original OCR text 9876543210')
        ->and($intake->field_confidence_json)->toEqual($fieldConfidence)
        ->and($intake->quality_summary_json)->toEqual($quality)
        ->and($intake->failure_codes_json)->toEqual($failureCodes)
        ->and($intake->routing_recommendation_json)->toEqual($routing)
        ->and($intake->parse_status)->toBe('parsed')
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe($attemptCountBefore)
        ->and($profile->full_name)->toBe('Profile Before Field Confidence Audit');
});

function fieldConfidenceAuditJson(array $parameters = []): array
{
    $exitCode = Artisan::call('intake:field-confidence-audit', array_merge($parameters, ['--json' => true]));
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);

    return $payload;
}

function createFieldConfidenceAuditIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => fieldConfidenceAuditParsed('Parsed Candidate', '9876543210'),
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
        'quality_summary_json' => ['score' => 0.55],
        'failure_codes_json' => [],
        'field_confidence_json' => [
            'date_of_birth' => ['score' => 0.2, 'present' => false, 'reason' => 'missing_parsed_value', 'source_path' => null],
        ],
        'routing_recommendation_json' => fieldConfidenceAuditRecommendation(),
        'routing_telemetry_json' => [
            'mode' => 'dry_run',
            'last_quality_score' => 0.55,
        ],
    ], $overrides));
}

function fieldConfidenceAuditRecommendation(array $overrides = []): array
{
    return array_replace_recursive([
        'mode' => 'dry_run',
        'recommended_action' => 'call_sarvam',
        'reason_codes' => ['field_confidence_low'],
        'confidence' => 0.7,
        'would_skip_paid_vision' => false,
        'would_call_paid_vision' => true,
        'signals' => [
            'quality_score' => 0.55,
            'low_confidence_fields' => ['date_of_birth'],
            'failure_codes' => [],
            'has_raw_ocr_text' => true,
            'has_parsed_json' => true,
        ],
    ], $overrides);
}

function fieldConfidenceAuditParsed(string $name, string $phone, string $address = 'Pune'): array
{
    return [
        'core' => [
            'full_name' => $name,
            'date_of_birth' => '1996-04-12',
            'primary_contact_number' => $phone,
            'current_address' => $address,
        ],
        'contacts' => [
            [
                'phone_number' => $phone,
                'is_primary' => true,
            ],
        ],
        'addresses' => [
            [
                'address' => $address,
            ],
        ],
    ];
}
