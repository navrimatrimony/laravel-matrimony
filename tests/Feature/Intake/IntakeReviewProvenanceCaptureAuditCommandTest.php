<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('command reports complete admin reviewed snapshot as complete authorized human provenance', function () {
    $intake = createReviewProvenanceCaptureAuditIntake([
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
    ]);

    $payload = reviewProvenanceCaptureAuditJson();

    expect($payload['summary']['total_reviewed_snapshots_scanned'])->toBe(1)
        ->and($payload['summary']['complete_authorized_human_provenance_count'])->toBe(1)
        ->and($payload['summary']['safety_status'])->toBe('pass_when_future_reviews_complete')
        ->and($payload['rows'][0]['intake_id'])->toBe($intake->id)
        ->and($payload['rows'][0]['review_actor_type'])->toBe('admin')
        ->and($payload['rows'][0]['review_surface'])->toBe('admin_panel')
        ->and($payload['rows'][0]['reviewed_by_user_id_present'])->toBeTrue()
        ->and($payload['rows'][0]['provenance_status'])->toBe('complete_authorized_human_provenance')
        ->and($payload['rows'][0]['blocker_codes'])->toBe([]);
});

test('command reports complete profile user mobile reviewed snapshot as complete authorized human provenance', function () {
    createReviewProvenanceCaptureAuditIntake([
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_MOBILE_APP,
    ]);

    $payload = reviewProvenanceCaptureAuditJson();

    expect($payload['summary']['complete_authorized_human_provenance_count'])->toBe(1)
        ->and($payload['summary']['actor_counts']['profile_user'])->toBe(1)
        ->and($payload['summary']['surface_counts']['mobile_app'])->toBe(1)
        ->and($payload['rows'][0]['review_actor_type'])->toBe('profile_user')
        ->and($payload['rows'][0]['review_surface'])->toBe('mobile_app')
        ->and($payload['rows'][0]['provenance_status'])->toBe('complete_authorized_human_provenance');
});

test('command reports complete suchak website reviewed snapshot as complete authorized human provenance', function () {
    createReviewProvenanceCaptureAuditIntake([
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SUCHAK,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
    ]);

    $payload = reviewProvenanceCaptureAuditJson();

    expect($payload['summary']['complete_authorized_human_provenance_count'])->toBe(1)
        ->and($payload['summary']['actor_counts']['suchak'])->toBe(1)
        ->and($payload['summary']['surface_counts']['website'])->toBe(1)
        ->and($payload['rows'][0]['review_actor_type'])->toBe('suchak')
        ->and($payload['rows'][0]['review_surface'])->toBe('website')
        ->and($payload['rows'][0]['provenance_status'])->toBe('complete_authorized_human_provenance');
});

test('command reports legacy snapshot without actor surface or reviewer as legacy unknown provenance', function () {
    createReviewProvenanceCaptureAuditIntake([
        'reviewed_by_user_id' => null,
        'review_actor_type' => null,
        'review_surface' => null,
        'reviewed_at' => null,
    ]);

    $payload = reviewProvenanceCaptureAuditJson();

    expect($payload['summary']['legacy_unknown_provenance_count'])->toBe(1)
        ->and($payload['summary']['missing_reviewer_id_count'])->toBe(1)
        ->and($payload['summary']['missing_surface_count'])->toBe(1)
        ->and($payload['summary']['missing_reviewed_at_count'])->toBe(1)
        ->and($payload['summary']['system_or_unknown_actor_count'])->toBe(1)
        ->and($payload['summary']['recommendation'])->toBe('legacy_rows_need_manual_mapping_csv; do_not_backfill_automatically')
        ->and($payload['summary']['safety_status'])->toBe('not_ready_legacy_or_incomplete_provenance_present')
        ->and($payload['rows'][0]['review_actor_type'])->toBe('unknown')
        ->and($payload['rows'][0]['review_surface'])->toBe('unknown')
        ->and($payload['rows'][0]['reviewed_by_user_id_present'])->toBeFalse()
        ->and($payload['rows'][0]['provenance_status'])->toBe('legacy_unknown_provenance')
        ->and($payload['rows'][0]['blocker_codes'])->toContain('missing_reviewer_id')
        ->and($payload['rows'][0]['blocker_codes'])->toContain('missing_surface')
        ->and($payload['rows'][0]['blocker_codes'])->toContain('missing_reviewed_at')
        ->and($payload['rows'][0]['blocker_codes'])->toContain('system_or_unknown_actor');
});

