<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('legacy unknown reviewed snapshot is excluded and reported as blocked', function () {
    createLearningCandidateRulesAuditIntake([
        'reviewed_by_user_id' => null,
        'review_actor_type' => null,
        'review_surface' => null,
        'reviewed_at' => null,
    ]);

    $payload = learningCandidateRulesAuditJson(['--field' => 'full_name']);

    expect($payload['summary']['total_reviewed_snapshots_scanned'])->toBe(1)
        ->and($payload['summary']['eligible_learning_source_rows'])->toBe(0)
        ->and($payload['summary']['blocked_rows'])->toBe(1)
        ->and($payload['summary']['blocker_counts']['legacy_unknown_provenance'])->toBe(1)
        ->and($payload['summary']['field_candidate_summary']['full_name']['sample_count'])->toBe(0)
        ->and($payload['summary']['safety_status'])->toBe('blocked_no_learning_candidates')
        ->and($payload['rows'][0]['blocker_codes'])->toContain('legacy_unknown_provenance')
        ->and($payload['rows'][0]['eligible_fields'])->toBe([]);
});

test('complete admin reviewed snapshot contributes to field candidate sample', function () {
    createLearningCandidateRulesAuditIntake([
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
    ]);

    $payload = learningCandidateRulesAuditJson(['--field' => 'full_name', '--min-samples' => 1]);
    $field = $payload['summary']['field_candidate_summary']['full_name'];

    expect($payload['summary']['eligible_learning_source_rows'])->toBe(1)
        ->and($field['sample_count'])->toBe(1)
        ->and($field['actor_mix']['admin'])->toBe(1)
        ->and($field['surface_mix']['admin_panel'])->toBe(1)
        ->and($field['candidate_status'])->toBe('dry_run_candidate_only')
        ->and($field['recommendation'])->toBe('future_candidate_requires_admin_approval');
});

test('complete profile user reviewed snapshot contributes to field candidate sample', function () {
    createLearningCandidateRulesAuditIntake([
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_MOBILE_APP,
    ]);

    $payload = learningCandidateRulesAuditJson(['--field' => 'date_of_birth', '--min-samples' => 1]);
    $field = $payload['summary']['field_candidate_summary']['date_of_birth'];

    expect($payload['summary']['eligible_learning_source_rows'])->toBe(1)
        ->and($field['sample_count'])->toBe(1)
        ->and($field['actor_mix']['profile_user'])->toBe(1)
        ->and($field['surface_mix']['mobile_app'])->toBe(1)
        ->and($payload['rows'][0]['eligible_fields'])->toContain('date_of_birth');
});

test('complete suchak reviewed snapshot contributes to field candidate sample', function () {
    createLearningCandidateRulesAuditIntake([
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SUCHAK,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
    ]);

    $payload = learningCandidateRulesAuditJson(['--field' => 'occupation', '--min-samples' => 1]);
    $field = $payload['summary']['field_candidate_summary']['occupation'];

    expect($payload['summary']['eligible_learning_source_rows'])->toBe(1)
        ->and($field['sample_count'])->toBe(1)
        ->and($field['actor_mix']['suchak'])->toBe(1)
        ->and($field['surface_mix']['website'])->toBe(1)
        ->and($payload['rows'][0]['eligible_fields'])->toContain('occupation');
});

test('missing reviewer id blocks row', function () {
    createLearningCandidateRulesAuditIntake([
        'reviewed_by_user_id' => null,
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
    ]);

    $payload = learningCandidateRulesAuditJson(['--field' => 'full_name']);

    expect($payload['summary']['eligible_learning_source_rows'])->toBe(0)
        ->and($payload['summary']['blocker_counts']['missing_reviewer_id'])->toBe(1)
        ->and($payload['rows'][0]['blocker_codes'])->toContain('missing_reviewer_id');
});

test('missing surface blocks row', function () {
    createLearningCandidateRulesAuditIntake([
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        'review_surface' => null,
    ]);

    $payload = learningCandidateRulesAuditJson(['--field' => 'full_name']);

    expect($payload['summary']['eligible_learning_source_rows'])->toBe(0)
        ->and($payload['summary']['blocker_counts']['missing_surface'])->toBe(1)
        ->and($payload['rows'][0]['blocker_codes'])->toContain('missing_surface');
});

test('missing reviewed at blocks row', function () {
    createLearningCandidateRulesAuditIntake([
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
        'reviewed_at' => null,
    ]);

    $payload = learningCandidateRulesAuditJson(['--field' => 'full_name']);

    expect($payload['summary']['eligible_learning_source_rows'])->toBe(0)
        ->and($payload['summary']['blocker_counts']['missing_reviewed_at'])->toBe(1)
        ->and($payload['rows'][0]['blocker_codes'])->toContain('missing_reviewed_at');
});

