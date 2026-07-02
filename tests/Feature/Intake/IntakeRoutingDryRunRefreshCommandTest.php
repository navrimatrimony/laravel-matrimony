<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('command fills routing json for one intake by id without mutating evidence or profile', function () {
    $member = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before Routing Refresh',
    ]);
    $parsed = [
        'core' => [
            'full_name' => 'Parsed Candidate',
            'date_of_birth' => '1996-04-12',
        ],
        'contacts' => [
            ['phone_number' => '9876543210'],
        ],
    ];
    $intake = createRoutingDryRunRefreshIntake([
        'uploaded_by' => $member->id,
        'matrimony_profile_id' => $profile->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
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
    $attemptCountBefore = BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count();

    $this->artisan('intake:routing-dry-run-refresh', ['--id' => $intake->id])
        ->assertExitCode(0);

    $intake->refresh();
    $profile->refresh();

    expect($intake->routing_recommendation_json['recommended_action'])->toBe('cheap_ocr_only')
        ->and($intake->routing_recommendation_json['would_skip_paid_vision'])->toBeTrue()
        ->and($intake->routing_telemetry_json['cheap_ocr_attempt_count'])->toBe(1)
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($intake->raw_ocr_text)->toBe('Original OCR text')
        ->and($intake->parse_status)->toBe('parsed')
        ->and($profile->full_name)->toBe('Profile Before Routing Refresh')
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe($attemptCountBefore);
});

test('dry run computes output without saving routing json', function () {
    $intake = createRoutingDryRunRefreshIntake();

    $this->artisan('intake:routing-dry-run-refresh', [
        '--id' => $intake->id,
        '--dry-run' => true,
    ])->assertExitCode(0);

    $intake->refresh();

    expect($intake->routing_recommendation_json)->toBeNull()
        ->and($intake->routing_telemetry_json)->toBeNull();
});

test('missing only skips intake that already has routing json', function () {
    $intake = createRoutingDryRunRefreshIntake([
        'routing_recommendation_json' => [
            'mode' => 'dry_run',
            'recommended_action' => 'manual_review',
            'reason_codes' => ['existing_reason'],
            'confidence' => 0.12,
            'would_skip_paid_vision' => false,
            'would_call_paid_vision' => false,
            'signals' => [],
        ],
        'routing_telemetry_json' => [
            'mode' => 'dry_run',
            'cheap_ocr_attempt_count' => 99,
        ],
    ]);

    $this->artisan('intake:routing-dry-run-refresh', ['--id' => $intake->id])
        ->assertExitCode(0);

    $intake->refresh();

    expect($intake->routing_recommendation_json['recommended_action'])->toBe('manual_review')
        ->and($intake->routing_recommendation_json['reason_codes'])->toBe(['existing_reason'])
        ->and($intake->routing_telemetry_json['cheap_ocr_attempt_count'])->toBe(99);
});

test('all option refreshes existing routing json', function () {
    $intake = createRoutingDryRunRefreshIntake([
        'routing_recommendation_json' => [
            'mode' => 'dry_run',
            'recommended_action' => 'manual_review',
            'reason_codes' => ['existing_reason'],
            'confidence' => 0.12,
            'would_skip_paid_vision' => false,
            'would_call_paid_vision' => false,
            'signals' => [],
        ],
        'routing_telemetry_json' => [
            'mode' => 'dry_run',
            'cheap_ocr_attempt_count' => 99,
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

    $this->artisan('intake:routing-dry-run-refresh', [
        '--id' => $intake->id,
        '--all' => true,
    ])->assertExitCode(0);

    $intake->refresh();

    expect($intake->routing_recommendation_json['recommended_action'])->toBe('cheap_ocr_only')
        ->and($intake->routing_recommendation_json['reason_codes'])->toContain('high_quality_cheap_ocr')
        ->and($intake->routing_telemetry_json['cheap_ocr_attempt_count'])->toBe(1);
});

function createRoutingDryRunRefreshIntake(array $overrides = []): BiodataIntake
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
            'contacts' => [
                ['phone_number' => '9876543210'],
            ],
        ],
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
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
    ], $overrides));
}
