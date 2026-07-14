<?php

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Intake\BulkIntakeBatchService;
use App\Services\Intake\IntakeCreationService;
use App\Services\Intake\IntakeOcrEnsembleGate;
use App\Services\Intake\IntakeOcrEnsemblePhase4Service;
use App\Services\Intake\IntakeSourceContextRecorder;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionMeta;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase4Constants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sleep::fake();
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, true);
    config()->set('ocr.ensemble.phase3.enabled', true);
    config()->set('ocr.ensemble.phase4.enabled', true);
    config()->set('ocr.ensemble.phase4.client.endpoint', 'https://example.test/v1/chat/completions');
    config()->set('ocr.ensemble.phase4.client.api_key', 'test-key');
    config()->set('ocr.ensemble.phase4.client.model', 'sarvam-m');
    config()->set('ocr.ensemble.phase4.client.max_attempts', 1);
    config()->set('ocr.ensemble.phase4.client.retry_base_ms', 0);
});

function phase45Batch(User $user): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $user->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_status' => BulkIntakeBatch::STATUS_PENDING,
    ]);
}

function phase45Item(BulkIntakeBatch $batch, BiodataIntake $intake, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'phase45.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}

function phase45Resolved(string $final, ?float $confidence = 0.9): FieldResolutionFieldRecord
{
    return new FieldResolutionFieldRecord(
        final: $final,
        status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
        source: OcrEnsemblePhase3Constants::FIELD_SOURCE_VALIDATOR,
        winningEngine: OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR,
        confidence: $confidence,
        reason: 'phase45_resolved',
        candidates: [OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => $final],
        normalized: [OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => $final],
        validator: ['passed' => true, 'code' => 'test_match', 'detail' => null],
    );
}

function phase45Missing(): FieldResolutionFieldRecord
{
    return FieldResolutionFieldRecord::missingSkeleton('phase45_missing');
}

/**
 * @param  array<string, FieldResolutionFieldRecord>  $overrides
 */
function phase45Envelope(array $overrides, int $intakeId): FieldResolutionEnvelope
{
    $fields = [];
    foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
        $fields[$fieldKey] = phase45Missing();
    }
    foreach ($overrides as $key => $record) {
        $fields[$key] = $record;
    }

    return new FieldResolutionEnvelope(
        meta: new FieldResolutionMeta(
            schemaVersion: OcrEnsemblePhase3Constants::SCHEMA_VERSION,
            pipelineVersion: OcrEnsemblePhase3Constants::PIPELINE_VERSION,
            resolvedAt: '2026-01-01T00:00:00+00:00',
            intakeId: $intakeId,
            attemptCount: 1,
            enginesPresent: [OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR],
            voteMode: OcrEnsemblePhase3Constants::VOTE_MODE_SINGLE_ENGINE_PASS_THROUGH,
            assemblyVersion: OcrEnsemblePhase3Constants::ASSEMBLY_VERSION,
        ),
        fields: $fields,
    );
}

function phase45OcrBody(): string
{
    return implode("\n", [
        'मुलाचे नाव : Phase45 Candidate',
        'जन्म तारीख :',
        'मोबाईल : 9876543210',
        'धर्म :',
        'शिक्षण : BE Computer',
        'कौटुंबिक माहिती आणि अधिक तपशील येथे आहे जेणेकरून लांबी पुरेशी राहील.',
    ]);
}

/**
 * @param  array<string, FieldResolutionFieldRecord>|null  $fieldOverrides null = no field_resolution_json
 */
function phase45Intake(User $user, ?array $fieldOverrides = [], ?string $rawOcr = null): BiodataIntake
{
    $rawOcr ??= phase45OcrBody();
    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => $rawOcr,
        'last_parse_input_text' => "मुलाचे नाव : Phase45 Candidate\nमोबाईल : 9876543210\nशिक्षण : BE\nकौटुंबिक.",
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    if ($fieldOverrides !== null) {
        $intake->field_resolution_json = phase45Envelope($fieldOverrides, (int) $intake->id)->toArray();
        $intake->save();
    }

    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => $rawOcr,
        'is_primary' => true,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);

    return $intake->fresh();
}

function phase45SuccessHttpBody(array $fields): string
{
    return json_encode([
        'choices' => [[
            'message' => [
                'content' => json_encode(['fields' => $fields], JSON_UNESCAPED_UNICODE),
            ],
        ]],
    ], JSON_UNESCAPED_UNICODE);
}

