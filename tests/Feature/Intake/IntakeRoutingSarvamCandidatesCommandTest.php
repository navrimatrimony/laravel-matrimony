<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Intake\IntakeSmartRoutingAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('command lists call sarvam candidates', function () {
    $candidate = createSarvamCandidateIntake([
        'quality_summary_json' => ['score' => 0.42],
        'field_confidence_json' => [
            'date_of_birth' => ['score' => 0.2, 'present' => false],
        ],
        'failure_codes_json' => [BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED],
        'routing_recommendation_json' => sarvamCandidateRecommendation([
            'reason_codes' => ['field_confidence_low', 'low_quality_cheap_ocr'],
            'signals' => [
                'low_confidence_fields' => ['date_of_birth'],
                'quality_score' => 0.42,
                'failure_codes' => [BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED],
                'critical_field_parser_proposal_outcome' => 'provider_candidate',
                'estimated_paid_vision_avoidable' => false,
                'missing_critical_fields_resolved_by_proposal' => false,
                'has_ambiguous_critical_proposal' => false,
                'critical_field_parser_raw_evidence_absent_fields' => ['date_of_birth'],
            ],
        ]),
    ]);
    sarvamCandidateAttempt($candidate, [
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'quality_score' => 0.42,
        'is_primary' => true,
    ]);
    createSarvamCandidateIntake([
        'routing_recommendation_json' => sarvamCandidateRecommendation([
            'recommended_action' => 'manual_review',
            'reason_codes' => ['provider_error'],
        ]),
    ]);

    $payload = sarvamCandidatesJson();

    expect($payload['summary']['total_call_sarvam_candidates'])->toBe(1)
        ->and($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['intake_id'])->toBe($candidate->id)
        ->and($payload['rows'][0]['recommended_action'])->toBe('call_sarvam')
        ->and($payload['rows'][0]['reason_codes'])->toBe(['field_confidence_low', 'low_quality_cheap_ocr'])
        ->and($payload['rows'][0]['quality_score'])->toBe(0.42)
        ->and($payload['rows'][0]['field_confidence_low_fields'])->toBe(['date_of_birth'])
        ->and($payload['rows'][0]['field_confidence_critical_fields'])->toBe(['date_of_birth'])
        ->and($payload['rows'][0]['field_confidence_important_fields'])->toBe([])
        ->and($payload['rows'][0]['field_confidence_optional_fields'])->toBe([])
        ->and($payload['rows'][0]['field_confidence_routing_severity'])->toBe('critical')
        ->and($payload['rows'][0]['paid_vision_reasonable_for_field_confidence'])->toBeTrue()
        ->and($payload['rows'][0]['failure_codes'])->toBe([BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED])
        ->and($payload['rows'][0]['ocr_attempt_count'])->toBe(1)
        ->and($payload['rows'][0]['cheap_ocr_attempt_count'])->toBe(1)
        ->and($payload['rows'][0]['sarvam_attempt_count'])->toBe(0)
        ->and($payload['rows'][0]['primary_ocr_attempt_exists'])->toBeTrue()
        ->and($payload['rows'][0]['parser_proposal_outcome'])->toBe('provider_candidate')
        ->and($payload['rows'][0]['estimated_paid_vision_avoidable'])->toBeFalse()
        ->and($payload['rows'][0]['missing_critical_fields_resolved_by_proposal'])->toBeFalse()
        ->and($payload['rows'][0]['has_ambiguous_critical_proposal'])->toBeFalse()
        ->and($payload['rows'][0]['raw_evidence_absent_fields'])->toBe(['date_of_birth'])
        ->and($payload['rows'][0]['policy']['blocked_reason'])->toBe('routing_disabled');
});

