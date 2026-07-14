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
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase5Constants;

function phase5fAdmin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function phase5fEnableGate(): void
{
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, true);
    config()->set('ocr.ensemble.phase5.enabled', true);
}

function phase5fBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Phase 5f UI batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function phase5fIntake(array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => null,
        'raw_ocr_text' => 'sample ocr text for phase five comparison UI tests with enough characters',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Ui Comparison Candidate',
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

function phase5fItem(BulkIntakeBatch $batch, BiodataIntake $intake): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'phase5f.pdf',
        'source_file_path' => 'bulk-intakes/phase5f.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ]);
}

/**
 * @param  array<string, FieldResolutionFieldRecord>  $overrides
 */
function phase5fEnvelope(int $intakeId, array $overrides = []): array
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

function phase5fRecord(
    ?string $final,
    array $candidates,
    string $reason,
    string $status,
    string $source,
    ?string $winningEngine = null,
): FieldResolutionFieldRecord {
    return new FieldResolutionFieldRecord(
        final: $final,
        status: $status,
        source: $source,
        winningEngine: $winningEngine,
        confidence: null,
        reason: $reason,
        candidates: $candidates,
        normalized: $candidates,
        validator: [
            'passed' => $status === OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            'code' => 'ok',
            'detail' => null,
        ],
    );
}

function phase5fPrimaryTesseract(BiodataIntake $intake): void
{
    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'tesseract ui evidence',
        'is_primary' => true,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);
}

function phase5fCorrectCandidateUrl(BulkIntakeBatch $batch, BulkIntakeBatchItem $item): string
{
    return route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]);
}

test('resolved comparison page renders table with highlighted final', function () {
    phase5fEnableGate();
    $admin = phase5fAdmin();
    $batch = phase5fBatch($admin);
    $intake = phase5fIntake();
    phase5fPrimaryTesseract($intake);

    $intake->field_resolution_json = phase5fEnvelope((int) $intake->id, [
        'religion' => phase5fRecord(
            'Hindu',
            [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Hindu'],
            'single_engine_pass_through',
            OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            OcrEnsemblePhase3Constants::FIELD_SOURCE_SINGLE_ENGINE,
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        ),
    ]);
    $intake->save();
    $item = phase5fItem($batch, $intake->fresh());

    $this->actingAs($admin)
        ->get(phase5fCorrectCandidateUrl($batch, $item))
        ->assertOk()
        ->assertSee('data-testid="ocr-comparison-review"', false)
        ->assertSee('data-ocr-comparison-surface="correct_candidate"', false)
        ->assertSee('data-outcome="resolved"', false)
        ->assertSee('data-testid="ocr-comparison-table"', false)
        ->assertSee('data-row-count="16"', false)
        ->assertSee('data-testid="ocr-comparison-row-religion"', false)
        ->assertSee('data-testid="ocr-comparison-final-highlight"', false)
        ->assertSee('Hindu', false);
});

test('empty comparison page shows empty notice and canonical rows', function () {
    phase5fEnableGate();
    $admin = phase5fAdmin();
    $batch = phase5fBatch($admin);
    $intake = phase5fIntake(['field_resolution_json' => null]);
    $item = phase5fItem($batch, $intake);

    $response = $this->actingAs($admin)
        ->get(phase5fCorrectCandidateUrl($batch, $item))
        ->assertOk()
        ->assertSee('data-outcome="empty"', false)
        ->assertSee('data-testid="ocr-comparison-empty-notice"', false)
        ->assertSee('data-testid="ocr-comparison-table"', false)
        ->assertSee('data-row-count="16"', false);

    foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
        $response->assertSee('data-testid="ocr-comparison-row-'.$fieldKey.'"', false);
    }
});

test('missing engine columns render as empty dashes', function () {
    phase5fEnableGate();
    $admin = phase5fAdmin();
    $batch = phase5fBatch($admin);
    $intake = phase5fIntake();
    phase5fPrimaryTesseract($intake);

    $intake->field_resolution_json = phase5fEnvelope((int) $intake->id, [
        'caste' => phase5fRecord(
            'Maratha',
            [
                OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Maratha',
                OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR => 'SHOULD_NOT_APPEAR',
                OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM => 'ALSO_HIDDEN',
            ],
            'single_engine_pass_through',
            OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            OcrEnsemblePhase3Constants::FIELD_SOURCE_SINGLE_ENGINE,
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        ),
    ]);
    $intake->save();
    $item = phase5fItem($batch, $intake->fresh());

    $html = $this->actingAs($admin)
        ->get(phase5fCorrectCandidateUrl($batch, $item))
        ->assertOk()
        ->assertSee('data-testid="ocr-comparison-row-caste"', false)
        ->assertSee('Maratha', false)
        ->assertDontSee('SHOULD_NOT_APPEAR', false)
        ->assertDontSee('ALSO_HIDDEN', false)
        ->getContent();

    expect($html)->toContain('data-testid="ocr-comparison-second-ocr"')
        ->and($html)->toContain('data-testid="ocr-comparison-sarvam"')
        ->and($html)->toContain('data-empty="1"');
});