test('successful sarvam response creates a new append-only ocr attempt', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response(phase45SuccessHttpBody([
            ['field_name' => 'religion', 'value' => 'Hindu', 'confidence' => 0.95],
        ]), 200),
    ]);

    $user = User::factory()->create();
    $intake = phase45Intake($user, [
        'full_name' => phase45Resolved('Phase45 Candidate'),
        'date_of_birth' => phase45Resolved('1992-01-04'),
        'primary_contact_number' => phase45Resolved('9876543210'),
        'religion' => phase45Missing(),
    ]);

    $result = app(IntakeOcrEnsemblePhase4Service::class)->judge($intake);

    $attempts = BiodataIntakeOcrAttempt::query()->where('intake_id', $intake->id)->orderBy('id')->get();
    $sarvam = $attempts->firstWhere('engine', BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION);

    expect($result->wasResolved())->toBeTrue()
        ->and($attempts)->toHaveCount(2)
        ->and($sarvam)->not->toBeNull()
        ->and($sarvam->status)->toBe(BiodataIntakeOcrAttempt::STATUS_SUCCESS)
        ->and($sarvam->is_primary)->toBeFalse()
        ->and($sarvam->source)->toBe('phase4_sarvam_judge')
        ->and($sarvam->raw_text)->toContain('religion : Hindu')
        ->and($sarvam->engine_meta_json['phase'] ?? null)->toBe('phase4_sarvam_judge')
        ->and($sarvam->parser_version)->toBe(OcrEnsemblePhase4Constants::PIPELINE_VERSION);
});

test('existing ocr attempts remain unchanged after sarvam judge attempt is appended', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response(phase45SuccessHttpBody([
            ['field_name' => 'date_of_birth', 'value' => '1992-01-04', 'confidence' => 0.95],
        ]), 200),
    ]);

    $user = User::factory()->create();
    $intake = phase45Intake($user, [
        'full_name' => phase45Resolved('Phase45 Candidate'),
        'date_of_birth' => phase45Missing(),
        'primary_contact_number' => phase45Resolved('9876543210'),
        'religion' => phase45Resolved('Hindu'),
    ]);

    $nativeBefore = BiodataIntakeOcrAttempt::query()
        ->where('intake_id', $intake->id)
        ->where('engine', BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR)
        ->firstOrFail();
    $nativeSnapshot = $nativeBefore->only([
        'id', 'engine', 'raw_text', 'is_primary', 'status', 'source_surface', 'created_by_actor_type',
    ]);

    app(IntakeOcrEnsemblePhase4Service::class)->judge($intake);

    $nativeAfter = BiodataIntakeOcrAttempt::query()->findOrFail($nativeBefore->id);

    expect($nativeAfter->only([
        'id', 'engine', 'raw_text', 'is_primary', 'status', 'source_surface', 'created_by_actor_type',
    ]))->toBe($nativeSnapshot)
        ->and($nativeAfter->is_primary)->toBeTrue();
});

test('retry resumes phase3 when intake exists without field_resolution_json', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response(phase45SuccessHttpBody([
            ['field_name' => 'religion', 'value' => 'Hindu', 'confidence' => 0.95],
            ['field_name' => 'date_of_birth', 'value' => '1992-01-04', 'confidence' => 0.95],
        ]), 200),
    ]);

    $user = User::factory()->create();
    $intake = phase45Intake($user, null);
    expect($intake->field_resolution_json)->toBeNull();

    $item = phase45Item(phase45Batch($user), $intake);

    app(BulkIntakeBatchService::class)->processPendingItem(
        $item,
        $user,
        app(IntakeCreationService::class),
        app(IntakeSourceContextRecorder::class),
        false,
    );

    $fresh = $intake->fresh();
    expect($fresh->field_resolution_json)->toBeArray()
        ->and($fresh->field_resolution_json['fields'] ?? null)->toBeArray()
        ->and($fresh->last_parse_input_text)->not->toBeEmpty();
});