test('reason filter works', function () {
    $fieldLow = createSarvamCandidateIntake([
        'routing_recommendation_json' => sarvamCandidateRecommendation([
            'reason_codes' => ['field_confidence_low'],
        ]),
    ]);
    createSarvamCandidateIntake([
        'routing_recommendation_json' => sarvamCandidateRecommendation([
            'reason_codes' => ['parser_no_fields'],
        ]),
    ]);

    $payload = sarvamCandidatesJson(['--reason' => 'field_confidence_low']);

    expect($payload['summary']['total_call_sarvam_candidates'])->toBe(1)
        ->and($payload['filters']['reason'])->toBe('field_confidence_low')
        ->and($payload['rows'][0]['intake_id'])->toBe($fieldLow->id);
});

test('json output is valid', function () {
    createSarvamCandidateIntake();

    $exitCode = Artisan::call('intake:routing-sarvam-candidates', ['--json' => true]);
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($payload['success'])->toBeTrue()
        ->and($payload['rows'])->toHaveCount(1);
});

test('summary counts are correct', function () {
    createSarvamCandidateIntake([
        'raw_ocr_text' => 'Raw low quality text',
        'parsed_json' => sarvamCandidateParsed('Low Candidate', '9876543210'),
        'quality_summary_json' => ['score' => 0.2],
        'routing_recommendation_json' => sarvamCandidateRecommendation([
            'reason_codes' => ['low_quality_cheap_ocr'],
            'signals' => ['quality_score' => 0.2, 'has_raw_ocr_text' => true, 'has_parsed_json' => true],
        ]),
    ]);
    $sarvamExisting = createSarvamCandidateIntake([
        'raw_ocr_text' => '',
        'parsed_json' => null,
        'quality_summary_json' => ['score' => 0.6],
        'routing_recommendation_json' => sarvamCandidateRecommendation([
            'reason_codes' => ['parser_no_fields'],
            'signals' => ['quality_score' => 0.6, 'has_raw_ocr_text' => false, 'has_parsed_json' => false],
        ]),
    ]);
    sarvamCandidateAttempt($sarvamExisting, [
        'engine' => BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
        'status' => BiodataIntakeOcrAttempt::STATUS_FAILED,
    ]);
    createSarvamCandidateIntake([
        'raw_ocr_text' => '',
        'parsed_json' => sarvamCandidateParsed('Mid Candidate', '9876543211'),
        'quality_summary_json' => ['score' => 0.8],
        'routing_recommendation_json' => sarvamCandidateRecommendation([
            'reason_codes' => ['field_confidence_low'],
            'signals' => ['quality_score' => 0.8, 'has_raw_ocr_text' => false, 'has_parsed_json' => true],
        ]),
    ]);
    createSarvamCandidateIntake([
        'raw_ocr_text' => 'High quality but layout risk',
        'parsed_json' => sarvamCandidateParsed('High Candidate', '9876543212'),
        'quality_summary_json' => ['score' => 0.95],
        'routing_recommendation_json' => sarvamCandidateRecommendation([
            'reason_codes' => ['two_column_layout_suspected'],
            'signals' => ['quality_score' => 0.95, 'has_raw_ocr_text' => true, 'has_parsed_json' => true],
        ]),
    ]);

    $payload = sarvamCandidatesJson();

    expect($payload['summary']['total_call_sarvam_candidates'])->toBe(4)
        ->and($payload['summary']['reason_code_counts']['low_quality_cheap_ocr'])->toBe(1)
        ->and($payload['summary']['reason_code_counts']['parser_no_fields'])->toBe(1)
        ->and($payload['summary']['reason_code_counts']['field_confidence_low'])->toBe(1)
        ->and($payload['summary']['reason_code_counts']['two_column_layout_suspected'])->toBe(1)
        ->and($payload['summary']['quality_bucket_counts']['0-0.49'])->toBe(1)
        ->and($payload['summary']['quality_bucket_counts']['0.5-0.74'])->toBe(1)
        ->and($payload['summary']['quality_bucket_counts']['0.75-0.89'])->toBe(1)
        ->and($payload['summary']['quality_bucket_counts']['0.9+'])->toBe(1)
        ->and($payload['summary']['parsed_json_counts'])->toBe(['yes' => 3, 'no' => 1])
        ->and($payload['summary']['raw_ocr_text_counts'])->toBe(['yes' => 2, 'no' => 2])
        ->and($payload['summary']['existing_sarvam_attempt_counts'])->toBe(['yes' => 1, 'no' => 3]);
});

