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
