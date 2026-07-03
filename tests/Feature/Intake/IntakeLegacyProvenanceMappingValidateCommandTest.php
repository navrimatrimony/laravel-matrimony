<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

test('valid CSV with skip rows passes', function () {
    $intake = createLegacyProvenanceValidateIntake();
    $path = writeLegacyProvenanceValidateCsv([
        legacyProvenanceValidateRow($intake, [
            'reviewer_decision' => 'skip',
        ]),
    ]);

    try {
        $payload = legacyProvenanceValidateJson($path);

        expect($payload['summary']['validation_status'])->toBe('pass')
            ->and($payload['summary']['total_csv_rows'])->toBe(1)
            ->and($payload['summary']['rows_matching_db'])->toBe(1)
            ->and($payload['summary']['skipped_rows'])->toBe(1)
            ->and($payload['summary']['future_apply_candidate_count'])->toBe(0)
            ->and($payload['summary']['recommendation'])->toBe('no_apply')
            ->and($payload['rows'][0]['validation_status'])->toBe('pass');
    } finally {
        File::delete(base_path($path));
    }
});

test('valid CSV with approved manual mapping reports future apply candidate but does not mutate DB', function () {
    $actor = User::factory()->create(['id' => 45678]);
    $intake = createLegacyProvenanceValidateIntake();
    $path = writeLegacyProvenanceValidateCsv([
        legacyProvenanceValidateRow($intake, [
            'manual_actor_type' => 'admin',
            'manual_actor_id' => (string) $actor->id,
            'manual_surface' => 'admin_panel',
            'manual_notes' => 'Admin verified legacy review source from offline records.',
            'reviewer_decision' => 'approve_manual_mapping',
        ]),
    ]);
    $snapshot = $intake->approval_snapshot_json;

    try {
        $payload = legacyProvenanceValidateJson($path);
        $intake->refresh();

        expect($payload['summary']['validation_status'])->toBe('pass')
            ->and($payload['summary']['approved_manual_mappings'])->toBe(1)
            ->and($payload['summary']['future_apply_candidate_count'])->toBe(1)
            ->and($payload['summary']['recommendation'])->toBe('ready_for_manual_apply_review')
            ->and($payload['rows'][0]['manual_actor_id_present'])->toBe('yes')
            ->and($payload['rows'][0]['future_apply_candidate'])->toBeTrue()
            ->and($intake->approval_snapshot_json)->toEqual($snapshot)
            ->and($intake->reviewed_by_user_id)->toBeNull()
            ->and($intake->review_actor_type)->toBeNull()
            ->and($intake->review_surface)->toBeNull();
    } finally {
        File::delete(base_path($path));
    }
});

test('invalid manual actor type fails', function () {
    $actor = User::factory()->create();
    $intake = createLegacyProvenanceValidateIntake();
    $path = writeLegacyProvenanceValidateCsv([
        legacyProvenanceValidateRow($intake, [
            'manual_actor_type' => 'system',
            'manual_actor_id' => (string) $actor->id,
            'manual_surface' => 'admin_panel',
            'manual_notes' => 'Invalid actor should fail.',
            'reviewer_decision' => 'approve_manual_mapping',
        ]),
    ]);

    try {
        $payload = legacyProvenanceValidateJson($path);

        expect($payload['summary']['validation_status'])->toBe('fail')
            ->and($payload['summary']['invalid_rows'])->toBe(1)
            ->and($payload['rows'][0]['risk_codes'])->toContain('invalid_manual_actor_type')
            ->and($payload['rows'][0]['validation_status'])->toBe('fail');
    } finally {
        File::delete(base_path($path));
    }
});

test('missing manual actor id fails for approve manual mapping', function () {
    $intake = createLegacyProvenanceValidateIntake();
    $path = writeLegacyProvenanceValidateCsv([
        legacyProvenanceValidateRow($intake, [
            'manual_actor_type' => 'admin',
            'manual_actor_id' => '',
            'manual_surface' => 'admin_panel',
            'manual_notes' => 'Missing actor id should fail.',
            'reviewer_decision' => 'approve_manual_mapping',
        ]),
    ]);

    try {
        $payload = legacyProvenanceValidateJson($path);

        expect($payload['summary']['validation_status'])->toBe('fail')
            ->and($payload['rows'][0]['manual_actor_id_present'])->toBe('no')
            ->and($payload['rows'][0]['risk_codes'])->toContain('manual_actor_id_missing');
    } finally {
        File::delete(base_path($path));
    }
});

test('confidence none approval fails in strict mode', function () {
    $actor = User::factory()->create();
    $intake = createLegacyProvenanceValidateIntake();
    $path = writeLegacyProvenanceValidateCsv([
        legacyProvenanceValidateRow($intake, [
            'confidence' => 'none',
            'manual_actor_type' => 'admin',
            'manual_actor_id' => (string) $actor->id,
            'manual_surface' => 'admin_panel',
            'manual_notes' => 'Manual note exists but strict mode rejects no evidence.',
            'reviewer_decision' => 'approve_manual_mapping',
        ]),
    ]);

    try {
        $payload = legacyProvenanceValidateJson($path, ['--strict' => true]);

        expect($payload['summary']['validation_status'])->toBe('fail')
            ->and($payload['rows'][0]['csv_confidence'])->toBe('none')
            ->and($payload['rows'][0]['risk_codes'])->toContain('none_confidence_not_approvable_in_strict_mode');
    } finally {
        File::delete(base_path($path));
    }
});

