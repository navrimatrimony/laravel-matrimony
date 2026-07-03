<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('command prints readiness summary', function () {
    createLearningReadinessIntake();

    $exitCode = Artisan::call('intake:learning-readiness-audit', [
        '--min-samples' => 1,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Learning readiness status')
        ->and($output)->toContain('Reviewed snapshot count')
        ->and($output)->toContain('Actor')
        ->and($output)->toContain('admin')
        ->and($output)->toContain('ml_kit')
        ->and($output)->toContain('full_name');
});

test('json output is valid', function () {
    createLearningReadinessIntake();

    $payload = learningReadinessAuditJson();

    expect($payload['success'])->toBeTrue()
        ->and($payload['summary']['total_intakes_scanned'])->toBe(1)
        ->and($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['has_reviewed_snapshot'])->toBeTrue();
});

test('actor filter works', function () {
    createLearningReadinessIntake([
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
    ]);
    $suchak = createLearningReadinessIntake([
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SUCHAK,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
    ]);

    $payload = learningReadinessAuditJson(['--actor' => 'suchak']);

    expect($payload['filters']['actor'])->toBe('suchak')
        ->and($payload['summary']['total_intakes_scanned'])->toBe(1)
        ->and($payload['summary']['actor_provenance_counts']['suchak'])->toBe(1)
        ->and($payload['rows'][0]['intake_id'])->toBe($suchak->id)
        ->and($payload['rows'][0]['review_actor_type'])->toBe('suchak');
});

test('field filter works', function () {
    $education = createLearningReadinessIntake([
        'approval_snapshot_json' => [
            'core' => [
                'highest_education' => 'MCA',
            ],
        ],
        'field_confidence_json' => [
            'education' => [
                'score' => 0.91,
                'present' => true,
                'source_path' => 'core.highest_education',
            ],
        ],
    ]);
    createLearningReadinessIntake([
        'approval_snapshot_json' => [
            'career_history' => [
                [
                    'occupation_title' => 'Engineer',
                ],
            ],
        ],
        'field_confidence_json' => [
            'occupation' => [
                'score' => 0.9,
                'present' => true,
                'source_path' => 'career_history.0.occupation_title',
            ],
        ],
    ]);

    $payload = learningReadinessAuditJson(['--field' => 'education']);

    expect($payload['filters']['field'])->toBe('education')
        ->and($payload['summary']['total_intakes_scanned'])->toBe(1)
        ->and($payload['rows'][0]['intake_id'])->toBe($education->id)
        ->and(array_keys($payload['summary']['corrected_snapshot_coverage_by_field']))->toBe(['education'])
        ->and($payload['summary']['corrected_snapshot_coverage_by_field']['education'])->toBe(1);
});

test('min samples affects readiness status', function () {
    createLearningReadinessIntake();
    createLearningReadinessIntake();

    $offlinePayload = learningReadinessAuditJson(['--min-samples' => 3]);
    $candidatePayload = learningReadinessAuditJson(['--min-samples' => 2]);

    expect($offlinePayload['summary']['learning_readiness_status'])->toBe('ready_for_offline_analysis')
        ->and($offlinePayload['summary']['warnings'])->toContain('min_samples_not_met_for_candidate_rules')
        ->and($candidatePayload['summary']['learning_readiness_status'])->toBe('ready_for_candidate_rules');
});

test('reports not ready when no reviewed snapshots exist', function () {
    createLearningReadinessIntake([
        'approval_snapshot_json' => null,
        'review_actor_type' => null,
        'review_surface' => null,
        'reviewed_at' => null,
    ]);

    $payload = learningReadinessAuditJson(['--include-unreviewed' => true]);

    expect($payload['summary']['total_intakes_scanned'])->toBe(1)
        ->and($payload['summary']['reviewed_snapshot_count'])->toBe(0)
        ->and($payload['summary']['unreviewed_count'])->toBe(1)
        ->and($payload['summary']['learning_readiness_status'])->toBe('not_ready')
        ->and($payload['summary']['blockers'])->toContain('no_reviewed_snapshots');
});

test('reports ready for offline analysis when reviewed snapshots exist but not enough samples', function () {
    createLearningReadinessIntake();

    $payload = learningReadinessAuditJson(['--min-samples' => 2]);

    expect($payload['summary']['reviewed_snapshot_count'])->toBe(1)
        ->and($payload['summary']['learning_readiness_status'])->toBe('ready_for_offline_analysis')
        ->and($payload['summary']['warnings'])->toContain('min_samples_not_met_for_candidate_rules');
});

test('raw ocr text phone candidate name full address provider payload and hashes are not printed', function () {
    $intake = createLearningReadinessIntake([
        'raw_ocr_text' => 'Sensitive raw OCR 9876543210 sk-proj-raw-secret',
        'approval_snapshot_json' => learningReadinessSnapshot('Sensitive Candidate', '9876543210', '123 Secret Full Address, Pune'),
        'field_confidence_json' => [
            'primary_contact_number' => [
                'score' => 0.42,
                'present' => true,
                'reason' => 'phone 9876543210 at 123 Secret Full Address',
                'source_path' => 'contacts.0.phone_number',
            ],
        ],
        'routing_recommendation_json' => learningReadinessRecommendation([
            'reason_codes' => [
                'field_confidence_low',
                'sk-proj-provider-payload',
                'abcdef1234567890abcdef1234567890',
                'content_hash',
            ],
            'signals' => [
                'provider_payload' => 'sk-proj-provider-payload',
                'content_hash' => 'abcdef1234567890abcdef1234567890',
            ],
        ]),
    ]);
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'source' => 'mobile_app',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'Attempt raw OCR 9876543210 Sensitive Candidate',
        'normalized_text' => 'Attempt normalized text',
        'text_hash' => 'abcdef1234567890abcdef1234567890',
    ]);

    $exitCode = Artisan::call('intake:learning-readiness-audit', [
        '--include-unreviewed' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain((string) $intake->id)
        ->and($output)->toContain('primary_contact_number')
        ->and($output)->not->toContain('Sensitive raw OCR')
        ->and($output)->not->toContain('Attempt raw OCR')
        ->and($output)->not->toContain('9876543210')
        ->and($output)->not->toContain('Sensitive Candidate')
        ->and($output)->not->toContain('123 Secret Full Address')
        ->and($output)->not->toContain('sk-proj-raw-secret')
        ->and($output)->not->toContain('sk-proj-provider-payload')
        ->and($output)->not->toContain('abcdef1234567890abcdef1234567890')
        ->and($output)->not->toContain('content_hash');
});

test('command does not mutate intake data ocr attempts parse status profile or review snapshots', function () {
    $member = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before Learning Audit',
    ]);
    $parsed = learningReadinessParsed('Read Only Candidate', '9876543210');
    $snapshot = learningReadinessSnapshot('Reviewed Candidate', '9876543210');
    $quality = ['score' => 0.94, 'layout_score' => 0.8];
    $failureCodes = [BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED];
    $fieldConfidence = [
        'date_of_birth' => ['score' => 0.2, 'present' => false, 'reason' => 'missing_parsed_value'],
    ];
    $routing = learningReadinessRecommendation([
        'recommended_action' => 'manual_review',
        'reason_codes' => ['field_confidence_low'],
    ]);
    $intake = createLearningReadinessIntake([
        'uploaded_by' => $member->id,
        'matrimony_profile_id' => $profile->id,
        'raw_ocr_text' => 'Original OCR text 9876543210',
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
        'approval_snapshot_json' => $snapshot,
        'quality_summary_json' => $quality,
        'failure_codes_json' => $failureCodes,
        'field_confidence_json' => $fieldConfidence,
        'routing_recommendation_json' => $routing,
    ]);
    $attempt = BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'source' => 'mobile_app',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'ML Kit read-only evidence',
        'quality_score' => 0.94,
        'cost_units' => 0,
    ]);
    $attemptCountBefore = BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count();

    $payload = learningReadinessAuditJson(['--include-unreviewed' => true]);

    $intake->refresh();
    $attempt->refresh();
    $profile->refresh();

    expect($payload['summary']['total_intakes_scanned'])->toBe(1)
        ->and($intake->parsed_json)->toBe($parsed)
        ->and($intake->raw_ocr_text)->toBe('Original OCR text 9876543210')
        ->and($intake->approval_snapshot_json)->toEqual($snapshot)
        ->and($intake->quality_summary_json)->toEqual($quality)
        ->and($intake->failure_codes_json)->toEqual($failureCodes)
        ->and($intake->field_confidence_json)->toEqual($fieldConfidence)
        ->and($intake->routing_recommendation_json)->toEqual($routing)
        ->and($intake->parse_status)->toBe('parsed')
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe($attemptCountBefore)
        ->and($attempt->raw_text)->toBe('ML Kit read-only evidence')
        ->and($profile->full_name)->toBe('Profile Before Learning Audit');
});

