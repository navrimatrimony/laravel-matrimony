<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

test('command outputs manual mapping columns', function () {
    createLegacyProvenanceTemplateIntake();

    $exitCode = Artisan::call('intake:legacy-provenance-mapping-template');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('manual_actor_type')
        ->and($output)->toContain('manual_actor_id')
        ->and($output)->toContain('manual_surface')
        ->and($output)->toContain('manual_notes')
        ->and($output)->toContain('reviewer_decision')
        ->and($output)->toContain('Allowed manual_actor_type values')
        ->and($output)->toContain('admin, profile_user, suchak')
        ->and($output)->toContain('admin_panel, mobile_app, website, api');
});

test('json output is valid', function () {
    createLegacyProvenanceTemplateIntake();

    $payload = legacyProvenanceTemplateJson();

    expect($payload['success'])->toBeTrue()
        ->and($payload['summary']['total_rows'])->toBe(1)
        ->and($payload['columns'])->toContain('manual_actor_type')
        ->and($payload['manual_mapping_allowed_values']['manual_actor_type'])->toBe(['admin', 'profile_user', 'suchak'])
        ->and($payload['rows'][0]['manual_actor_type'])->toBe('')
        ->and($payload['rows'][0]['manual_actor_id'])->toBe('')
        ->and($payload['rows'][0]['manual_surface'])->toBe('');
});

test('csv output is valid', function () {
    createLegacyProvenanceTemplateIntake();

    $exitCode = Artisan::call('intake:legacy-provenance-mapping-template', ['--csv' => true]);
    $output = trim(Artisan::output());
    $lines = preg_split('/\r\n|\r|\n/', $output);

    expect($exitCode)->toBe(0)
        ->and($lines[0])->toContain('allowed_manual_actor_type_values')
        ->and($lines[1])->toContain('allowed_manual_surface_values');

    $header = str_getcsv($lines[2]);
    $row = str_getcsv($lines[3]);

    expect($header)->toBe(legacyProvenanceTemplateColumns())
        ->and($row)->toHaveCount(count($header))
        ->and($row[array_search('manual_actor_type', $header, true)])->toBe('')
        ->and($row[array_search('manual_actor_id', $header, true)])->toBe('')
        ->and($row[array_search('manual_surface', $header, true)])->toBe('');
});

test('id filter works', function () {
    createLegacyProvenanceTemplateIntake();
    $target = createLegacyProvenanceTemplateIntake();

    $payload = legacyProvenanceTemplateJson(['--id' => $target->id]);

    expect($payload['filters']['id'])->toBe($target->id)
        ->and($payload['summary']['total_rows'])->toBe(1)
        ->and($payload['rows'][0]['intake_id'])->toBe($target->id);
});

test('confidence filter works', function () {
    $medium = createLegacyProvenanceTemplateIntake([
        'approval_snapshot_json' => legacyProvenanceTemplateSnapshot('Medium Candidate', '9876543210', 'Pune', [
            'review_actor_type' => 'suchak',
            'review_surface' => 'website',
        ]),
    ]);
    createLegacyProvenanceTemplateIntake([
        'approved_by_user' => true,
        'approved_at' => now(),
    ]);
    createLegacyProvenanceTemplateIntake([
        'approved_by_user' => false,
        'approved_at' => null,
    ]);

    $payload = legacyProvenanceTemplateJson(['--confidence' => 'medium']);

    expect($payload['filters']['confidence'])->toBe('medium')
        ->and($payload['summary']['total_rows'])->toBe(1)
        ->and($payload['rows'][0]['intake_id'])->toBe($medium->id)
        ->and($payload['rows'][0]['confidence'])->toBe('medium');
});

test('output file is written under storage app when output is provided', function () {
    createLegacyProvenanceTemplateIntake();
    $relativePath = 'storage/app/intake-legacy-provenance-template-test-'.uniqid().'.csv';
    $absolutePath = base_path($relativePath);
    File::delete($absolutePath);

    $exitCode = Artisan::call('intake:legacy-provenance-mapping-template', [
        '--output' => $relativePath,
    ]);
    $output = Artisan::output();
    $content = File::get($absolutePath);

    try {
        expect($exitCode)->toBe(0)
            ->and($output)->toContain('CSV export written')
            ->and(str_replace('\\', '/', $absolutePath))->toStartWith(str_replace('\\', '/', storage_path('app')))
            ->and(File::exists($absolutePath))->toBeTrue()
            ->and($content)->toContain('manual_actor_type')
            ->and($content)->toContain('reviewer_decision');
    } finally {
        File::delete($absolutePath);
    }
});

test('raw ocr phone name address provider payload and hashes are not printed', function () {
    createLegacyProvenanceTemplateIntake([
        'raw_ocr_text' => 'Sensitive raw OCR 9876543210 sk-proj-raw-secret',
        'parsed_json' => legacyProvenanceTemplateParsed('Sensitive Candidate', '9876543210', '123 Secret Full Address, Pune'),
        'approval_snapshot_json' => legacyProvenanceTemplateSnapshot('Sensitive Candidate', '9876543210', '123 Secret Full Address, Pune'),
        'routing_recommendation_json' => [
            'provider_payload' => 'sk-proj-provider-payload',
            'content_hash' => 'abcdef1234567890abcdef1234567890',
        ],
    ]);

    $exitCode = Artisan::call('intake:legacy-provenance-mapping-template', ['--csv' => true]);
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
        'full_name' => 'Profile Before Legacy Mapping Template',
    ]);
    $parsed = legacyProvenanceTemplateParsed('Parsed Candidate', '9876543210');
    $snapshot = legacyProvenanceTemplateSnapshot('Reviewed Candidate', '9876543210');
    $quality = ['score' => 0.92];
    $failureCodes = [BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED];
    $fieldConfidence = [
        'full_name' => ['score' => 0.95, 'present' => true],
    ];
    $routing = [
        'mode' => 'dry_run',
        'recommended_action' => 'manual_review',
        'reason_codes' => ['field_confidence_low'],
    ];
    $intake = createLegacyProvenanceTemplateIntake([
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

    $payload = legacyProvenanceTemplateJson();

    $intake->refresh();
    $attempt->refresh();
    $profile->refresh();

    expect($payload['summary']['total_rows'])->toBe(1)
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
        ->and($profile->full_name)->toBe('Profile Before Legacy Mapping Template');
});

function legacyProvenanceTemplateJson(array $parameters = []): array
{
    $exitCode = Artisan::call('intake:legacy-provenance-mapping-template', array_merge($parameters, ['--json' => true]));
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0)
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);

    return $payload;
}

function createLegacyProvenanceTemplateIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'Original OCR text',
        'parsed_json' => legacyProvenanceTemplateParsed('Parsed Candidate', '9876543210'),
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'approved_at' => null,
        'approval_snapshot_json' => legacyProvenanceTemplateSnapshot('Reviewed Candidate', '9876543210'),
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

function legacyProvenanceTemplateSnapshot(
    string $name,
    string $phone,
    string $address = 'Pune',
    array $metadata = []
): array {
    $snapshot = [
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

    if ($metadata !== []) {
        $snapshot['metadata'] = $metadata;
    }

    return $snapshot;
}

function legacyProvenanceTemplateParsed(string $name, string $phone, string $address = 'Pune'): array
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

function legacyProvenanceTemplateColumns(): array
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
