<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('report summarizes action counts and reason code counts', function () {
    $reuse = createRoutingDryRunReportIntake([
        'routing_recommendation_json' => routingDryRunReportRecommendation([
            'recommended_action' => 'reuse_previous',
            'reason_codes' => ['duplicate_detected'],
            'confidence' => 0.91,
            'would_skip_paid_vision' => true,
        ]),
        'routing_telemetry_json' => routingDryRunReportTelemetry([
            'last_quality_score' => 0.9,
        ]),
    ]);
    createRoutingDryRunReportIntake([
        'routing_recommendation_json' => routingDryRunReportRecommendation([
            'recommended_action' => 'call_sarvam',
            'reason_codes' => ['low_quality_cheap_ocr', 'field_confidence_low'],
            'confidence' => 0.64,
            'would_call_paid_vision' => true,
        ]),
        'routing_telemetry_json' => routingDryRunReportTelemetry([
            'failed_provider_count' => 2,
            'last_quality_score' => 0.42,
        ]),
    ]);
    createRoutingDryRunReportIntake([
        'routing_recommendation_json' => routingDryRunReportRecommendation([
            'recommended_action' => 'unknown',
            'reason_codes' => ['no_signal'],
            'confidence' => 0.0,
        ]),
        'routing_telemetry_json' => routingDryRunReportTelemetry(),
    ]);

    $payload = routingDryRunReportJson();

    expect($payload['total_scanned'])->toBe(3)
        ->and($payload['action_counts']['reuse_previous'])->toBe(1)
        ->and($payload['action_counts']['call_sarvam'])->toBe(1)
        ->and($payload['action_counts']['unknown'])->toBe(1)
        ->and($payload['reason_code_counts']['duplicate_detected'])->toBe(1)
        ->and($payload['reason_code_counts']['low_quality_cheap_ocr'])->toBe(1)
        ->and($payload['reason_code_counts']['field_confidence_low'])->toBe(1)
        ->and($payload['reason_code_counts']['no_signal'])->toBe(1)
        ->and($payload['would_skip_paid_vision_count'])->toBe(1)
        ->and($payload['would_call_paid_vision_count'])->toBe(1)
        ->and($payload['unknown_no_signal_count'])->toBe(1)
        ->and($payload['provider_failure_count'])->toBe(2)
        ->and($payload['average_quality_score'])->toBe(0.66)
        ->and($payload['sample_intake_ids_by_action']['reuse_previous'])->toContain($reuse->id);
});

test('action filter works', function () {
    createRoutingDryRunReportIntake([
        'routing_recommendation_json' => routingDryRunReportRecommendation([
            'recommended_action' => 'reuse_previous',
            'reason_codes' => ['duplicate_detected'],
        ]),
    ]);
    createRoutingDryRunReportIntake([
        'routing_recommendation_json' => routingDryRunReportRecommendation([
            'recommended_action' => 'manual_review',
            'reason_codes' => ['parser_no_fields'],
        ]),
    ]);

    $payload = routingDryRunReportJson(['--action' => 'reuse_previous']);

    expect($payload['filters']['action'])->toBe('reuse_previous')
        ->and($payload['total_scanned'])->toBe(1)
        ->and($payload['action_counts']['reuse_previous'])->toBe(1)
        ->and($payload['action_counts']['manual_review'])->toBe(0)
        ->and($payload['reason_code_counts'])->toHaveKey('duplicate_detected')
        ->and($payload['reason_code_counts'])->not->toHaveKey('parser_no_fields');
});

test('json option returns valid json', function () {
    createRoutingDryRunReportIntake([
        'routing_recommendation_json' => routingDryRunReportRecommendation([
            'recommended_action' => 'cheap_ocr_only',
            'reason_codes' => ['high_quality_cheap_ocr'],
            'would_skip_paid_vision' => true,
        ]),
    ]);

    $exitCode = Artisan::call('intake:routing-dry-run-report', ['--json' => true]);
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($payload['success'])->toBeTrue()
        ->and($payload['total_scanned'])->toBe(1);
});