function learningReadinessAuditJson(array $parameters = []): array
{
    $exitCode = Artisan::call('intake:learning-readiness-audit', array_merge($parameters, ['--json' => true]));
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);

    return $payload;
}

function createLearningReadinessIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => learningReadinessParsed('Parsed Candidate', '9876543210'),
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => true,
        'approval_snapshot_json' => learningReadinessSnapshot('Reviewed Candidate', '9876543210'),
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
        'reviewed_at' => now(),
        'intake_locked' => false,
        'quality_summary_json' => ['score' => 0.9],
        'failure_codes_json' => [],
        'field_confidence_json' => [
            'full_name' => ['score' => 0.95, 'present' => true, 'source_path' => 'core.full_name'],
            'date_of_birth' => ['score' => 0.9, 'present' => true, 'source_path' => 'core.date_of_birth'],
            'primary_contact_number' => ['score' => 0.9, 'present' => true, 'source_path' => 'contacts.0.phone_number'],
        ],
        'routing_recommendation_json' => learningReadinessRecommendation(),
        'routing_telemetry_json' => [
            'mode' => 'dry_run',
            'sarvam_attempt_count' => 0,
            'cheap_ocr_attempt_count' => 1,
            'failed_provider_count' => 0,
        ],
    ], $overrides));
}

function learningReadinessRecommendation(array $overrides = []): array
{
    return array_replace_recursive([
        'mode' => 'dry_run',
        'recommended_action' => 'cheap_ocr_only',
        'reason_codes' => ['high_quality_cheap_ocr'],
        'confidence' => 0.9,
        'would_skip_paid_vision' => false,
        'would_call_paid_vision' => false,
        'signals' => [
            'quality_score' => 0.9,
            'has_raw_ocr_text' => true,
            'has_parsed_json' => true,
            'estimated_paid_vision_avoidable' => false,
        ],
    ], $overrides);
}

function learningReadinessSnapshot(string $name, string $phone, string $address = 'Pune'): array
{
    return [
        'core' => [
            'full_name' => $name,
            'date_of_birth' => '1996-04-12',
            'height_cm' => 170,
            'highest_education' => 'MCA',
            'occupation_title' => 'Engineer',
            'religion' => 'Hindu',
            'caste' => 'Maratha',
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

function learningReadinessParsed(string $name, string $phone, string $address = 'Pune'): array
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

