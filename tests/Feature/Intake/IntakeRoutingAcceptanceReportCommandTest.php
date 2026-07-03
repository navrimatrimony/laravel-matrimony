<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('command prints pass when metrics are within thresholds', function () {
    createAcceptanceReportIntake([
        'routing_recommendation_json' => acceptanceReportRecommendation([
            'recommended_action' => 'call_sarvam',
            'reason_codes' => ['critical_field_raw_evidence_absent'],
            'would_call_paid_vision' => true,
            'signals' => [
                'critical_field_parser_raw_evidence_absent_fields' => ['date_of_birth'],
            ],
        ]),
    ]);
    createAcceptanceReportIntake([
        'routing_recommendation_json' => acceptanceReportRecommendation([
            'recommended_action' => 'manual_review',
            'reason_codes' => [
                'critical_field_parser_proposal_available',
                'paid_vision_not_required_due_to_parser_proposal',
            ],
            'signals' => [
                'estimated_paid_vision_avoidable' => true,
                'critical_field_parser_proposal_outcome' => 'parser_improvement_candidate',
            ],
        ]),
    ]);
    createAcceptanceReportIntake([
        'routing_recommendation_json' => acceptanceReportRecommendation([
            'recommended_action' => 'unknown',
            'reason_codes' => ['no_signal'],
        ]),
    ]);

    $exitCode = Artisan::call('intake:routing-acceptance-report');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Acceptance status')
        ->and($output)->toContain('pass')
        ->and($output)->toContain('Parser proposal avoidable')
        ->and($output)->toContain('Raw evidence absent')
        ->and($output)->toContain('call_sarvam');
});

test('json output is valid and includes parser proposal avoided count', function () {
    createAcceptanceReportIntake([
        'routing_recommendation_json' => acceptanceReportRecommendation([
            'recommended_action' => 'manual_review',
            'reason_codes' => ['critical_field_parser_proposal_available'],
            'signals' => ['estimated_paid_vision_avoidable' => true],
        ]),
    ]);

    $payload = acceptanceReportJson();

    expect($payload['success'])->toBeTrue()
        ->and($payload['summary']['total_scanned'])->toBe(1)
        ->and($payload['summary']['parser_proposal_avoidable_count'])->toBe(1)
        ->and($payload['risk_details']['parser_proposal_avoided_sample_ids'])->toHaveCount(1)
        ->and($payload['acceptance']['status'])->toBe('pass');
});

test('fail on risk exits non zero when would call paid vision exceeds threshold', function () {
    createAcceptanceReportIntake([
        'routing_recommendation_json' => acceptanceReportRecommendation([
            'recommended_action' => 'call_sarvam',
            'would_call_paid_vision' => true,
        ]),
    ]);
    createAcceptanceReportIntake([
        'routing_recommendation_json' => acceptanceReportRecommendation([
            'recommended_action' => 'call_sarvam',
            'would_call_paid_vision' => true,
        ]),
    ]);

    $exitCode = Artisan::call('intake:routing-acceptance-report', [
        '--json' => true,
        '--fail-on-risk' => true,
        '--max-paid-calls' => 1,
    ]);
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(1)
        ->and($payload['acceptance']['status'])->toBe('fail')
        ->and($payload['acceptance']['risks'][0]['code'])->toBe('would_call_paid_vision_exceeds_threshold')
        ->and($payload['acceptance']['risks'][0]['actual'])->toBe(2)
        ->and($payload['acceptance']['risks'][0]['max'])->toBe(1);
});

test('fail on risk exits non zero when would skip paid vision exceeds threshold', function () {
    createAcceptanceReportIntake([
        'routing_recommendation_json' => acceptanceReportRecommendation([
            'recommended_action' => 'cheap_ocr_only',
            'reason_codes' => ['high_quality_cheap_ocr'],
            'would_skip_paid_vision' => true,
        ]),
    ]);

    $exitCode = Artisan::call('intake:routing-acceptance-report', [
        '--json' => true,
        '--fail-on-risk' => true,
        '--max-skip-calls' => 0,
    ]);
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(1)
        ->and($payload['acceptance']['status'])->toBe('fail')
        ->and($payload['acceptance']['risks'][0]['code'])->toBe('would_skip_paid_vision_exceeds_threshold')
        ->and($payload['risk_details']['risky_rows'][0]['risk_codes'])->toContain('would_skip_paid_vision');
});

test('detects live policy and allowed live action risk from stored routing json', function () {
    createAcceptanceReportIntake([
        'routing_recommendation_json' => acceptanceReportRecommendation([
            'recommended_action' => 'reuse_previous',
            'confidence' => 0.95,
            'would_skip_paid_vision' => false,
            'policy' => [
                'enabled' => true,
                'dry_run_only' => false,
                'allowed_live_action' => 'reuse_previous',
                'blocked_reason' => null,
            ],
        ]),
    ]);

    $exitCode = Artisan::call('intake:routing-acceptance-report', [
        '--json' => true,
        '--fail-on-risk' => true,
        '--max-reuse-previous' => 5,
    ]);
    $payload = json_decode(trim(Artisan::output()), true);
    $riskCodes = array_column($payload['acceptance']['risks'], 'code');

    expect($exitCode)->toBe(1)
        ->and($payload['summary']['policy_enabled_count'])->toBe(1)
        ->and($payload['summary']['allowed_live_action_count'])->toBe(1)
        ->and($payload['summary']['policy_dry_run_only_counts'])->toBe(['yes' => 0, 'no' => 1, 'unknown' => 0])
        ->and($riskCodes)->toContain('policy_live_enabled')
        ->and($riskCodes)->toContain('allowed_live_action_present')
        ->and($payload['risk_details']['risky_rows'][0]['risk_codes'])->toContain('policy_live_enabled')
        ->and($payload['risk_details']['risky_rows'][0]['risk_codes'])->toContain('allowed_live_action_present');
});

