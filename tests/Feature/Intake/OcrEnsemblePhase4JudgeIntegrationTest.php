<?php

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Intake\BulkIntakeBatchService;
use App\Services\Intake\IntakeOcrEnsembleGate;
use App\Services\Intake\IntakeOcrEnsemblePhase4Service;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionMeta;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase4Constants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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
    config()->set('ocr.ensemble.phase4.client.max_attempts', 3);
    config()->set('ocr.ensemble.phase4.client.retry_base_ms', 0);
});

function phase4fBatch(User $user): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $user->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_status' => BulkIntakeBatch::STATUS_PENDING,
    ]);
}

function phase4fItem(BulkIntakeBatch $batch, BiodataIntake $intake, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'phase4-integration.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}

function phase4fResolved(string $final, ?float $confidence = 0.9): FieldResolutionFieldRecord
{
    return new FieldResolutionFieldRecord(
        final: $final,
        status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
        source: OcrEnsemblePhase3Constants::FIELD_SOURCE_VALIDATOR,
        winningEngine: OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR,
        confidence: $confidence,
        reason: 'phase4f_resolved',
        candidates: [OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => $final],
        normalized: [OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => $final],
        validator: [
            'passed' => true,
            'code' => 'test_match',
            'detail' => null,
        ],
    );
}

function phase4fMissing(): FieldResolutionFieldRecord
{
    return FieldResolutionFieldRecord::missingSkeleton('phase4f_missing');
}

/**
 * @param  array<string, FieldResolutionFieldRecord>  $overrides
 */
function phase4fEnvelope(array $overrides, int $intakeId): FieldResolutionEnvelope
{
    $fields = [];
    foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
        $fields[$fieldKey] = phase4fMissing();
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

function phase4fOcrBody(): string
{
    return implode("\n", [
        'मुलाचे नाव : Integration Candidate',
        'जन्म तारीख :',
        'मोबाईल : 9876543210',
        'धर्म :',
        'शिक्षण : BE Computer',
        'कौटुंबिक माहिती आणि अधिक तपशील येथे आहे जेणेकरून लांबी पुरेशी राहील.',
    ]);
}

/**
 * @param  array<string, FieldResolutionFieldRecord>  $fieldOverrides
 */
function phase4fIntake(User $user, array $fieldOverrides, ?string $rawOcr = null): BiodataIntake
{
    $rawOcr ??= phase4fOcrBody();
    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => $rawOcr,
        'last_parse_input_text' => "मुलाचे नाव : Integration Candidate\nमोबाईल : 9876543210\nशिक्षण : BE Computer\nकौटुंबिक माहिती.",
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    $intake->field_resolution_json = phase4fEnvelope($fieldOverrides, (int) $intake->id)->toArray();
    $intake->save();

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

function phase4fSuccessHttpBody(array $fields): string
{
    return json_encode([
        'choices' => [
            [
                'message' => [
                    'content' => json_encode(['fields' => $fields], JSON_UNESCAPED_UNICODE),
                ],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

test('phase4 skips http when trigger evaluator says no', function () {
    Http::fake();
    $user = User::factory()->create();
    $intake = phase4fIntake($user, [
        'full_name' => phase4fResolved('Integration Candidate'),
        'date_of_birth' => phase4fResolved('1992-01-04'),
        'primary_contact_number' => phase4fResolved('9876543210'),
        'religion' => phase4fResolved('Hindu'),
    ]);
    $before = $intake->field_resolution_json;
    $beforeParse = $intake->last_parse_input_text;

    $result = app(IntakeOcrEnsemblePhase4Service::class)
        ->runForBulkItemIfApplicable(phase4fItem(phase4fBatch($user), $intake));

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->reason)->toBe('no_triggers')
        ->and($intake->fresh()->field_resolution_json)->toBe($before)
        ->and($intake->fresh()->last_parse_input_text)->toBe($beforeParse);

    Http::assertNothingSent();
});

test('phase4 successful judge merges persists and rebuilds parse input', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response(phase4fSuccessHttpBody([
            [
                'field_name' => 'date_of_birth',
                'value' => '1992-01-04',
                'confidence' => 0.95,
                'reason' => 'vision',
            ],
            [
                'field_name' => 'religion',
                'value' => 'Hindu',
                'confidence' => 0.9,
                'reason' => 'vision',
            ],
        ]), 200),
    ]);

    $user = User::factory()->create();
    $intake = phase4fIntake($user, [
        'full_name' => phase4fResolved('Integration Candidate'),
        'date_of_birth' => phase4fMissing(),
        'primary_contact_number' => phase4fResolved('9876543210'),
        'religion' => phase4fMissing(),
        'education' => phase4fResolved('BE Computer'),
    ]);
    $rawBefore = $intake->raw_ocr_text;

    $result = app(IntakeOcrEnsemblePhase4Service::class)
        ->runForBulkItemIfApplicable(phase4fItem(phase4fBatch($user), $intake));

    $fresh = $intake->fresh();

    expect($result->wasResolved())->toBeTrue()
        ->and($fresh->field_resolution_json['fields']['date_of_birth']['final'])->toBe('1992-01-04')
        ->and($fresh->field_resolution_json['fields']['date_of_birth']['source'])->toBe(OcrEnsemblePhase4Constants::FIELD_SOURCE_SARVAM_JUDGE)
        ->and($fresh->field_resolution_json['fields']['religion']['final'])->toBe('Hindu')
        ->and(
            str_contains((string) $fresh->last_parse_input_text, '1992-01-04')
            || str_contains((string) $fresh->last_parse_input_text, '04/01/1992')
        )->toBeTrue()
        ->and($fresh->last_parse_input_text)->toContain('Hindu')
        ->and($fresh->raw_ocr_text)->toBe($rawBefore);

    Http::assertSentCount(1);
});

test('phase4 soft-fails on http timeout and preserves phase3 data', function () {
    Http::fake(function () {
        throw new ConnectionException('cURL error 28: timeout');
    });

    $user = User::factory()->create();
    $intake = phase4fIntake($user, [
        'full_name' => phase4fResolved('Integration Candidate'),
        'date_of_birth' => phase4fMissing(),
        'primary_contact_number' => phase4fResolved('9876543210'),
        'religion' => phase4fResolved('Hindu'),
    ]);
    $before = $intake->field_resolution_json;
    $beforeParse = $intake->last_parse_input_text;
    $rawBefore = $intake->raw_ocr_text;

    $result = app(IntakeOcrEnsemblePhase4Service::class)->judge($intake);

    expect($result->wasSoftFailed())->toBeTrue()
        ->and($result->reason)->toBe('sarvam_timeout')
        ->and($intake->fresh()->field_resolution_json)->toBe($before)
        ->and($intake->fresh()->last_parse_input_text)->toBe($beforeParse)
        ->and($intake->fresh()->raw_ocr_text)->toBe($rawBefore);
});

test('phase4 soft-fails on http 500 and preserves phase3 data', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response('server error', 500),
    ]);

    $user = User::factory()->create();
    $intake = phase4fIntake($user, [
        'full_name' => phase4fResolved('Integration Candidate'),
        'date_of_birth' => phase4fMissing(),
        'primary_contact_number' => phase4fResolved('9876543210'),
        'religion' => phase4fResolved('Hindu'),
    ]);
    $before = $intake->field_resolution_json;

    $result = app(IntakeOcrEnsemblePhase4Service::class)->judge($intake);

    expect($result->wasSoftFailed())->toBeTrue()
        ->and($result->reason)->toBe('sarvam_http_error')
        ->and($intake->fresh()->field_resolution_json)->toBe($before);
});

test('phase4 merge no-op does not persist', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response(phase4fSuccessHttpBody([
            [
                'field_name' => 'religion',
                'value' => 'Buddhist',
                'confidence' => 0.2,
                'reason' => 'weak',
            ],
        ]), 200),
    ]);

    $user = User::factory()->create();
    $intake = phase4fIntake($user, [
        'full_name' => phase4fResolved('Integration Candidate'),
        'date_of_birth' => phase4fResolved('1992-01-04'),
        'primary_contact_number' => phase4fResolved('9876543210'),
        // Trigger via conflict-ish unresolved religion at high confidence already filled
        'religion' => phase4fResolved('Hindu', 0.9),
        // Force trigger by leaving DOB missing wait - if all resolved no HTTP
        // Use missing DOB but return only weak religion (no DOB) → merge no-op for religion if not triggered
    ]);

    // Religion already resolved high confidence → not in trigger. Need a missing trigger field.
    $intake->field_resolution_json = phase4fEnvelope([
        'full_name' => phase4fResolved('Integration Candidate'),
        'date_of_birth' => phase4fMissing(),
        'primary_contact_number' => phase4fResolved('9876543210'),
        'religion' => phase4fResolved('Hindu', 0.9),
    ], (int) $intake->id)->toArray();
    $intake->save();

    // Sarvam returns only religion with lower confidence and no DOB → merge no-op
    $before = $intake->fresh()->field_resolution_json;
    $beforeParse = $intake->fresh()->last_parse_input_text;
    $updatedAt = $intake->fresh()->updated_at?->toIso8601String();

    $result = app(IntakeOcrEnsemblePhase4Service::class)->judge($intake->fresh());

    expect($result->wasNoop())->toBeTrue()
        ->and($result->reason)->toBe('merge_noop')
        ->and($intake->fresh()->field_resolution_json)->toBe($before)
        ->and($intake->fresh()->last_parse_input_text)->toBe($beforeParse)
        ->and($intake->fresh()->updated_at?->toIso8601String())->toBe($updatedAt);
});