test('blank placeholder field values do not count as field samples', function () {
    createLearningCandidateRulesAuditIntake([
        'approval_snapshot_json' => [
            'core' => [
                'full_name' => 'Unknown',
            ],
        ],
    ]);

    $payload = learningCandidateRulesAuditJson(['--field' => 'full_name', '--min-samples' => 1]);
    $field = $payload['summary']['field_candidate_summary']['full_name'];

    expect($payload['summary']['eligible_learning_source_rows'])->toBe(0)
        ->and($field['sample_count'])->toBe(0)
        ->and($field['candidate_status'])->toBe('blocked_no_authorized_samples')
        ->and($payload['rows'][0]['fields_present'])->toBe([])
        ->and($payload['rows'][0]['blocker_codes'])->toContain('no_eligible_field_samples');
});

test('provider candidate and conflict risk rows are excluded', function () {
    createLearningCandidateRulesAuditIntake([
        'routing_recommendation_json' => learningCandidateRulesAuditRecommendation([
            'recommended_action' => 'call_sarvam',
            'reason_codes' => ['critical_field_raw_evidence_absent'],
            'signals' => [
                'critical_field_parser_proposal_outcome' => 'provider_candidate',
            ],
        ]),
    ]);
    createLearningCandidateRulesAuditIntake([
        'routing_recommendation_json' => learningCandidateRulesAuditRecommendation([
            'recommended_action' => 'manual_review',
            'reason_codes' => ['duplicate_detected'],
            'signals' => [
                'duplicate_detected' => true,
            ],
        ]),
    ]);

    $payload = learningCandidateRulesAuditJson(['--field' => 'full_name', '--min-samples' => 1]);
    $field = $payload['summary']['field_candidate_summary']['full_name'];

    expect($payload['summary']['eligible_learning_source_rows'])->toBe(0)
        ->and($field['sample_count'])->toBe(0)
        ->and($field['provider_candidate_count'])->toBe(1)
        ->and($field['conflict_risk_count'])->toBe(1)
        ->and($payload['summary']['blocker_counts']['provider_candidate'])->toBe(1)
        ->and($payload['summary']['blocker_counts']['duplicate_manual_conflict_risk'])->toBe(1);
});

test('field filter works', function () {
    createLearningCandidateRulesAuditIntake([
        'approval_snapshot_json' => [
            'core' => [
                'highest_education' => 'MCA',
            ],
        ],
    ]);

    $payload = learningCandidateRulesAuditJson(['--field' => 'education', '--min-samples' => 1]);

    expect($payload['filters']['field'])->toBe('education')
        ->and(array_keys($payload['summary']['field_candidate_summary']))->toBe(['education'])
        ->and($payload['summary']['field_candidate_summary']['education']['sample_count'])->toBe(1)
        ->and($payload['rows'][0]['fields_present'])->toBe(['education']);
});

test('actor filter works', function () {
    createLearningCandidateRulesAuditIntake([
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
    ]);
    $suchak = createLearningCandidateRulesAuditIntake([
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SUCHAK,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
    ]);

    $payload = learningCandidateRulesAuditJson(['--actor' => 'suchak', '--field' => 'full_name']);

    expect($payload['filters']['actor'])->toBe('suchak')
        ->and($payload['summary']['total_reviewed_snapshots_scanned'])->toBe(1)
        ->and($payload['summary']['actor_counts']['suchak'])->toBe(1)
        ->and($payload['rows'][0]['intake_id'])->toBe($suchak->id);
});

test('min samples changes candidate status', function () {
    createLearningCandidateRulesAuditIntake();
    createLearningCandidateRulesAuditIntake();

    $blocked = learningCandidateRulesAuditJson(['--field' => 'full_name', '--min-samples' => 3]);
    $candidate = learningCandidateRulesAuditJson(['--field' => 'full_name', '--min-samples' => 2]);

    expect($blocked['summary']['field_candidate_summary']['full_name']['candidate_status'])->toBe('blocked_min_samples_not_met')
        ->and($blocked['summary']['field_candidate_summary']['full_name']['min_samples_met'])->toBeFalse()
        ->and($candidate['summary']['field_candidate_summary']['full_name']['candidate_status'])->toBe('dry_run_candidate_only')
        ->and($candidate['summary']['field_candidate_summary']['full_name']['min_samples_met'])->toBeTrue();
});