test('raw ocr text phone provider payloads addresses and hashes are not printed', function () {
    $intake = createSarvamCandidateIntake([
        'raw_ocr_text' => 'Sensitive OCR text 9876543210 sk-proj-secret',
        'parsed_json' => sarvamCandidateParsed('Sensitive Candidate', '9876543210', '123 Secret Road, Pune'),
        'content_hash' => 'hash-that-should-not-print',
        'routing_recommendation_json' => sarvamCandidateRecommendation([
            'reason_codes' => ['field_confidence_low'],
            'signals' => [
                'quality_score' => 0.55,
                'low_confidence_fields' => ['primary_contact_number'],
                'provider_payload' => 'sk-proj-provider-payload',
                'matched_hash_type' => 'content_hash',
                'duplicate_detected' => true,
                'duplicate_reference_intake_id' => 777,
                'duplicate_reference_reason' => 'reference_lacks_verifiable_ocr_evidence',
            ],
        ]),
    ]);
    sarvamCandidateAttempt($intake, [
        'raw_text' => 'Attempt raw 9876543210 sk-proj-attempt',
        'normalized_text_hash' => 'attempt-normalized-hash',
        'image_hash' => 'attempt-image-hash',
        'engine_meta_json' => ['secret' => 'sk-proj-engine-secret'],
    ]);

    $exitCode = Artisan::call('intake:routing-sarvam-candidates');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain((string) $intake->id)
        ->and($output)->toContain('Paid vision reasonable')
        ->and($output)->toContain('Parser proposal outcome')
        ->and($output)->toContain('Paid vision avoidable')
        ->and($output)->toContain('Resolved by proposal')
        ->and($output)->toContain('Ambiguous proposal')
        ->and($output)->toContain('Raw absent fields')
        ->and($output)->toContain('critical')
        ->and($output)->toContain('reference_lacks_verifiable_ocr_evidence')
        ->and($output)->not->toContain('Sensitive OCR text')
        ->and($output)->not->toContain('9876543210')
        ->and($output)->not->toContain('123 Secret Road')
        ->and($output)->not->toContain('sk-proj-secret')
        ->and($output)->not->toContain('sk-proj-provider-payload')
        ->and($output)->not->toContain('sk-proj-attempt')
        ->and($output)->not->toContain('sk-proj-engine-secret')
        ->and($output)->not->toContain('hash-that-should-not-print')
        ->and($output)->not->toContain('attempt-normalized-hash')
        ->and($output)->not->toContain('attempt-image-hash');
});

test('command does not mutate routing json parsed raw ocr attempts parse status or profile', function () {
    $member = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before Sarvam Candidate Report',
    ]);
    $parsed = sarvamCandidateParsed('Read Only Candidate', '9876543210');
    $routing = sarvamCandidateRecommendation([
        'reason_codes' => ['field_confidence_low'],
        'signals' => ['quality_score' => 0.5, 'low_confidence_fields' => ['date_of_birth']],
    ]);
    $intake = createSarvamCandidateIntake([
        'uploaded_by' => $member->id,
        'matrimony_profile_id' => $profile->id,
        'raw_ocr_text' => 'Original OCR text 9876543210',
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
        'routing_recommendation_json' => $routing,
    ]);
    sarvamCandidateAttempt($intake);
    $attemptCountBefore = BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count();

    $payload = sarvamCandidatesJson();

    $intake->refresh();
    $profile->refresh();

    expect($payload['summary']['total_call_sarvam_candidates'])->toBe(1)
        ->and($intake->routing_recommendation_json)->toEqual($routing)
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($intake->raw_ocr_text)->toBe('Original OCR text 9876543210')
        ->and($intake->parse_status)->toBe('parsed')
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe($attemptCountBefore)
        ->and($profile->full_name)->toBe('Profile Before Sarvam Candidate Report');
});