test('phase4 does not persist when assembled parse input fails quality gate', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response(phase4fSuccessHttpBody([
            [
                'field_name' => 'religion',
                'value' => 'H',
                'confidence' => 0.99,
            ],
        ]), 200),
    ]);

    $user = User::factory()->create();
    // Extremely short OCR body so assembled output stays under MIN length even after merge.
    $shortOcr = 'धर्म :';
    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => $shortOcr,
        'last_parse_input_text' => 'short',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);
    $intake->field_resolution_json = phase4fEnvelope([
        'religion' => phase4fMissing(),
    ], (int) $intake->id)->toArray();
    $intake->save();
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => $shortOcr,
        'is_primary' => true,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);

    $before = $intake->fresh()->field_resolution_json;

    $result = app(IntakeOcrEnsemblePhase4Service::class)->judge($intake->fresh());

    expect($result->wasNoop())->toBeTrue()
        ->and($result->reason)->toBe('assembled_parse_input_too_short')
        ->and($intake->fresh()->field_resolution_json)->toBe($before);
});

test('phase4 raw_ocr_text remains immutable after successful persist', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response(phase4fSuccessHttpBody([
            [
                'field_name' => 'religion',
                'value' => 'Hindu',
                'confidence' => 0.95,
            ],
        ]), 200),
    ]);

    $user = User::factory()->create();
    $intake = phase4fIntake($user, [
        'full_name' => phase4fResolved('Integration Candidate'),
        'date_of_birth' => phase4fResolved('1992-01-04'),
        'primary_contact_number' => phase4fResolved('9876543210'),
        'religion' => phase4fMissing(),
    ]);
    $rawBefore = $intake->raw_ocr_text;

    app(IntakeOcrEnsemblePhase4Service::class)->judge($intake);

    expect($intake->fresh()->raw_ocr_text)->toBe($rawBefore);
});

