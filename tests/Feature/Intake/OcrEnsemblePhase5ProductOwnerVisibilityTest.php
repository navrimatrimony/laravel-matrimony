<?php

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Intake\IntakeOcrEnsembleGate;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionMeta;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonAttemptSummary;
use App\Services\Intake\OcrEnsemble\Data\Phase5ComparisonResult;
use App\Services\Intake\OcrEnsemble\OcrEnsembleJudgeParticipationBuilder;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase4Constants;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase5Constants;
use App\Services\Intake\IntakeOcrEnsemblePhase5Service;

function phase5PoAdmin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function phase5PoEnableGate(): void
{
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, true);
    config()->set('ocr.ensemble.phase5.enabled', true);
}

function phase5PoBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Phase 5 PO visibility batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function phase5PoIntake(array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => null,
        'raw_ocr_text' => 'sample ocr text for phase five product owner visibility coverage',
        'parsed_json' => [
            'core' => [
                'full_name' => 'PO Visibility Candidate',
                'primary_contact_number' => '9876543210',
            ],
        ],
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function phase5PoItem(BulkIntakeBatch $batch, BiodataIntake $intake): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'phase5-po.pdf',
        'source_file_path' => 'bulk-intakes/phase5-po.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ]);
}

function phase5PoEnvelope(int $intakeId, array $overrides = []): array
{
    $fields = [];
    foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
        $fields[$fieldKey] = FieldResolutionFieldRecord::missingSkeleton('not_present');
    }
    foreach ($overrides as $fieldKey => $record) {
        $fields[$fieldKey] = $record;
    }

    return (new FieldResolutionEnvelope(
        meta: FieldResolutionMeta::skeleton($intakeId),
        fields: $fields,
    ))->toArray();
}

test('correct candidate shows PO visibility surfaces: labels winner judge raw metrics', function () {
    phase5PoEnableGate();
    $admin = phase5PoAdmin();
    $batch = phase5PoBatch($admin);
    $intake = phase5PoIntake();

    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => "TESSERACT_RAW_PO_VISIBLE\nजन्म :२४/०३/१९९९",
        'quality_score' => 0.81,
        'duration_ms' => 1234,
        'is_primary' => true,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);

    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => "SARVAM_RAW_PO_VISIBLE\nजन्म तारीख : २१/०३/१९९९",
        'duration_ms' => 5500,
        'is_primary' => false,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);

    $intake->field_resolution_json = phase5PoEnvelope((int) $intake->id, [
        'date_of_birth' => new FieldResolutionFieldRecord(
            final: '1999-03-21',
            status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            source: OcrEnsemblePhase4Constants::FIELD_SOURCE_SARVAM_JUDGE,
            winningEngine: OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
            confidence: null,
            reason: 'sarvam_judge_accepted',
            candidates: [
                OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => '1999-03-24',
                OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM => '1999-03-21',
            ],
            normalized: [
                OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => '1999-03-24',
                OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM => '1999-03-21',
            ],
            validator: ['passed' => true, 'code' => 'ok', 'detail' => null],
        ),
        'full_name' => new FieldResolutionFieldRecord(
            final: 'PO Visibility Candidate',
            status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            source: OcrEnsemblePhase3Constants::FIELD_SOURCE_SINGLE_ENGINE,
            winningEngine: OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
            confidence: null,
            reason: 'single_engine_pass_through',
            candidates: [
                OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'PO Visibility Candidate',
            ],
            normalized: [
                OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'PO Visibility Candidate',
            ],
            validator: ['passed' => true, 'code' => 'ok', 'detail' => null],
        ),
    ]);
    $intake->save();
    $item = phase5PoItem($batch, $intake->fresh());

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertOk()
        ->assertSee('data-testid="ocr-comparison-review"', false)
        ->assertSee('data-testid="ocr-comparison-human-path-note"', false)
        ->assertSee('data-testid="ocr-comparison-field-label"', false)
        ->assertSee('DOB', false)
        ->assertSee('Full name', false)
        ->assertSee('data-testid="ocr-comparison-winner"', false)
        ->assertSee('data-winning-engine="'.OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM.'"', false)
        ->assertSee('data-source="sarvam_judge"', false)
        ->assertSee('data-testid="ocr-engine-debug-metrics"', false)
        ->assertSee('data-testid="ocr-judge-participation"', false)
        ->assertSee('data-participated="1"', false)
        ->assertSee('data-testid="ocr-judge-fields-table"', false)
        ->assertSee('data-testid="ocr-attempt-raw-transcripts"', false)
        ->assertSee('TESSERACT_RAW_PO_VISIBLE', false)
        ->assertSee('SARVAM_RAW_PO_VISIBLE', false)
        ->assertSee('1999-03-21', false)
        ->assertSee('1999-03-24', false);
});

test('judge participation builder reports no participation when sarvam absent', function () {
    $builder = new OcrEnsembleJudgeParticipationBuilder;
    $summary = new OcrComparisonAttemptSummary(
        attemptId: 1,
        engine: OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        source: null,
        status: 'success',
        isPrimary: true,
        rawText: 'only tesseract',
        engineMetaJson: null,
        qualityScore: 0.5,
        durationMs: 100,
        selectedReason: null,
        preprocessingVersion: null,
        promptVersion: null,
        parserVersion: null,
    );

    $result = $builder->build([$summary], Phase5ComparisonResult::empty('ensemble_not_run'));

    expect($result['participated'])->toBeFalse()
        ->and($result['attempt_count'])->toBe(0)
        ->and($result['judged_fields'])->toBe([]);
});

test('table builder exposes plain field labels and winning engine', function () {
    phase5PoEnableGate();
    $intake = phase5PoIntake();
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'label check',
        'is_primary' => true,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);
    $intake->field_resolution_json = phase5PoEnvelope((int) $intake->id, [
        'religion' => new FieldResolutionFieldRecord(
            final: 'Hindu',
            status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            source: OcrEnsemblePhase3Constants::FIELD_SOURCE_VALIDATOR,
            winningEngine: OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
            confidence: null,
            reason: 'dictionary_match',
            candidates: [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Hindu'],
            normalized: [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Hindu'],
            validator: ['passed' => true, 'code' => 'ok', 'detail' => null],
        ),
    ]);
    $intake->save();

    $comparison = app(IntakeOcrEnsemblePhase5Service::class)->buildComparisonForIntake($intake->fresh());
    $religion = collect($comparison->table?->rows ?? [])->first(
        fn ($row) => $row->fieldKey === 'religion'
    );

    expect($religion)->not->toBeNull()
        ->and($religion->fieldLabel)->toBe('Religion')
        ->and($religion->winningEngine)->toBe(OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT)
        ->and($religion->source)->toBe(OcrEnsemblePhase3Constants::FIELD_SOURCE_VALIDATOR);
});