test('command reports future looking incomplete snapshot as incomplete future review provenance', function () {
    createReviewProvenanceCaptureAuditIntake([
        'reviewed_by_user_id' => null,
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        'review_surface' => null,
        'reviewed_at' => now(),
    ]);

    $payload = reviewProvenanceCaptureAuditJson();

    expect($payload['summary']['incomplete_future_review_provenance_count'])->toBe(1)
        ->and($payload['summary']['missing_reviewer_id_count'])->toBe(1)
        ->and($payload['summary']['missing_surface_count'])->toBe(1)
        ->and($payload['summary']['system_or_unknown_actor_count'])->toBe(0)
        ->and($payload['summary']['recommendation'])->toBe('fix_review_surface_or_actor_capture_before_learning')
        ->and($payload['summary']['safety_status'])->toBe('not_ready_legacy_or_incomplete_provenance_present')
        ->and($payload['rows'][0]['review_actor_type'])->toBe('admin')
        ->and($payload['rows'][0]['review_surface'])->toBe('unknown')
        ->and($payload['rows'][0]['provenance_status'])->toBe('incomplete_future_review_provenance')
        ->and($payload['rows'][0]['blocker_codes'])->toContain('missing_reviewer_id')
        ->and($payload['rows'][0]['blocker_codes'])->toContain('missing_surface');
});

test('actor filter works', function () {
    createReviewProvenanceCaptureAuditIntake([
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
    ]);
    $suchak = createReviewProvenanceCaptureAuditIntake([
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SUCHAK,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
    ]);

    $payload = reviewProvenanceCaptureAuditJson(['--actor' => 'suchak']);

    expect($payload['filters']['actor'])->toBe('suchak')
        ->and($payload['summary']['total_reviewed_snapshots_scanned'])->toBe(1)
        ->and($payload['summary']['actor_counts']['suchak'])->toBe(1)
        ->and($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['intake_id'])->toBe($suchak->id)
        ->and($payload['rows'][0]['review_actor_type'])->toBe('suchak');
});

test('json output has success true and expected summary keys', function () {
    createReviewProvenanceCaptureAuditIntake();

    $payload = reviewProvenanceCaptureAuditJson();

    expect($payload['success'])->toBeTrue()
        ->and($payload['summary'])->toHaveKeys([
            'total_reviewed_snapshots_scanned',
            'complete_authorized_human_provenance_count',
            'legacy_unknown_provenance_count',
            'incomplete_future_review_provenance_count',
            'missing_reviewer_id_count',
            'missing_surface_count',
            'missing_reviewed_at_count',
            'system_or_unknown_actor_count',
            'actor_counts',
            'surface_counts',
            'recommendation',
            'safety_status',
        ]);
});

test('command does not mutate parsed raw approval snapshot profile fields or ocr attempts', function () {
    $member = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before Review Provenance Audit',
    ]);
    $parsed = reviewProvenanceCaptureAuditParsed('Parsed Candidate', '9876543210');
    $snapshot = reviewProvenanceCaptureAuditSnapshot('Reviewed Candidate', '9876543210');
    $quality = ['score' => 0.91];
    $failureCodes = [BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED];
    $fieldConfidence = [
        'full_name' => ['score' => 0.94, 'present' => true],
    ];
    $routing = [
        'mode' => 'dry_run',
        'recommended_action' => 'manual_review',
        'reason_codes' => ['manual_review'],
    ];
    $intake = createReviewProvenanceCaptureAuditIntake([
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
        'raw_text' => 'OCR attempt text remains unchanged',
        'text_hash' => 'abcdef1234567890abcdef1234567890',
        'created_by_user_id' => $member->id,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_MOBILE_APP,
    ]);
    $attemptCountBefore = BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count();

    $payload = reviewProvenanceCaptureAuditJson();

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
        ->and($attempt->raw_text)->toBe('OCR attempt text remains unchanged')
        ->and($profile->full_name)->toBe('Profile Before Review Provenance Audit');
});

function reviewProvenanceCaptureAuditJson(array $parameters = []): array
{
    $exitCode = Artisan::call('intake:review-provenance-capture-audit', array_merge($parameters, ['--json' => true]));
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);

    return $payload;
}

function createReviewProvenanceCaptureAuditIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => reviewProvenanceCaptureAuditParsed('Parsed Candidate', '9876543210'),
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => true,
        'approved_at' => now(),
        'approval_status' => 'approved',
        'approval_snapshot_json' => reviewProvenanceCaptureAuditSnapshot('Reviewed Candidate', '9876543210'),
        'reviewed_by_user_id' => $user->id,
        'review_actor_type' => BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        'review_surface' => BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
        'reviewed_at' => now(),
        'intake_locked' => false,
        'quality_summary_json' => ['score' => 0.9],
        'failure_codes_json' => [],
        'field_confidence_json' => [
            'full_name' => ['score' => 0.95, 'present' => true],
        ],
        'routing_recommendation_json' => [
            'mode' => 'dry_run',
            'recommended_action' => 'manual_review',
            'reason_codes' => ['manual_review'],
        ],
    ], $overrides));
}

function reviewProvenanceCaptureAuditSnapshot(string $name, string $phone, string $address = 'Pune'): array
{
    return [
        'core' => [
            'full_name' => $name,
            'date_of_birth' => '1996-04-12',
            'current_address' => $address,
        ],
        'contacts' => [
            [
                'phone_number' => $phone,
                'is_primary' => true,
            ],
        ],
    ];
}

function reviewProvenanceCaptureAuditParsed(string $name, string $phone, string $address = 'Pune'): array
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
    ];
}