test('bulk intake batch service phase4 hook soft-fails without throwing', function () {
    Http::fake(function () {
        throw new ConnectionException('timeout');
    });

    $user = User::factory()->create();
    $intake = phase4fIntake($user, [
        'full_name' => phase4fResolved('Integration Candidate'),
        'date_of_birth' => phase4fMissing(),
        'primary_contact_number' => phase4fResolved('9876543210'),
        'religion' => phase4fResolved('Hindu'),
    ]);
    $item = phase4fItem(phase4fBatch($user), $intake);
    $before = $intake->field_resolution_json;

    $result = app(IntakeOcrEnsemblePhase4Service::class)->runForBulkItemIfApplicable($item);

    // Explicit BulkIntakeBatchService wiring path
    app(BulkIntakeBatchService::class);
    $wired = (new ReflectionClass(BulkIntakeBatchService::class))
        ->getMethod('runPhase4SarvamJudgeIfApplicable');
    $wired->setAccessible(true);
    $wired->invoke(app(BulkIntakeBatchService::class), $item);

    expect($result->wasSoftFailed())->toBeTrue()
        ->and($intake->fresh()->field_resolution_json)->toBe($before);
});

test('phase4 client remains sole http entry and request uses deterministic body', function () {
    Http::fake([
        'https://example.test/v1/chat/completions' => Http::response(phase4fSuccessHttpBody([
            [
                'field_name' => 'date_of_birth',
                'value' => '1992-01-04',
                'confidence' => 0.95,
            ],
        ]), 200),
    ]);

    $user = User::factory()->create();
    $intake = phase4fIntake($user, [
        'full_name' => phase4fResolved('Integration Candidate'),
        'date_of_birth' => phase4fMissing(),
        'primary_contact_number' => phase4fResolved('9876543210'),
        'religion' => phase4fResolved('Hindu'),
    ]);

    app(IntakeOcrEnsemblePhase4Service::class)->judge($intake);

    Http::assertSent(function ($request) {
        $payload = json_decode($request->body(), true);

        return $request->url() === 'https://example.test/v1/chat/completions'
            && ($payload['temperature'] ?? null) === 0
            && is_string($payload['messages'][1]['content'] ?? null);
    });
});