test('retry resumes phase4 after phase3 rebuild on missing envelope', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response(phase45SuccessHttpBody([
            ['field_name' => 'date_of_birth', 'value' => '1992-01-04', 'confidence' => 0.95],
            ['field_name' => 'religion', 'value' => 'Hindu', 'confidence' => 0.9],
        ]), 200),
    ]);

    $user = User::factory()->create();
    $raw = implode("\n", [
        'मुलाचे नाव : Phase45 Resume',
        'मोबाईल : 9876543210',
        'शिक्षण : BE Computer Science Engineering',
        'कौटुंबिक माहिती आणि अधिक तपशील येथे आहे जेणेकरून लांबी पुरेशी राहील.',
    ]);
    $intake = phase45Intake($user, null, $raw);
    $item = phase45Item(phase45Batch($user), $intake);

    app(BulkIntakeBatchService::class)->processPendingItem(
        $item,
        $user,
        app(IntakeCreationService::class),
        app(IntakeSourceContextRecorder::class),
        false,
    );

    $fresh = $intake->fresh();
    $sarvamExists = BiodataIntakeOcrAttempt::query()
        ->where('intake_id', $fresh->id)
        ->where('engine', BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION)
        ->exists();

    expect($fresh->field_resolution_json)->toBeArray()
        ->and($sarvamExists)->toBeTrue()
        ->and($fresh->field_resolution_json['fields']['date_of_birth']['final'] ?? null)->toBe('1992-01-04')
        ->and($fresh->field_resolution_json['fields']['religion']['final'] ?? null)->toBe('Hindu');
});

test('retry does not duplicate work when phase3 already completed', function () {
    Http::fake();

    $user = User::factory()->create();
    $intake = phase45Intake($user, [
        'full_name' => phase45Resolved('Phase45 Candidate'),
        'date_of_birth' => phase45Resolved('1992-01-04'),
        'primary_contact_number' => phase45Resolved('9876543210'),
        'religion' => phase45Resolved('Hindu'),
        'education' => phase45Resolved('BE Computer'),
    ]);
    $beforeEnvelope = $intake->field_resolution_json;
    $beforeParse = $intake->last_parse_input_text;
    $beforeAttemptCount = BiodataIntakeOcrAttempt::query()->where('intake_id', $intake->id)->count();
    $item = phase45Item(phase45Batch($user), $intake);

    app(BulkIntakeBatchService::class)->processPendingItem(
        $item,
        $user,
        app(IntakeCreationService::class),
        app(IntakeSourceContextRecorder::class),
        false,
    );

    expect($intake->fresh()->field_resolution_json)->toBe($beforeEnvelope)
        ->and($intake->fresh()->last_parse_input_text)->toBe($beforeParse)
        ->and(BiodataIntakeOcrAttempt::query()->where('intake_id', $intake->id)->count())->toBe($beforeAttemptCount);

    Http::assertNothingSent();
});

test('phase45 raw_ocr_text remains immutable after sarvam attempt persist', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response(phase45SuccessHttpBody([
            ['field_name' => 'religion', 'value' => 'Hindu', 'confidence' => 0.95],
        ]), 200),
    ]);

    $user = User::factory()->create();
    $intake = phase45Intake($user, [
        'full_name' => phase45Resolved('Phase45 Candidate'),
        'date_of_birth' => phase45Resolved('1992-01-04'),
        'primary_contact_number' => phase45Resolved('9876543210'),
        'religion' => phase45Missing(),
    ]);
    $rawBefore = $intake->raw_ocr_text;

    app(IntakeOcrEnsemblePhase4Service::class)->judge($intake);

    expect($intake->fresh()->raw_ocr_text)->toBe($rawBefore)
        ->and(
            BiodataIntakeOcrAttempt::query()
                ->where('intake_id', $intake->id)
                ->where('engine', BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION)
                ->exists()
        )->toBeTrue();
});

test('http soft-fail does not create sarvam ocr attempt', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response('error', 500),
    ]);

    $user = User::factory()->create();
    $intake = phase45Intake($user, [
        'full_name' => phase45Resolved('Phase45 Candidate'),
        'date_of_birth' => phase45Missing(),
        'primary_contact_number' => phase45Resolved('9876543210'),
        'religion' => phase45Resolved('Hindu'),
    ]);

    $result = app(IntakeOcrEnsemblePhase4Service::class)->judge($intake);

    expect($result->wasSoftFailed())->toBeTrue()
        ->and(
            BiodataIntakeOcrAttempt::query()
                ->where('intake_id', $intake->id)
                ->where('engine', BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION)
                ->exists()
        )->toBeFalse();
});
