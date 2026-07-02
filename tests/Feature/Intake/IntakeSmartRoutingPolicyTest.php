<?php

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\User;
use App\Services\AiVisionExtractionService;
use App\Services\Intake\IntakeSmartRoutingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('default policy blocks all live actions', function () {
    $decision = app(IntakeSmartRoutingPolicy::class)->evaluate(policyRoutingRecommendation());

    expect($decision['enabled'])->toBeFalse()
        ->and($decision['dry_run_only'])->toBeTrue()
        ->and($decision['allowed_live_action'])->toBeNull()
        ->and($decision['blocked_reason'])->toBe('routing_disabled');
});

test('dry run only blocks even eligible duplicate', function () {
    setSmartRoutingPolicySettings([
        IntakeSmartRoutingPolicy::KEY_ENABLED => '1',
    ]);

    $decision = app(IntakeSmartRoutingPolicy::class)->evaluate(policyRoutingRecommendation());

    expect($decision['enabled'])->toBeTrue()
        ->and($decision['dry_run_only'])->toBeTrue()
        ->and($decision['allowed_live_action'])->toBeNull()
        ->and($decision['blocked_reason'])->toBe('dry_run_only');
});

test('skip paid vision disabled blocks eligible reuse previous', function () {
    setSmartRoutingPolicySettings([
        IntakeSmartRoutingPolicy::KEY_ENABLED => '1',
        IntakeSmartRoutingPolicy::KEY_DRY_RUN_ONLY => '0',
        IntakeSmartRoutingPolicy::KEY_SKIP_PAID_VISION_ENABLED => '0',
        IntakeSmartRoutingPolicy::KEY_REUSE_PREVIOUS_ENABLED => '1',
    ]);

    $decision = app(IntakeSmartRoutingPolicy::class)->evaluate(policyRoutingRecommendation());

    expect($decision['allowed_live_action'])->toBeNull()
        ->and($decision['blocked_reason'])->toBe('skip_paid_vision_disabled');
});

test('eligible trusted duplicate is allowed only when all flags are explicitly enabled', function () {
    enableSmartRoutingPolicy();

    $decision = app(IntakeSmartRoutingPolicy::class)->evaluate(policyRoutingRecommendation([
        'confidence' => 0.95,
        'signals' => policyRoutingSignals([
            'duplicate_reuse_eligible' => true,
            'duplicate_reference_has_reviewed_snapshot' => true,
        ]),
    ]));

    expect($decision['enabled'])->toBeTrue()
        ->and($decision['dry_run_only'])->toBeFalse()
        ->and($decision['allowed_live_action'])->toBe('reuse_previous')
        ->and($decision['blocked_reason'])->toBeNull()
        ->and($decision['guardrails']['duplicate_reuse_eligible'])->toBeTrue()
        ->and($decision['guardrails']['duplicate_reference_has_reviewed_snapshot'])->toBeTrue();
});

test('weak duplicate remains blocked even when flags are enabled', function () {
    enableSmartRoutingPolicy([
        IntakeSmartRoutingPolicy::KEY_REQUIRE_HUMAN_REVIEWED_REFERENCE => '0',
    ]);

    $decision = app(IntakeSmartRoutingPolicy::class)->evaluate(policyRoutingRecommendation([
        'confidence' => 0.95,
        'signals' => policyRoutingSignals([
            'duplicate_reuse_eligible' => false,
            'duplicate_reuse_trust' => 'weak',
            'duplicate_reference_has_reviewed_snapshot' => false,
        ]),
    ]));

    expect($decision['allowed_live_action'])->toBeNull()
        ->and($decision['blocked_reason'])->toBe('duplicate_reuse_not_eligible')
        ->and($decision['guardrails']['duplicate_reuse_trust'])->toBe('weak');
});

test('low confidence recommendation is blocked', function () {
    enableSmartRoutingPolicy();

    $decision = app(IntakeSmartRoutingPolicy::class)->evaluate(policyRoutingRecommendation([
        'confidence' => 0.72,
    ]));

    expect($decision['allowed_live_action'])->toBeNull()
        ->and($decision['blocked_reason'])->toBe('confidence_below_min')
        ->and($decision['guardrails']['min_confidence'])->toBe(0.9);
});

test('action not allowlisted is blocked', function () {
    enableSmartRoutingPolicy([
        IntakeSmartRoutingPolicy::KEY_ALLOW_SARVAM_SKIP_ACTIONS => json_encode(['cheap_ocr_only']),
    ]);

    $decision = app(IntakeSmartRoutingPolicy::class)->evaluate(policyRoutingRecommendation());

    expect($decision['allowed_live_action'])->toBeNull()
        ->and($decision['blocked_reason'])->toBe('action_not_allowlisted')
        ->and($decision['guardrails']['allow_sarvam_skip_actions'])->toBe(['cheap_ocr_only']);
});

test('policy evaluation does not call providers or mutate intake evidence', function () {
    $this->mock(AiVisionExtractionService::class, function ($mock): void {
        $mock->shouldNotReceive('extractTextForIntake');
    });
    enableSmartRoutingPolicy();
    $user = User::factory()->create();
    $parsed = [
        'core' => [
            'full_name' => 'Policy Candidate',
        ],
    ];
    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => $parsed,
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);
    $attemptCountBefore = BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count();

    $decision = app(IntakeSmartRoutingPolicy::class)->evaluate(policyRoutingRecommendation());

    $intake->refresh();

    expect($decision['allowed_live_action'])->toBe('reuse_previous')
        ->and($intake->parse_status)->toBe('parsed')
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($intake->raw_ocr_text)->toBe('Original OCR text')
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe($attemptCountBefore);
});

function enableSmartRoutingPolicy(array $overrides = []): void
{
    setSmartRoutingPolicySettings(array_merge([
        IntakeSmartRoutingPolicy::KEY_ENABLED => '1',
        IntakeSmartRoutingPolicy::KEY_DRY_RUN_ONLY => '0',
        IntakeSmartRoutingPolicy::KEY_SKIP_PAID_VISION_ENABLED => '1',
        IntakeSmartRoutingPolicy::KEY_REUSE_PREVIOUS_ENABLED => '1',
        IntakeSmartRoutingPolicy::KEY_MIN_CONFIDENCE => '0.90',
        IntakeSmartRoutingPolicy::KEY_REQUIRE_HUMAN_REVIEWED_REFERENCE => '1',
        IntakeSmartRoutingPolicy::KEY_ALLOW_SARVAM_SKIP_ACTIONS => json_encode(['reuse_previous']),
    ], $overrides));
}

function setSmartRoutingPolicySettings(array $settings): void
{
    foreach ($settings as $key => $value) {
        AdminSetting::setValue((string) $key, is_scalar($value) ? (string) $value : json_encode($value));
    }
}

function policyRoutingRecommendation(array $overrides = []): array
{
    return array_merge([
        'mode' => 'dry_run',
        'recommended_action' => 'reuse_previous',
        'reason_codes' => ['duplicate_detected', 'duplicate_reuse_eligible'],
        'confidence' => 0.95,
        'would_skip_paid_vision' => true,
        'would_call_paid_vision' => false,
        'signals' => policyRoutingSignals(),
    ], $overrides);
}

function policyRoutingSignals(array $overrides = []): array
{
    return array_merge([
        'duplicate_detected' => true,
        'duplicate_reuse_eligible' => true,
        'duplicate_reuse_trust' => 'trusted',
        'duplicate_reference_intake_id' => 10,
        'duplicate_reference_has_reviewed_snapshot' => true,
        'duplicate_reference_reason' => 'reference_has_reviewed_snapshot',
    ], $overrides);
}