test('stale DB provenance warning is reported', function () {
    $actor = User::factory()->create();
    $intake = createLegacyProvenanceValidateIntake([
        'reviewed_by_user_id' => $actor->id,
        'review_actor_type' => 'admin',
        'review_surface' => 'admin_panel',
    ]);
    $path = writeLegacyProvenanceValidateCsv([
        legacyProvenanceValidateRow($intake, [
            'reviewer_decision' => 'skip',
        ]),
    ]);

    try {
        $payload = legacyProvenanceValidateJson($path);

        expect($payload['summary']['stale_rows'])->toBe(1)
            ->and($payload['rows'][0]['validation_status'])->toBe('warning')
            ->and($payload['rows'][0]['risk_codes'])->toContain('stale_db_provenance_present');
    } finally {
        File::delete(base_path($path));
    }
});

test('json output is valid', function () {
    $intake = createLegacyProvenanceValidateIntake();
    $path = writeLegacyProvenanceValidateCsv([
        legacyProvenanceValidateRow($intake, [
            'reviewer_decision' => 'needs_more_evidence',
        ]),
    ]);

    try {
        $exitCode = Artisan::call('intake:legacy-provenance-mapping-validate', [
            '--file' => $path,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        expect($exitCode)->toBe(0)
            ->and(json_last_error())->toBe(JSON_ERROR_NONE)
            ->and($payload['success'])->toBeTrue()
            ->and($payload['rows'])->toHaveCount(1)
            ->and($payload['allowed_values']['reviewer_decision'])->toContain('approve_manual_mapping');
    } finally {
        File::delete(base_path($path));
    }
});

test('fail on risk exits non zero on risky rows', function () {
    $intake = createLegacyProvenanceValidateIntake();
    $path = writeLegacyProvenanceValidateCsv([
        legacyProvenanceValidateRow($intake, [
            'manual_actor_type' => 'admin',
            'manual_actor_id' => '',
            'manual_surface' => 'admin_panel',
            'reviewer_decision' => 'approve_manual_mapping',
        ]),
    ]);

    try {
        $exitCode = Artisan::call('intake:legacy-provenance-mapping-validate', [
            '--file' => $path,
            '--fail-on-risk' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain('manual_actor_id_missing');
    } finally {
        File::delete(base_path($path));
    }
});

test('raw OCR actor IDs phone name address provider payload and hashes are not printed', function () {
    $actor = User::factory()->create(['id' => 45679]);
    $intake = createLegacyProvenanceValidateIntake([
        'raw_ocr_text' => 'Sensitive raw OCR 9876543210 sk-proj-raw-secret',
        'parsed_json' => legacyProvenanceValidateParsed('Sensitive Candidate', '9876543210', '123 Secret Full Address, Pune'),
        'approval_snapshot_json' => legacyProvenanceValidateSnapshot('Sensitive Candidate', '9876543210', '123 Secret Full Address, Pune'),
        'routing_recommendation_json' => [
            'provider_payload' => 'sk-proj-provider-payload',
            'content_hash' => 'abcdef1234567890abcdef1234567890',
        ],
    ]);
    $path = writeLegacyProvenanceValidateCsv([
        legacyProvenanceValidateRow($intake, [
            'manual_actor_type' => 'admin',
            'manual_actor_id' => (string) $actor->id,
            'manual_surface' => 'admin_panel',
            'manual_notes' => 'Admin verified without exposing private data.',
            'reviewer_decision' => 'approve_manual_mapping',
        ]),
    ]);

    try {
        $exitCode = Artisan::call('intake:legacy-provenance-mapping-validate', ['--file' => $path]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->not->toContain((string) $actor->id)
            ->and($output)->not->toContain('Sensitive raw OCR')
            ->and($output)->not->toContain('9876543210')
            ->and($output)->not->toContain('Sensitive Candidate')
            ->and($output)->not->toContain('123 Secret Full Address')
            ->and($output)->not->toContain('sk-proj-raw-secret')
            ->and($output)->not->toContain('sk-proj-provider-payload')
            ->and($output)->not->toContain('abcdef1234567890abcdef1234567890');
    } finally {
        File::delete(base_path($path));
    }
});

test('command does not mutate reviewed snapshots routing quality parsed raw OCR attempts parse status or profile', function () {
    $member = User::factory()->create();
    $actor = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before Legacy Validate',
    ]);
    $parsed = legacyProvenanceValidateParsed('Parsed Candidate', '9876543210');
    $snapshot = legacyProvenanceValidateSnapshot('Reviewed Candidate', '9876543210');
    $quality = ['score' => 0.91];
    $failureCodes = [BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED];
    $fieldConfidence = [
        'full_name' => ['score' => 0.95, 'present' => true],
    ];
    $routing = [
        'mode' => 'dry_run',
        'recommended_action' => 'manual_review',
        'reason_codes' => ['field_confidence_low'],
    ];
    $intake = createLegacyProvenanceValidateIntake([
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
    $path = writeLegacyProvenanceValidateCsv([
        legacyProvenanceValidateRow($intake, [
            'manual_actor_type' => 'admin',
            'manual_actor_id' => (string) $actor->id,
            'manual_surface' => 'admin_panel',
            'manual_notes' => 'Admin verified legacy review source from offline records.',
            'reviewer_decision' => 'approve_manual_mapping',
        ]),
    ]);

    try {
        $payload = legacyProvenanceValidateJson($path);

        $intake->refresh();
        $attempt->refresh();
        $profile->refresh();

        expect($payload['summary']['future_apply_candidate_count'])->toBe(1)
            ->and($intake->parsed_json)->toEqual($parsed)
            ->and($intake->raw_ocr_text)->toBe('Original OCR text 9876543210')
            ->and($intake->approval_snapshot_json)->toEqual($snapshot)
            ->and($intake->quality_summary_json)->toEqual($quality)
            ->and($intake->failure_codes_json)->toEqual($failureCodes)
            ->and($intake->field_confidence_json)->toEqual($fieldConfidence)
            ->and($intake->routing_recommendation_json)->toEqual($routing)
            ->and($intake->parse_status)->toBe('parsed')
            ->and($intake->reviewed_by_user_id)->toBeNull()
            ->and($intake->review_actor_type)->toBeNull()
            ->and($intake->review_surface)->toBeNull()
            ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe($attemptCountBefore)
            ->and($attempt->raw_text)->toBe('Attempt raw OCR evidence')
            ->and($profile->full_name)->toBe('Profile Before Legacy Validate');
    } finally {
        File::delete(base_path($path));
    }
});

function legacyProvenanceValidateJson(string $path, array $parameters = []): array
{
    $exitCode = Artisan::call('intake:legacy-provenance-mapping-validate', array_merge([
        '--file' => $path,
        '--json' => true,
    ], $parameters));
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);

    return $payload;
}

function createLegacyProvenanceValidateIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => legacyProvenanceValidateParsed('Parsed Candidate', '9876543210'),
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'approved_at' => null,
        'approval_snapshot_json' => legacyProvenanceValidateSnapshot('Reviewed Candidate', '9876543210'),
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

function legacyProvenanceValidateRow(BiodataIntake $intake, array $overrides = []): array
{
    return array_merge([
        'intake_id' => (string) $intake->id,
        'reviewed_snapshot_present' => 'yes',
        'reviewed_at_present' => $intake->reviewed_at !== null ? 'yes' : 'no',
        'current_actor_type' => $intake->review_actor_type ?? 'unknown',
        'current_actor_id_present' => $intake->reviewed_by_user_id !== null ? 'yes' : 'no',
        'current_surface' => $intake->review_surface ?? 'unknown',
        'suggested_actor_type' => 'profile_user',
        'suggested_actor_id_present' => 'yes',
        'suggested_surface' => 'website',
        'evidence_source' => 'uploaded_by_approval_timestamp_inference',
        'confidence' => 'low',
        'can_backfill_safely' => 'no',
        'reason' => 'uploaded_by_or_approval_timestamp_is_not_exact_review_provenance',
        'manual_actor_type' => '',
        'manual_actor_id' => '',
        'manual_surface' => '',
        'manual_notes' => '',
        'reviewer_decision' => 'skip',
    ], $overrides);
}

function writeLegacyProvenanceValidateCsv(array $rows): string
{
    $relative = 'storage/app/intake-legacy-provenance-validate-test-'.uniqid().'.csv';
    $absolute = base_path($relative);
    File::ensureDirectoryExists(dirname($absolute));
    File::put($absolute, legacyProvenanceValidateCsv($rows));

    return $relative;
}

function legacyProvenanceValidateCsv(array $rows): string
{
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, ['# allowed_manual_actor_type_values: admin,profile_user,suchak']);
    fputcsv($handle, ['# allowed_manual_surface_values: admin_panel,mobile_app,website,api']);
    fputcsv($handle, legacyProvenanceValidateColumns());

    foreach ($rows as $row) {
        fputcsv($handle, array_map(
            fn (string $column): string => (string) ($row[$column] ?? ''),
            legacyProvenanceValidateColumns()
        ));
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    return (string) $csv;
}

function legacyProvenanceValidateSnapshot(string $name, string $phone, string $address = 'Pune'): array
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

function legacyProvenanceValidateParsed(string $name, string $phone, string $address = 'Pune'): array
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

function legacyProvenanceValidateColumns(): array
{
    return [
        'intake_id',
        'reviewed_snapshot_present',
        'reviewed_at_present',
        'current_actor_type',
        'current_actor_id_present',
        'current_surface',
        'suggested_actor_type',
        'suggested_actor_id_present',
        'suggested_surface',
        'evidence_source',
        'confidence',
        'can_backfill_safely',
        'reason',
        'manual_actor_type',
        'manual_actor_id',
        'manual_surface',
        'manual_notes',
        'reviewer_decision',
    ];
}
