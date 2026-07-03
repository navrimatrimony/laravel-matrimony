<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('command lists unknown provenance reviewed snapshots', function () {
    $intake = createLegacyProvenanceAuditIntake([
        'reviewed_by_user_id' => null,
        'review_actor_type' => null,
        'review_surface' => null,
        'reviewed_at' => null,
    ]);

    $payload = legacyProvenanceAuditJson();

    expect($payload['summary']['total_reviewed_unknown_provenance'])->toBe(1)
        ->and($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['intake_id'])->toBe($intake->id)
        ->and($payload['rows'][0]['existing_actor_type'])->toBe('unknown')
        ->and($payload['rows'][0]['existing_actor_id_present'])->toBeFalse()
        ->and($payload['rows'][0]['existing_surface'])->toBe('unknown');
});

test('json output is valid', function () {
    createLegacyProvenanceAuditIntake();

    $exitCode = Artisan::call('intake:legacy-provenance-backfill-audit', ['--json' => true]);
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($payload['success'])->toBeTrue()
        ->and($payload['rows'])->toHaveCount(1);
});

test('high confidence when direct reviewed by user id exists', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $intake = createLegacyProvenanceAuditIntake([
        'reviewed_by_user_id' => $admin->id,
        'review_actor_type' => null,
        'review_surface' => null,
        'reviewed_at' => now(),
    ]);

    $payload = legacyProvenanceAuditJson();

    expect($payload['summary']['high_confidence_candidates'])->toBe(1)
        ->and($payload['summary']['safe_backfill_candidate_count'])->toBe(1)
        ->and($payload['summary']['recommendation'])->toBe('dry_run_backfill_possible')
        ->and($payload['rows'][0]['intake_id'])->toBe($intake->id)
        ->and($payload['rows'][0]['possible_actor_type'])->toBe('admin')
        ->and($payload['rows'][0]['possible_actor_id_present'])->toBeTrue()
        ->and($payload['rows'][0]['possible_surface'])->toBe('admin_panel')
        ->and($payload['rows'][0]['evidence_source'])->toBe('reviewed_by_user_id')
        ->and($payload['rows'][0]['confidence'])->toBe('high')
        ->and($payload['rows'][0]['can_backfill_safely'])->toBeTrue();
});

test('no evidence reports none and can backfill safely no', function () {
    createLegacyProvenanceAuditIntake([
        'uploaded_by' => User::factory()->create()->id,
        'approved_by_user' => false,
        'approved_at' => null,
        'reviewed_by_user_id' => null,
        'review_actor_type' => null,
        'review_surface' => null,
        'reviewed_at' => null,
    ]);

    $payload = legacyProvenanceAuditJson();

    expect($payload['summary']['no_evidence_count'])->toBe(1)
        ->and($payload['summary']['safe_backfill_candidate_count'])->toBe(0)
        ->and($payload['summary']['recommendation'])->toBe('no_backfill')
        ->and($payload['rows'][0]['confidence'])->toBe('none')
        ->and($payload['rows'][0]['can_backfill_safely'])->toBeFalse()
        ->and($payload['rows'][0]['reason'])->toBe('no_reliable_provenance_evidence');
});

test('confidence filter works', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $high = createLegacyProvenanceAuditIntake([
        'reviewed_by_user_id' => $admin->id,
        'review_actor_type' => null,
        'review_surface' => null,
    ]);
    createLegacyProvenanceAuditIntake([
        'approved_by_user' => true,
        'approved_at' => now(),
        'reviewed_by_user_id' => null,
        'review_actor_type' => null,
        'review_surface' => null,
    ]);

    $payload = legacyProvenanceAuditJson(['--confidence' => 'high']);

    expect($payload['filters']['confidence'])->toBe('high')
        ->and($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['intake_id'])->toBe($high->id)
        ->and($payload['rows'][0]['confidence'])->toBe('high');
});

test('id filter works', function () {
    createLegacyProvenanceAuditIntake();
    $target = createLegacyProvenanceAuditIntake();

    $payload = legacyProvenanceAuditJson(['--id' => $target->id]);

    expect($payload['filters']['id'])->toBe($target->id)
        ->and($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['intake_id'])->toBe($target->id);
});

test('include unreviewed works but does not mark unreviewed as backfillable', function () {
    createLegacyProvenanceAuditIntake();
    $unreviewed = createLegacyProvenanceAuditIntake([
        'approval_snapshot_json' => null,
        'approved_by_user' => false,
        'approved_at' => null,
        'reviewed_by_user_id' => null,
        'review_actor_type' => null,
        'review_surface' => null,
        'reviewed_at' => null,
    ]);

    $payload = legacyProvenanceAuditJson(['--include-unreviewed' => true]);
    $unreviewedRow = collect($payload['rows'])->firstWhere('intake_id', $unreviewed->id);

    expect($payload['summary']['unreviewed_count'])->toBe(1)
        ->and($unreviewedRow['has_reviewed_snapshot'])->toBeFalse()
        ->and($unreviewedRow['confidence'])->toBe('none')
        ->and($unreviewedRow['can_backfill_safely'])->toBeFalse()
        ->and($unreviewedRow['reason'])->toBe('no_reviewed_snapshot');
});