test('json output is valid', function () {
    createLearningCandidateRulesAuditIntake();

    $payload = learningCandidateRulesAuditJson();

    expect($payload['success'])->toBeTrue()
        ->and($payload['summary'])->toHaveKeys([
            'total_reviewed_snapshots_scanned',
            'eligible_learning_source_rows',
            'blocked_rows',
            'field_candidate_summary',
            'actor_counts',
            'surface_counts',
            'blocker_counts',
            'recommendation',
            'safety_status',
        ]);
});

test('command does not mutate intakes snapshots parsed raw ocr attempts profile routing quality or field confidence', function () {
    $member = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before Candidate Rules Audit',
    ]);
    $parsed = learningCandidateRulesAuditParsed('Parsed Candidate', '9876543210');
    $snapshot = learningCandidateRulesAuditSnapshot('Reviewed Candidate', '9876543210');
    $quality = ['score' => 0.93];
    $failureCodes = [BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED];
    $fieldConfidence = [
        'full_name' => ['score' => 0.42, 'present' => true, 'source_path' => 'core.full_name'],
    ];
    $routing = learningCandidateRulesAuditRecommendation([
        'recommended_action' => 'manual_review',
        'reason_codes' => ['field_confidence_low'],
    ]);
    $intake = createLearningCandidateRulesAuditIntake([
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
        'raw_text' => 'Attempt raw OCR evidence',
        'text_hash' => 'abcdef1234567890abcdef1234567890',
        'created_by_user_id' => $member->id,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_MOBILE_APP,
    ]);
    $attemptCountBefore = BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count();

    $payload = learningCandidateRulesAuditJson(['--field' => 'full_name']);

    $intake->refresh();
    $attempt->refresh();
    $profile->refresh();

    expect($payload['summary']['total_reviewed_snapshots_scanned'])->toBe(1)
        ->and($intake->raw_ocr_text)->toBe('Original OCR text 9876543210')
        ->and($intake->parsed_json)->toEqual($parsed)
        ->and($intake->parse_status)->toBe('parsed')
        ->and($intake->approval_snapshot_json)->toEqual($snapshot)
        ->and($intake->quality_summary_json)->toEqual($quality)
        ->and($intake->failure_codes_json)->toEqual($failureCodes)
        ->and($intake->field_confidence_json)->toEqual($fieldConfidence)
        ->and($intake->routing_recommendation_json)->toEqual($routing)
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe($attemptCountBefore)
        ->and($attempt->raw_text)->toBe('Attempt raw OCR evidence')
        ->and($profile->full_name)->toBe('Profile Before Candidate Rules Audit');
});

function learningCandidateRulesAuditJson(array $parameters = []): array
{
    $exitCode = Artisan::call('intake:learning-candidate-rules-audit', array_merge($parameters, ['--json' => true]));
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);

    return $payload;
}

function createLearningCandidateRulesAuditIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => learningCandidateRulesAuditParsed('Parsed Candidate', '9876543210'),
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => true,
        'approved_at' => now(),
        'approval_status' => 'approved',
        'approval_snapshot_json' => learningCandidateRulesAuditSnapshot('Reviewed Candidate', '9876543210'),
        'reviewed_by_user_id' => $user->id,
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
        'reviewed_at' => now(),
        'intake_locked' => false,
        'quality_summary_json' => ['score' => 0.9],
        'failure_codes_json' => [],
        'field_confidence_json' => [
            'full_name' => ['score' => 0.95, 'present' => true, 'source_path' => 'core.full_name'],
            'date_of_birth' => ['score' => 0.9, 'present' => true, 'source_path' => 'core.date_of_birth'],
            'occupation' => ['score' => 0.9, 'present' => true, 'source_path' => 'core.occupation_title'],
        ],
        'routing_recommendation_json' => learningCandidateRulesAuditRecommendation(),
    ], $overrides));
}

function learningCandidateRulesAuditRecommendation(array $overrides = []): array
{
    return array_replace_recursive([
        'mode' => 'dry_run',
        'recommended_action' => 'cheap_ocr_only',
        'reason_codes' => ['high_quality_cheap_ocr'],
        'would_skip_paid_vision' => false,
        'would_call_paid_vision' => false,
        'signals' => [
            'quality_score' => 0.9,
            'has_raw_ocr_text' => true,
            'has_parsed_json' => true,
        ],
    ], $overrides);
}

function learningCandidateRulesAuditSnapshot(string $name, string $phone, string $address = 'Pune'): array
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

function learningCandidateRulesAuditParsed(string $name, string $phone, string $address = 'Pune'): array
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
