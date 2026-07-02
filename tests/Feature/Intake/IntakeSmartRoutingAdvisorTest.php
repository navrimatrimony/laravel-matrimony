<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\User;
use App\Services\Intake\IntakeSmartRoutingAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('high quality cheap OCR recommends cheap OCR only without mutating truth fields', function () {
    $parsed = [
        'core' => [
            'full_name' => 'Parsed Candidate',
            'date_of_birth' => '1996-04-12',
        ],
        'contacts' => [
            ['phone_number' => '9876543210'],
        ],
    ];
    $intake = createRoutingAdvisorIntake([
        'raw_ocr_text' => 'Original immutable OCR text',
        'last_parse_input_text' => "Name: Parsed Candidate\nMobile: 9876543210",
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
        'ai_calls_used' => 1,
        'quality_summary_json' => [
            'score' => 0.9,
            'is_low' => false,
        ],
        'failure_codes_json' => [],
        'field_confidence_json' => [
            'full_name' => [
                'score' => 0.92,
                'present' => true,
            ],
            'primary_contact_number' => [
                'score' => 0.9,
                'present' => true,
            ],
        ],
    ]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'source' => 'mobile_app',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'Name: Parsed Candidate',
        'quality_score' => 0.9,
        'cost_units' => 0,
    ]);

    $stored = app(IntakeSmartRoutingAdvisor::class)->storeForIntake($intake);

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('cheap_ocr_only')
        ->and($stored->routing_recommendation_json['would_skip_paid_vision'])->toBeTrue()
        ->and($stored->routing_recommendation_json['would_call_paid_vision'])->toBeFalse()
        ->and($stored->routing_telemetry_json['cheap_ocr_attempt_count'])->toBe(1)
        ->and($stored->raw_ocr_text)->toBe('Original immutable OCR text')
        ->and($stored->parsed_json)->toBe($parsed)
        ->and($stored->parse_status)->toBe('parsed')
        ->and($stored->ai_calls_used)->toBe(1);
});

test('empty text with image recommends paid vision in dry run', function () {
    $intake = createRoutingAdvisorIntake([
        'file_path' => 'intakes/empty.jpg',
        'raw_ocr_text' => '',
        'parsed_json' => [],
        'parse_status' => 'error',
        'quality_summary_json' => [
            'score' => 0.0,
            'is_low' => true,
        ],
        'failure_codes_json' => [
            BiodataIntakeOcrAttempt::FAILURE_EMPTY_TEXT,
        ],
        'field_confidence_json' => [],
    ]);

    $recommendation = app(IntakeSmartRoutingAdvisor::class)->recommend($intake);

    expect($recommendation['recommended_action'])->toBe('call_sarvam')
        ->and($recommendation['would_call_paid_vision'])->toBeTrue()
        ->and($recommendation['reason_codes'])->toContain('empty_text')
        ->and($recommendation['reason_codes'])->toContain('low_quality_cheap_ocr');
});

test('low quality cheap OCR recommends paid vision without changing intake truth fields', function () {
    $parsed = [
        'core' => [
            'full_name' => 'Weak Candidate',
        ],
    ];
    $intake = createRoutingAdvisorIntake([
        'file_path' => 'intakes/weak.jpg',
        'raw_ocr_text' => 'Original weak OCR text',
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
        'quality_summary_json' => [
            'score' => 0.42,
            'is_low' => true,
        ],
        'failure_codes_json' => [],
        'field_confidence_json' => [
            'date_of_birth' => [
                'score' => 0.2,
                'present' => false,
            ],
        ],
    ]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'source' => 'server_parse',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'Weak Candidate',
        'quality_score' => 0.42,
    ]);

    $stored = app(IntakeSmartRoutingAdvisor::class)->storeForIntake($intake);

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('call_sarvam')
        ->and($stored->routing_recommendation_json['would_call_paid_vision'])->toBeTrue()
        ->and($stored->routing_recommendation_json['would_skip_paid_vision'])->toBeFalse()
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('low_quality_cheap_ocr')
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('field_confidence_low')
        ->and($stored->raw_ocr_text)->toBe('Original weak OCR text')
        ->and($stored->parsed_json)->toBe($parsed)
        ->and($stored->parse_status)->toBe('parsed');
});

test('duplicate content signal recommends previous reuse but never copies parsed json', function () {
    $sourceParsed = [
        'core' => [
            'full_name' => 'Source Candidate',
        ],
    ];
    $targetParsed = [
        'core' => [
            'full_name' => 'Target Candidate',
        ],
    ];
    createRoutingAdvisorIntake([
        'content_hash' => 'same-image-hash',
        'raw_ocr_text' => 'Source OCR text',
        'parsed_json' => $sourceParsed,
        'parse_status' => 'parsed',
    ]);
    $target = createRoutingAdvisorIntake([
        'content_hash' => 'same-image-hash',
        'raw_ocr_text' => 'Target OCR text',
        'parsed_json' => $targetParsed,
        'parse_status' => 'parsed',
    ]);

    $stored = app(IntakeSmartRoutingAdvisor::class)->storeForIntake($target);

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('reuse_previous')
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('duplicate_detected')
        ->and($stored->routing_telemetry_json['reuse_candidate_found'])->toBeTrue()
        ->and($stored->raw_ocr_text)->toBe('Target OCR text')
        ->and($stored->parsed_json)->toBe($targetParsed)
        ->and($stored->parsed_json)->not->toBe($sourceParsed);
});

test('provider failure is captured in telemetry and reason codes', function () {
    $intake = createRoutingAdvisorIntake([
        'file_path' => 'intakes/sarvam-timeout.jpg',
        'parse_status' => 'error',
        'quality_summary_json' => [
            'score' => 0.2,
            'is_low' => true,
        ],
        'failure_codes_json' => [
            BiodataIntakeOcrAttempt::FAILURE_PROVIDER_TIMEOUT,
        ],
    ]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
        'source' => 'server_ai_vision',
        'status' => BiodataIntakeOcrAttempt::STATUS_FAILED,
        'failure_code' => BiodataIntakeOcrAttempt::FAILURE_PROVIDER_TIMEOUT,
        'failure_message' => 'Provider timed out',
        'duration_ms' => 1200,
        'cost_units' => 1,
    ]);

    $stored = app(IntakeSmartRoutingAdvisor::class)->storeForIntake($intake);

    expect($stored->routing_recommendation_json['recommended_action'])->toBe('manual_review')
        ->and($stored->routing_recommendation_json['reason_codes'])->toContain('provider_error')
        ->and($stored->routing_telemetry_json['sarvam_attempt_count'])->toBe(1)
        ->and($stored->routing_telemetry_json['failed_provider_count'])->toBe(1)
        ->and($stored->routing_telemetry_json['last_provider_failure_code'])->toBe(BiodataIntakeOcrAttempt::FAILURE_PROVIDER_TIMEOUT)
        ->and($stored->routing_telemetry_json['cost_units'])->toEqual(1.0);
});

function createRoutingAdvisorIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}