test('raw ocr phone name address provider payload and hashes are not printed', function () {
    createLegacyProvenanceAuditIntake([
        'raw_ocr_text' => 'Sensitive raw OCR 9876543210 sk-proj-raw-secret',
        'parsed_json' => legacyProvenanceAuditParsed('Sensitive Candidate', '9876543210', '123 Secret Full Address, Pune'),
        'approval_snapshot_json' => legacyProvenanceAuditSnapshot('Sensitive Candidate', '9876543210', '123 Secret Full Address, Pune'),
        'routing_recommendation_json' => [
            'provider_payload' => 'sk-proj-provider-payload',
            'content_hash' => 'abcdef1234567890abcdef1234567890',
        ],
    ]);

    $exitCode = Artisan::call('intake:legacy-provenance-backfill-audit');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->not->toContain('Sensitive raw OCR')
        ->and($output)->not->toContain('9876543210')
        ->and($output)->not->toContain('Sensitive Candidate')
        ->and($output)->not->toContain('123 Secret Full Address')
        ->and($output)->not->toContain('sk-proj-raw-secret')
        ->and($output)->not->toContain('sk-proj-provider-payload')
        ->and($output)->not->toContain('abcdef1234567890abcdef1234567890');
});

test('command does not mutate reviewed snapshots routing quality parsed raw ocr attempts parse status or profile', function () {
    $member = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before Legacy Provenance Audit',
    ]);
    $parsed = legacyProvenanceAuditParsed('Parsed Candidate', '9876543210');
    $snapshot = legacyProvenanceAuditSnapshot('Reviewed Candidate', '9876543210');
    $quality = ['score' => 0.93];
    $failureCodes = [BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED];
    $fieldConfidence = [
        'full_name' => ['score' => 0.95, 'present' => true],
    ];
    $routing = [
        'mode' => 'dry_run',
        'recommended_action' => 'manual_review',
        'reason_codes' => ['field_confidence_low'],
    ];
    $intake = createLegacyProvenanceAuditIntake([
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

    $payload = legacyProvenanceAuditJson(['--include-unreviewed' => true]);

    $intake->refresh();
    $attempt->refresh();
    $profile->refresh();

    expect($payload['summary']['total_rows_scanned'])->toBe(1)
        ->and($intake->parsed_json)->toEqual($parsed)
        ->and($intake->raw_ocr_text)->toBe('Original OCR text 9876543210')
        ->and($intake->approval_snapshot_json)->toEqual($snapshot)
        ->and($intake->quality_summary_json)->toEqual($quality)
        ->and($intake->failure_codes_json)->toEqual($failureCodes)
        ->and($intake->field_confidence_json)->toEqual($fieldConfidence)
        ->and($intake->routing_recommendation_json)->toEqual($routing)
        ->and($intake->parse_status)->toBe('parsed')
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe($attemptCountBefore)
        ->and($attempt->raw_text)->toBe('Attempt raw OCR evidence')
        ->and($profile->full_name)->toBe('Profile Before Legacy Provenance Audit');
});

test('trusted admin audit event can produce high confidence without printing actor id', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $intake = createLegacyProvenanceAuditIntake([
        'reviewed_by_user_id' => null,
        'review_actor_type' => null,
        'review_surface' => null,
    ]);
    DB::table('admin_audit_logs')->insert([
        'admin_id' => $admin->id,
        'action_type' => 'intake_review_snapshot_saved',
        'entity_type' => 'biodata_intake',
        'entity_id' => $intake->id,
        'reason' => 'Review snapshot saved.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = legacyProvenanceAuditJson();

    expect($payload['rows'][0]['evidence_source'])->toBe('admin_audit_logs')
        ->and($payload['rows'][0]['confidence'])->toBe('high')
        ->and($payload['rows'][0]['possible_actor_type'])->toBe('admin')
        ->and(json_encode($payload))->not->toContain('admin_id')
        ->and(json_encode($payload))->not->toContain('reviewed_by_user_id')
        ->and(json_encode($payload))->not->toContain('actor_user_id');
});

function legacyProvenanceAuditJson(array $parameters = []): array
{
    $exitCode = Artisan::call('intake:legacy-provenance-backfill-audit', array_merge($parameters, ['--json' => true]));
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);

    return $payload;
}

function createLegacyProvenanceAuditIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => legacyProvenanceAuditParsed('Parsed Candidate', '9876543210'),
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'approved_at' => null,
        'approval_snapshot_json' => legacyProvenanceAuditSnapshot('Reviewed Candidate', '9876543210'),
        'reviewed_by_user_id' => null,
        'review_actor_type' => null,
        'review_surface' => null,
        'reviewed_at' => null,
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

function legacyProvenanceAuditSnapshot(string $name, string $phone, string $address = 'Pune'): array
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

function legacyProvenanceAuditParsed(string $name, string $phone, string $address = 'Pune'): array
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
