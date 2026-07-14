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

function phase5b1Admin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function phase5b1Member(): User
{
    return User::factory()->create([
        'is_admin' => false,
        'admin_role' => null,
    ]);
}

function phase5b1EnableGate(): void
{
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, true);
    config()->set('ocr.ensemble.phase5.enabled', true);
}

function phase5b1Batch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Phase 5 B1 comparison batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function phase5b1Intake(array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => null,
        'raw_ocr_text' => 'sample ocr text for phase five b1 correct candidate comparison',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Comparison Candidate',
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

function phase5b1Item(BulkIntakeBatch $batch, BiodataIntake $intake): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'phase5b1.pdf',
        'source_file_path' => 'bulk-intakes/phase5b1.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ]);
}

/**
 * @param  array<string, FieldResolutionFieldRecord>  $overrides
 */
function phase5b1Envelope(int $intakeId, array $overrides = []): array
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

function phase5b1Record(
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

test('correct candidate page shows ocr comparison review', function () {
    phase5b1EnableGate();
    $admin = phase5b1Admin();
    $batch = phase5b1Batch($admin);
    $intake = phase5b1Intake();
    $item = phase5b1Item($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertOk()
        ->assertSee('data-testid="bulk-correction-ocr-comparison"', false)
        ->assertSee('data-testid="ocr-comparison-review"', false)
        ->assertSee('data-ocr-comparison-surface="correct_candidate"', false)
        ->assertSee('data-testid="ocr-comparison-table"', false)
        ->assertSee('Bulk Candidate Correction', false);
});

test('unauthorized user cannot open correct candidate comparison', function () {
    phase5b1EnableGate();
    $admin = phase5b1Admin();
    $member = phase5b1Member();
    $batch = phase5b1Batch($admin);
    $intake = phase5b1Intake();
    $item = phase5b1Item($batch, $intake);

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertForbidden();
});

test('correct candidate shows empty comparison when no field resolution', function () {
    phase5b1EnableGate();
    $admin = phase5b1Admin();
    $batch = phase5b1Batch($admin);
    $intake = phase5b1Intake(['field_resolution_json' => null]);
    $item = phase5b1Item($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertOk()
        ->assertSee('data-outcome="empty"', false)
        ->assertSee('data-testid="ocr-comparison-empty-notice"', false)
        ->assertSee('data-row-count="16"', false);
});

test('correct candidate shows resolved comparison with highlighted final', function () {
    phase5b1EnableGate();
    $admin = phase5b1Admin();
    $batch = phase5b1Batch($admin);
    $intake = phase5b1Intake();

    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'tesseract evidence for correct candidate',
        'is_primary' => true,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);

    $intake->field_resolution_json = phase5b1Envelope((int) $intake->id, [
        'religion' => phase5b1Record(
            'Hindu',
            [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Hindu'],
            'single_engine_pass_through',
            OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            OcrEnsemblePhase3Constants::FIELD_SOURCE_SINGLE_ENGINE,
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        ),
    ]);
    $intake->save();
    $item = phase5b1Item($batch, $intake->fresh());

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertOk()
        ->assertSee('data-outcome="resolved"', false)
        ->assertSee('data-testid="ocr-comparison-row-religion"', false)
        ->assertSee('data-testid="ocr-comparison-final-highlight"', false)
        ->assertSee('Hindu', false);
});

test('correct candidate hides missing engine values even if candidates exist', function () {
    phase5b1EnableGate();
    $admin = phase5b1Admin();
    $batch = phase5b1Batch($admin);
    $intake = phase5b1Intake();

    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'tesseract only correct candidate',
        'is_primary' => true,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);

    $intake->field_resolution_json = phase5b1Envelope((int) $intake->id, [
        'caste' => phase5b1Record(
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
    $item = phase5b1Item($batch, $intake->fresh());

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertOk()
        ->assertSee('Maratha', false)
        ->assertDontSee('SHOULD_NOT_APPEAR', false)
        ->assertDontSee('ALSO_HIDDEN', false)
        ->assertSee('data-empty="1"', false);
});

test('correct candidate comparison rows follow phase3 structured field order', function () {
    phase5b1EnableGate();
    $admin = phase5b1Admin();
    $batch = phase5b1Batch($admin);
    $intake = phase5b1Intake([
        'field_resolution_json' => FieldResolutionEnvelope::skeleton(1)->toArray(),
    ]);
    $intake->field_resolution_json = FieldResolutionEnvelope::skeleton((int) $intake->id)->toArray();
    $intake->save();
    $item = phase5b1Item($batch, $intake->fresh());

    $html = $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertOk()
        ->assertSee('data-row-count="16"', false)
        ->getContent();

    foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $index => $fieldKey) {
        expect($html)->toContain(
            'data-testid="ocr-comparison-row-'.$fieldKey.'" data-field-key="'.$fieldKey.'" data-row-index="'.$index.'"'
        );
    }
});

test('legacy standalone ocr comparison route redirects to correct candidate', function () {
    phase5b1EnableGate();
    $admin = phase5b1Admin();
    $batch = phase5b1Batch($admin);
    $intake = phase5b1Intake();
    $item = phase5b1Item($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.biodata-intakes.ocr-comparison', $intake))
        ->assertRedirect(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]));
});

test('legacy standalone ocr comparison route 404s without bulk item', function () {
    phase5b1EnableGate();
    $admin = phase5b1Admin();
    $intake = phase5b1Intake();

    $this->actingAs($admin)
        ->get(route('admin.biodata-intakes.ocr-comparison', $intake))
        ->assertNotFound();
});