test('status badges render resolved missing and conflict', function () {
    phase5fEnableGate();
    $admin = phase5fAdmin();
    $batch = phase5fBatch($admin);
    $intake = phase5fIntake();
    phase5fPrimaryTesseract($intake);

    $intake->field_resolution_json = phase5fEnvelope((int) $intake->id, [
        'religion' => phase5fRecord(
            'Hindu',
            [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Hindu'],
            'ok',
            OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            OcrEnsemblePhase3Constants::FIELD_SOURCE_VALIDATOR,
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        ),
        'full_name' => phase5fRecord(
            null,
            [],
            'missing_name',
            OcrEnsemblePhase3Constants::FIELD_STATUS_MISSING,
            OcrEnsemblePhase3Constants::FIELD_SOURCE_MISSING,
        ),
        'education' => phase5fRecord(
            null,
            [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'BE'],
            'engines_disagree',
            OcrEnsemblePhase3Constants::FIELD_STATUS_CONFLICT,
            OcrEnsemblePhase3Constants::FIELD_SOURCE_VOTE,
        ),
    ]);
    $intake->save();
    $item = phase5fItem($batch, $intake->fresh());

    $this->actingAs($admin)
        ->get(phase5fCorrectCandidateUrl($batch, $item))
        ->assertOk()
        ->assertSee('data-status-badge="resolved"', false)
        ->assertSee('data-status-badge="missing"', false)
        ->assertSee('data-status-badge="conflict"', false);
});

test('source badges render validator vote and sarvam', function () {
    phase5fEnableGate();
    $admin = phase5fAdmin();
    $batch = phase5fBatch($admin);
    $intake = phase5fIntake();
    phase5fPrimaryTesseract($intake);

    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'sarvam ui evidence',
        'is_primary' => false,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);

    $intake->field_resolution_json = phase5fEnvelope((int) $intake->id, [
        'religion' => phase5fRecord(
            'Hindu',
            [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Hindu'],
            'validator_ok',
            OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            OcrEnsemblePhase3Constants::FIELD_SOURCE_VALIDATOR,
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        ),
        'occupation' => phase5fRecord(
            'Engineer',
            [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Engineer'],
            '2/2 vote',
            OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            OcrEnsemblePhase3Constants::FIELD_SOURCE_VOTE,
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        ),
        'date_of_birth' => phase5fRecord(
            '1998-04-15',
            [OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM => '1998-04-15'],
            'sarvam_judge_merge',
            OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            'sarvam_judge',
            OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
        ),
    ]);
    $intake->save();
    $item = phase5fItem($batch, $intake->fresh());

    $this->actingAs($admin)
        ->get(phase5fCorrectCandidateUrl($batch, $item))
        ->assertOk()
        ->assertSee('data-source-badge="validator"', false)
        ->assertSee('data-source-badge="vote"', false)
        ->assertSee('data-source-badge="sarvam_judge"', false);
});

test('deterministic row rendering follows phase3 structured field order', function () {
    phase5fEnableGate();
    $admin = phase5fAdmin();
    $batch = phase5fBatch($admin);
    $intake = phase5fIntake();
    phase5fPrimaryTesseract($intake);
    $intake->field_resolution_json = FieldResolutionEnvelope::skeleton((int) $intake->id)->toArray();
    $intake->save();
    $item = phase5fItem($batch, $intake->fresh());

    $html = $this->actingAs($admin)
        ->get(phase5fCorrectCandidateUrl($batch, $item))
        ->assertOk()
        ->assertSee('data-row-count="16"', false)
        ->getContent();

    $positions = [];
    foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
        $pos = strpos($html, 'data-testid="ocr-comparison-row-'.$fieldKey.'"');
        expect($pos)->not->toBeFalse();
        $positions[] = $pos;
    }

    $sorted = $positions;
    sort($sorted, SORT_NUMERIC);
    expect($positions)->toBe($sorted);

    foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $index => $fieldKey) {
        expect($html)->toContain(
            'data-testid="ocr-comparison-row-'.$fieldKey.'" data-field-key="'.$fieldKey.'" data-row-index="'.$index.'"'
        );
    }
});