test('details output includes safe signal summary without raw evidence or provider secrets', function () {
    createRoutingDryRunReportIntake([
        'raw_ocr_text' => 'Sensitive OCR text 9876543210 sk-proj-secret',
        'routing_recommendation_json' => routingDryRunReportRecommendation([
            'recommended_action' => 'reuse_previous',
            'reason_codes' => ['duplicate_detected'],
            'confidence' => 0.95,
            'would_skip_paid_vision' => true,
            'signals' => [
                'duplicate_signal_source' => 'content_hash',
                'duplicate_match_type' => 'exact_content_hash',
                'duplicate_reuse_eligible' => true,
                'duplicate_reuse_trust' => 'trusted',
                'duplicate_reference_intake_id' => 123,
                'duplicate_reference_reason' => 'reference_has_reviewed_snapshot',
                'duplicate_reference_quality_score' => 0.91,
                'duplicate_reference_has_reviewed_snapshot' => true,
                'duplicate_reference_has_primary_ocr_attempt' => true,
                'duplicate_reference_has_sarvam_attempt' => false,
                'duplicate_reference_has_verifiable_ocr_evidence' => true,
                'duplicate_reference_quality_source' => 'reviewed',
                'duplicate_reference_ocr_attempt_count' => 2,
                'duplicate_reference_sarvam_attempt_count' => 0,
                'backfilled_quality_not_trusted' => false,
                'matched_hash_type' => 'content_hash',
                'has_parsed_json' => true,
                'has_raw_ocr_text' => true,
                'provider_payload' => 'sk-proj-provider-payload',
                'quality_score' => 0.82,
                'ocr_attempt_count' => 2,
                'primary_ocr_attempt_exists' => true,
                'cheap_ocr_attempt_count' => 1,
                'sarvam_attempt_count' => 0,
                'identity_fingerprint_present' => true,
                'normalized_text_hash_present' => true,
                'image_hash_present' => false,
                'duplicate_field_match_eligible' => true,
                'duplicate_field_match_score' => 1.0,
                'duplicate_field_mismatch_codes' => [],
                'low_confidence_critical_fields' => ['primary_contact_number'],
                'low_confidence_important_fields' => ['education'],
                'low_confidence_optional_fields' => ['custom_optional_field'],
                'field_confidence_routing_severity' => 'critical',
                'paid_vision_reasonable_for_field_confidence' => true,
            ],
        ]),
        'routing_telemetry_json' => routingDryRunReportTelemetry([
            'cheap_ocr_attempt_count' => 1,
            'last_quality_score' => 0.82,
        ]),
    ]);

    $exitCode = Artisan::call('intake:routing-dry-run-report', ['--details' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Details: reuse_previous')
        ->and($output)->toContain('source=content_hash')
        ->and($output)->toContain('match=exact_content_hash')
        ->and($output)->toContain('eligible=yes')
        ->and($output)->toContain('trust=trusted')
        ->and($output)->toContain('ref=123')
        ->and($output)->toContain('ref_reason=reference_has_reviewed_snapshot')
        ->and($output)->toContain('ref_quality=0.91')
        ->and($output)->toContain('ref_reviewed=yes')
        ->and($output)->toContain('ref_primary=yes')
        ->and($output)->toContain('ref_sarvam=no')
        ->and($output)->toContain('ref_verifiable=yes')
        ->and($output)->toContain('ref_quality_source=reviewed')
        ->and($output)->toContain('ref_ocr_attempts=2')
        ->and($output)->toContain('ref_sarvam_attempts=0')
        ->and($output)->toContain('backfilled_quality_trusted=n/a')
        ->and($output)->toContain('Ref verifiable OCR evidence')
        ->and($output)->toContain('Ref quality source')
        ->and($output)->toContain('Backfilled quality trusted?')
        ->and($output)->toContain('hash=content_hash')
        ->and($output)->toContain('quality=0.82')
        ->and($output)->toContain('cheap=1')
        ->and($output)->toContain('sarvam=0')
        ->and($output)->toContain('field_match=yes')
        ->and($output)->toContain('field_score=1')
        ->and($output)->toContain('field_mismatches=none')
        ->and($output)->toContain('Critical low fields')
        ->and($output)->toContain('Important low fields')
        ->and($output)->toContain('Optional low fields')
        ->and($output)->toContain('Field severity')
        ->and($output)->toContain('Paid vision reasonable')
        ->and($output)->toContain('fc_critical=primary_contact_number')
        ->and($output)->toContain('fc_important=education')
        ->and($output)->toContain('fc_optional=custom_optional_field')
        ->and($output)->toContain('fc_severity=critical')
        ->and($output)->toContain('fc_paid_reasonable=yes')
        ->and($output)->toContain('Policy enabled')
        ->and($output)->toContain('routing_disabled')
        ->and($output)->toContain('skip=no')
        ->and($output)->toContain('reuse=no')
        ->and($output)->toContain('allowlist=reuse_previous')
        ->and($output)->not->toContain('Sensitive OCR text')
        ->and($output)->not->toContain('9876543210')
        ->and($output)->not->toContain('sk-proj-secret')
        ->and($output)->not->toContain('sk-proj-provider-payload');
});

test('details json shows backfilled quality is not trusted as verifiable evidence', function () {
    createRoutingDryRunReportIntake([
        'routing_recommendation_json' => routingDryRunReportRecommendation([
            'recommended_action' => 'manual_review',
            'reason_codes' => ['duplicate_detected', 'backfilled_quality_not_trusted', 'duplicate_detected_but_untrusted'],
            'confidence' => 0.42,
            'signals' => [
                'duplicate_signal_source' => 'content_hash',
                'duplicate_match_type' => 'exact_content_hash',
                'duplicate_reuse_eligible' => false,
                'duplicate_reuse_trust' => 'weak',
                'duplicate_reference_intake_id' => 321,
                'duplicate_reference_reason' => 'reference_parsed_with_backfilled_quality_only',
                'duplicate_reference_quality_score' => 0.91,
                'duplicate_reference_has_reviewed_snapshot' => false,
                'duplicate_reference_has_primary_ocr_attempt' => false,
                'duplicate_reference_has_sarvam_attempt' => false,
                'duplicate_reference_has_verifiable_ocr_evidence' => false,
                'duplicate_reference_quality_source' => 'backfilled',
                'duplicate_reference_ocr_attempt_count' => 0,
                'duplicate_reference_sarvam_attempt_count' => 0,
                'backfilled_quality_not_trusted' => true,
                'has_parsed_json' => true,
                'has_raw_ocr_text' => true,
                'quality_score' => 0.89,
                'ocr_attempt_count' => 0,
                'cheap_ocr_attempt_count' => 0,
                'sarvam_attempt_count' => 0,
                'duplicate_field_match_eligible' => true,
                'duplicate_field_match_score' => 1.0,
                'duplicate_field_mismatch_codes' => [],
                'low_confidence_critical_fields' => [],
                'low_confidence_important_fields' => ['education'],
                'low_confidence_optional_fields' => [],
                'field_confidence_routing_severity' => 'important_only',
                'paid_vision_reasonable_for_field_confidence' => false,
            ],
        ]),
    ]);

    $payload = routingDryRunReportJson(['--details' => true]);
    $row = $payload['details_by_action']['manual_review'][0];

    expect($row['duplicate_reuse_eligible'])->toBe('no')
        ->and($row['duplicate_reference_has_verifiable_ocr_evidence'])->toBe('no')
        ->and($row['duplicate_reference_quality_source'])->toBe('backfilled')
        ->and($row['duplicate_reference_ocr_attempt_count'])->toBe(0)
        ->and($row['duplicate_reference_sarvam_attempt_count'])->toBe(0)
        ->and($row['backfilled_quality_not_trusted'])->toBe('yes')
        ->and($row['backfilled_quality_trusted'])->toBe('no')
        ->and($row['signal_summary'])->toContain('ref_verifiable=no')
        ->and($row['signal_summary'])->toContain('ref_quality_source=backfilled')
        ->and($row['signal_summary'])->toContain('ref_ocr_attempts=0')
        ->and($row['signal_summary'])->toContain('ref_sarvam_attempts=0')
        ->and($row['signal_summary'])->toContain('backfilled_quality_trusted=no')
        ->and($row['low_confidence_critical_fields'])->toBe([])
        ->and($row['low_confidence_important_fields'])->toBe(['education'])
        ->and($row['low_confidence_optional_fields'])->toBe([])
        ->and($row['field_confidence_routing_severity'])->toBe('important_only')
        ->and($row['paid_vision_reasonable_for_field_confidence'])->toBe('no')
        ->and($row['signal_summary'])->toContain('fc_critical=none')
        ->and($row['signal_summary'])->toContain('fc_important=education')
        ->and($row['signal_summary'])->toContain('fc_severity=important_only')
        ->and($row['signal_summary'])->toContain('fc_paid_reasonable=no')
        ->and($row['signal_summary'])->not->toContain('9876543210')
        ->and($row['signal_summary'])->not->toContain('sk-proj');
});

test('details json shows duplicate field mismatch codes', function () {
    createRoutingDryRunReportIntake([
        'routing_recommendation_json' => routingDryRunReportRecommendation([
            'recommended_action' => 'manual_review',
            'reason_codes' => ['duplicate_detected', 'duplicate_field_mismatch', 'duplicate_detected_but_untrusted'],
            'confidence' => 0.42,
            'signals' => [
                'duplicate_reuse_eligible' => false,
                'duplicate_reuse_trust' => 'field_mismatch',
                'duplicate_reference_intake_id' => 321,
                'duplicate_field_match_eligible' => false,
                'duplicate_field_match_score' => 0.6667,
                'duplicate_field_mismatch_codes' => ['contact_mismatch'],
                'current_reference_contact_match' => 'no',
                'current_reference_dob_match' => 'yes',
                'current_reference_name_match' => 'yes',
                'current_reference_core_fields_compared' => 3,
            ],
        ]),
    ]);

    $payload = routingDryRunReportJson(['--details' => true]);
    $row = $payload['details_by_action']['manual_review'][0];

    expect($row['duplicate_field_match_eligible'])->toBe('no')
        ->and($row['duplicate_field_match_score'])->toBe(0.6667)
        ->and($row['duplicate_field_mismatch_codes'])->toBe(['contact_mismatch'])
        ->and($row['signal_summary'])->toContain('field_match=no')
        ->and($row['signal_summary'])->toContain('field_mismatches=contact_mismatch');
});

test('details json shows default disabled policy as blocked for eligible reuse recommendation', function () {
    createRoutingDryRunReportIntake([
        'routing_recommendation_json' => routingDryRunReportRecommendation([
            'recommended_action' => 'reuse_previous',
            'reason_codes' => ['duplicate_detected'],
            'confidence' => 0.95,
            'would_skip_paid_vision' => true,
            'signals' => [
                'duplicate_reuse_eligible' => true,
                'duplicate_reuse_trust' => 'trusted',
                'duplicate_reference_intake_id' => 345,
                'duplicate_reference_has_reviewed_snapshot' => true,
            ],
        ]),
    ]);

    $payload = routingDryRunReportJson(['--details' => true]);
    $row = $payload['details_by_action']['reuse_previous'][0];

    expect($row['policy_enabled'])->toBe('no')
        ->and($row['policy_dry_run_only'])->toBe('yes')
        ->and($row['policy_allowed_live_action'])->toBe('none')
        ->and($row['policy_blocked_reason'])->toBe('routing_disabled')
        ->and($row['policy_guardrail_summary'])->toContain('skip=no')
        ->and($row['policy_guardrail_summary'])->toContain('reuse=no')
        ->and($row['policy_guardrail_summary'])->toContain('eligible=yes')
        ->and($row['policy_guardrail_summary'])->toContain('ref_reviewed=yes');
});

test('details json shows allowed live action when policy config flags are explicitly enabled', function () {
    enableRoutingDryRunReportPolicyConfig();

    createRoutingDryRunReportIntake([
        'routing_recommendation_json' => routingDryRunReportRecommendation([
            'recommended_action' => 'reuse_previous',
            'reason_codes' => ['duplicate_detected'],
            'confidence' => 0.95,
            'would_skip_paid_vision' => true,
            'signals' => [
                'duplicate_reuse_eligible' => true,
                'duplicate_reuse_trust' => 'trusted',
                'duplicate_reference_intake_id' => 456,
                'duplicate_reference_has_reviewed_snapshot' => true,
            ],
        ]),
    ]);

    $payload = routingDryRunReportJson(['--details' => true]);
    $row = $payload['details_by_action']['reuse_previous'][0];

    expect($row['policy_enabled'])->toBe('yes')
        ->and($row['policy_dry_run_only'])->toBe('no')
        ->and($row['policy_allowed_live_action'])->toBe('reuse_previous')
        ->and($row['policy_blocked_reason'])->toBe('none')
        ->and($row['policy_guardrail_summary'])->toContain('skip=yes')
        ->and($row['policy_guardrail_summary'])->toContain('reuse=yes')
        ->and($row['policy_guardrail_summary'])->toContain('eligible=yes')
        ->and($row['policy_guardrail_summary'])->toContain('ref_reviewed=yes');
});

test('details json keeps weak duplicate blocked when policy config flags are enabled', function () {
    enableRoutingDryRunReportPolicyConfig();

    createRoutingDryRunReportIntake([
        'routing_recommendation_json' => routingDryRunReportRecommendation([
            'recommended_action' => 'reuse_previous',
            'reason_codes' => ['duplicate_detected'],
            'confidence' => 0.95,
            'would_skip_paid_vision' => true,
            'signals' => [
                'duplicate_reuse_eligible' => false,
                'duplicate_reuse_trust' => 'weak',
                'duplicate_reference_intake_id' => 567,
                'duplicate_reference_has_reviewed_snapshot' => true,
            ],
        ]),
    ]);

    $payload = routingDryRunReportJson(['--details' => true]);
    $row = $payload['details_by_action']['reuse_previous'][0];

    expect($row['policy_enabled'])->toBe('yes')
        ->and($row['policy_dry_run_only'])->toBe('no')
        ->and($row['policy_allowed_live_action'])->toBe('none')
        ->and($row['policy_blocked_reason'])->toBe('duplicate_reuse_not_eligible')
        ->and($row['policy_guardrail_summary'])->toContain('eligible=no')
        ->and($row['policy_guardrail_summary'])->toContain('ref_reviewed=yes');
});

test('command does not mutate routing data evidence parse status ocr attempts or profile', function () {
    $member = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before Routing Report',
    ]);
    $routingRecommendation = routingDryRunReportRecommendation([
        'recommended_action' => 'call_sarvam',
        'reason_codes' => ['provider_error'],
        'would_call_paid_vision' => true,
    ]);
    $routingTelemetry = routingDryRunReportTelemetry([
        'failed_provider_count' => 1,
        'last_provider_failure_code' => 'provider_timeout',
        'last_quality_score' => 0.31,
    ]);
    $parsed = [
        'core' => [
            'full_name' => 'Parsed Candidate',
            'date_of_birth' => '1996-04-12',
        ],
    ];
    $intake = createRoutingDryRunReportIntake([
        'uploaded_by' => $member->id,
        'matrimony_profile_id' => $profile->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
        'routing_recommendation_json' => $routingRecommendation,
        'routing_telemetry_json' => $routingTelemetry,
    ]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'source' => 'mobile_app',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'Name: Parsed Candidate',
        'quality_score' => 0.31,
        'cost_units' => 0,
    ]);
    $attemptCountBefore = BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count();

    $payload = routingDryRunReportJson();

    $intake->refresh();
    $profile->refresh();

    expect($payload['provider_failure_count'])->toBe(1)
        ->and($intake->routing_recommendation_json)->toEqual($routingRecommendation)
        ->and($intake->routing_telemetry_json)->toEqual($routingTelemetry)
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($intake->raw_ocr_text)->toBe('Original OCR text')
        ->and($intake->parse_status)->toBe('parsed')
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe($attemptCountBefore)
        ->and($profile->full_name)->toBe('Profile Before Routing Report');
});