test('sarvam candidates report excludes avoidable parser proposal rows after dry run refresh', function () {
    $intake = createSarvamCandidateIntake([
        'raw_ocr_text' => 'Mobile: 9876543210',
        'parsed_json' => sarvamCandidateParsed('Parsed Candidate', '1111111111'),
        'field_confidence_json' => [
            'primary_contact_number' => ['score' => 0.1, 'present' => false, 'reason' => 'missing_parsed_value'],
        ],
        'routing_recommendation_json' => null,
    ]);

    $stored = app(IntakeSmartRoutingAdvisor::class)->storeForIntake($intake);
    $payload = sarvamCandidatesJson();

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('manual_review')
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('critical_field_parser_proposal_available')
        ->and($stored->routing_recommendation_json['signals']['estimated_paid_vision_avoidable'])->toBeTrue()
        ->and($payload['summary']['total_call_sarvam_candidates'])->toBe(0)
        ->and($payload['rows'])->toBe([]);
});

test('non call sarvam rows are excluded', function () {
    createSarvamCandidateIntake([
        'routing_recommendation_json' => sarvamCandidateRecommendation([
            'recommended_action' => 'manual_review',
            'reason_codes' => ['duplicate_detected_but_untrusted'],
        ]),
    ]);
    createSarvamCandidateIntake([
        'routing_recommendation_json' => sarvamCandidateRecommendation([
            'recommended_action' => 'unknown',
            'reason_codes' => ['no_signal'],
        ]),
    ]);

    $payload = sarvamCandidatesJson();

    expect($payload['summary']['total_call_sarvam_candidates'])->toBe(0)
        ->and($payload['rows'])->toBe([]);
});

function sarvamCandidatesJson(array $parameters = []): array
{
    $exitCode = Artisan::call('intake:routing-sarvam-candidates', array_merge($parameters, ['--json' => true]));
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);

    return $payload;
}

function createSarvamCandidateIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => sarvamCandidateParsed('Parsed Candidate', '9876543210'),
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
        'quality_summary_json' => ['score' => 0.55],
        'failure_codes_json' => [],
        'field_confidence_json' => [
            'date_of_birth' => ['score' => 0.2, 'present' => false],
        ],
        'routing_recommendation_json' => sarvamCandidateRecommendation(),
        'routing_telemetry_json' => sarvamCandidateTelemetry(),
    ], $overrides));
}

function sarvamCandidateRecommendation(array $overrides = []): array
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
            'duplicate_detected' => false,
            'duplicate_reuse_eligible' => false,
        ],
    ], $overrides);
}

function sarvamCandidateTelemetry(array $overrides = []): array
{
    return array_merge([
        'mode' => 'dry_run',
        'sarvam_attempt_count' => 0,
        'cheap_ocr_attempt_count' => 0,
        'failed_provider_count' => 0,
        'reuse_candidate_found' => false,
        'last_provider_failure_code' => null,
        'last_quality_score' => 0.55,
        'last_layout_score' => null,
        'duration_ms' => null,
        'cost_units' => null,
    ], $overrides);
}

function sarvamCandidateAttempt(BiodataIntake $intake, array $overrides = []): BiodataIntakeOcrAttempt
{
    return BiodataIntakeOcrAttempt::create(array_merge([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'source' => 'mobile_app',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'Name: Candidate',
        'quality_score' => 0.55,
        'cost_units' => 0,
        'is_primary' => false,
    ], $overrides));
}

function sarvamCandidateParsed(string $name, string $phone, string $address = 'Pune'): array
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