test('raw ocr text phone provider payload full address and hashes are not printed', function () {
    createAcceptanceReportIntake([
        'raw_ocr_text' => 'Sensitive OCR text 9876543210 sk-proj-secret',
        'parsed_json' => acceptanceReportParsed('Sensitive Candidate', '9876543210', '123 Secret Road, Pune'),
        'content_hash' => 'hash-that-should-not-print',
        'routing_recommendation_json' => acceptanceReportRecommendation([
            'recommended_action' => 'manual_review',
            'reason_codes' => ['critical_field_parser_proposal_available'],
            'signals' => [
                'estimated_paid_vision_avoidable' => true,
                'provider_payload' => 'sk-proj-provider-payload',
                'matched_hash_type' => 'content_hash',
            ],
        ]),
    ]);

    $exitCode = Artisan::call('intake:routing-acceptance-report');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Parser proposal avoidable')
        ->and($output)->toContain('parser_proposal_avoided')
        ->and($output)->not->toContain('Sensitive OCR text')
        ->and($output)->not->toContain('9876543210')
        ->and($output)->not->toContain('Sensitive Candidate')
        ->and($output)->not->toContain('123 Secret Road')
        ->and($output)->not->toContain('sk-proj-secret')
        ->and($output)->not->toContain('sk-proj-provider-payload')
        ->and($output)->not->toContain('hash-that-should-not-print')
        ->and($output)->not->toContain('content_hash');
});

test('command does not mutate intake data routing quality ocr attempts parse status or profile', function () {
    $member = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before Acceptance Report',
    ]);
    $parsed = acceptanceReportParsed('Read Only Candidate', '9876543210');
    $quality = ['score' => 0.95, 'layout_score' => 0.8];
    $fieldConfidence = [
        'date_of_birth' => ['score' => 0.2, 'present' => false, 'reason' => 'missing_parsed_value'],
    ];
    $routing = acceptanceReportRecommendation([
        'recommended_action' => 'call_sarvam',
        'reason_codes' => ['critical_field_raw_evidence_absent'],
        'would_call_paid_vision' => true,
    ]);
    $telemetry = acceptanceReportTelemetry([
        'last_quality_score' => 0.95,
    ]);
    $intake = createAcceptanceReportIntake([
        'uploaded_by' => $member->id,
        'matrimony_profile_id' => $profile->id,
        'raw_ocr_text' => 'Original OCR text 9876543210',
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
        'quality_summary_json' => $quality,
        'field_confidence_json' => $fieldConfidence,
        'routing_recommendation_json' => $routing,
        'routing_telemetry_json' => $telemetry,
    ]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'source' => 'mobile_app',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'Attempt raw text',
        'quality_score' => 0.95,
        'cost_units' => 0,
    ]);
    $attemptCountBefore = BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count();

    $payload = acceptanceReportJson();

    $intake->refresh();
    $profile->refresh();

    expect($payload['summary']['total_scanned'])->toBe(1)
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($intake->raw_ocr_text)->toBe('Original OCR text 9876543210')
        ->and($intake->quality_summary_json)->toEqual($quality)
        ->and($intake->field_confidence_json)->toEqual($fieldConfidence)
        ->and($intake->routing_recommendation_json)->toEqual($routing)
        ->and($intake->routing_telemetry_json)->toEqual($telemetry)
        ->and($intake->parse_status)->toBe('parsed')
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe($attemptCountBefore)
        ->and($profile->full_name)->toBe('Profile Before Acceptance Report');
});

function acceptanceReportJson(array $parameters = []): array
{
    $exitCode = Artisan::call('intake:routing-acceptance-report', array_merge($parameters, ['--json' => true]));
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);

    return $payload;
}

function createAcceptanceReportIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => acceptanceReportParsed('Parsed Candidate', '9876543210'),
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
        'quality_summary_json' => ['score' => 0.9],
        'field_confidence_json' => [],
        'routing_recommendation_json' => acceptanceReportRecommendation(),
        'routing_telemetry_json' => acceptanceReportTelemetry(),
    ], $overrides));
}

function acceptanceReportRecommendation(array $overrides = []): array
{
    return array_replace_recursive([
        'mode' => 'dry_run',
        'recommended_action' => 'manual_review',
        'reason_codes' => [],
        'confidence' => 0.7,
        'would_skip_paid_vision' => false,
        'would_call_paid_vision' => false,
        'signals' => [
            'quality_score' => 0.9,
            'has_raw_ocr_text' => true,
            'has_parsed_json' => true,
        ],
    ], $overrides);
}

function acceptanceReportTelemetry(array $overrides = []): array
{
    return array_merge([
        'mode' => 'dry_run',
        'sarvam_attempt_count' => 0,
        'cheap_ocr_attempt_count' => 0,
        'failed_provider_count' => 0,
        'reuse_candidate_found' => false,
        'last_provider_failure_code' => null,
        'last_quality_score' => 0.9,
        'last_layout_score' => null,
        'duration_ms' => null,
        'cost_units' => null,
    ], $overrides);
}

function acceptanceReportParsed(string $name, string $phone, string $address = 'Pune'): array
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