function routingDryRunReportJson(array $parameters = []): array
{
    $exitCode = Artisan::call('intake:routing-dry-run-report', array_merge($parameters, ['--json' => true]));
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);

    return $payload;
}

function createRoutingDryRunReportIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Parsed Candidate',
                'date_of_birth' => '1996-04-12',
            ],
        ],
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
        'routing_recommendation_json' => routingDryRunReportRecommendation([
            'recommended_action' => 'unknown',
            'reason_codes' => ['no_signal'],
        ]),
        'routing_telemetry_json' => routingDryRunReportTelemetry(),
    ], $overrides));
}

function routingDryRunReportRecommendation(array $overrides = []): array
{
    return array_merge([
        'mode' => 'dry_run',
        'recommended_action' => 'unknown',
        'reason_codes' => [],
        'confidence' => 0.0,
        'would_skip_paid_vision' => false,
        'would_call_paid_vision' => false,
        'signals' => [],
    ], $overrides);
}

function routingDryRunReportTelemetry(array $overrides = []): array
{
    return array_merge([
        'mode' => 'dry_run',
        'sarvam_attempt_count' => 0,
        'cheap_ocr_attempt_count' => 0,
        'failed_provider_count' => 0,
        'reuse_candidate_found' => false,
        'last_provider_failure_code' => null,
        'last_quality_score' => null,
        'last_layout_score' => null,
        'duration_ms' => null,
        'cost_units' => null,
    ], $overrides);
}

function enableRoutingDryRunReportPolicyConfig(): void
{
    config([
        'intake.smart_routing.enabled' => true,
        'intake.smart_routing.dry_run_only' => false,
        'intake.smart_routing.skip_paid_vision_enabled' => true,
        'intake.smart_routing.reuse_previous_enabled' => true,
        'intake.smart_routing.min_confidence' => 0.90,
        'intake.smart_routing.require_human_reviewed_reference' => true,
        'intake.smart_routing.allow_sarvam_skip_actions' => ['reuse_previous'],
    ]);
}
