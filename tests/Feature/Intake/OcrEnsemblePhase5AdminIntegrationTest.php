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

/**
 * Phase 5e legacy route coverage after P5-B1: standalone entry redirects to Correct Candidate.
 */

function phase5eAdmin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function phase5eMember(): User
{
    return User::factory()->create([
        'is_admin' => false,
        'admin_role' => null,
    ]);
}

function phase5eEnableGate(): void
{
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, true);
    config()->set('ocr.ensemble.phase5.enabled', true);
}

function phase5eDisableGate(): void
{
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, false);
    config()->set('ocr.ensemble.phase5.enabled', true);
}

function phase5eIntake(array $overrides = []): BiodataIntake
{
    $uploader = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $uploader->id,
        'raw_ocr_text' => 'sample ocr text for phase five admin integration tests with enough characters',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function phase5eLinkBulk(User $admin, BiodataIntake $intake): array
{
    $batch = BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Phase 5e redirect batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
    $item = BulkIntakeBatchItem::create([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'phase5e.pdf',
        'source_file_path' => 'bulk-intakes/phase5e.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ]);

    return [$batch, $item];
}

function phase5eResolvedEnvelope(int $intakeId): array
{
    $fields = [];
    foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
        $fields[$fieldKey] = FieldResolutionFieldRecord::missingSkeleton('not_present');
    }

    $fields['religion'] = new FieldResolutionFieldRecord(
        final: 'Hindu',
        status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
        source: OcrEnsemblePhase3Constants::FIELD_SOURCE_SINGLE_ENGINE,
        winningEngine: OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        confidence: null,
        reason: 'single_engine_pass_through',
        candidates: [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Hindu'],
        normalized: [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Hindu'],
        validator: [
            'passed' => true,
            'code' => 'ok',
            'detail' => null,
        ],
    );

    return (new FieldResolutionEnvelope(
        meta: FieldResolutionMeta::skeleton($intakeId),
        fields: $fields,
    ))->toArray();
}

test('authorized admin is redirected from standalone ocr comparison to correct candidate', function () {
    phase5eEnableGate();
    $admin = phase5eAdmin();
    $intake = phase5eIntake();
    [$batch, $item] = phase5eLinkBulk($admin, $intake);

    $this->actingAs($admin)
        ->get(route('admin.biodata-intakes.ocr-comparison', $intake))
        ->assertRedirect(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]));
});

test('unauthorized user cannot open standalone ocr comparison redirect', function () {
    phase5eEnableGate();
    $admin = phase5eAdmin();
    $member = phase5eMember();
    $intake = phase5eIntake();
    phase5eLinkBulk($admin, $intake);

    $this->actingAs($member)
        ->get(route('admin.biodata-intakes.ocr-comparison', $intake))
        ->assertForbidden();
});

test('ocr comparison returns not found for missing intake', function () {
    phase5eEnableGate();
    $admin = phase5eAdmin();

    $this->actingAs($admin)
        ->get(route('admin.biodata-intakes.ocr-comparison', ['intake' => 999999]))
        ->assertNotFound();
});

test('standalone ocr comparison 404s when intake has no bulk item', function () {
    phase5eEnableGate();
    $admin = phase5eAdmin();
    $intake = phase5eIntake();

    $this->actingAs($admin)
        ->get(route('admin.biodata-intakes.ocr-comparison', $intake))
        ->assertNotFound();
});

test('correct candidate shows skipped outcome when gate disabled', function () {
    phase5eDisableGate();
    $admin = phase5eAdmin();
    $intake = phase5eIntake();
    [$batch, $item] = phase5eLinkBulk($admin, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertOk()
        ->assertSee('data-outcome="skipped"', false)
        ->assertSee('data-reason="phase5_gate_disabled"', false);
});

test('correct candidate shows resolved outcome when field resolution exists', function () {
    phase5eEnableGate();
    $admin = phase5eAdmin();
    $intake = phase5eIntake();

    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'tesseract evidence for admin resolved comparison',
        'is_primary' => true,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);

    $intake->field_resolution_json = phase5eResolvedEnvelope((int) $intake->id);
    $intake->save();
    [$batch, $item] = phase5eLinkBulk($admin, $intake->fresh());

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertOk()
        ->assertSee('data-outcome="resolved"', false)
        ->assertSee('data-testid="ocr-comparison-row-religion"', false)
        ->assertSee('data-testid="ocr-comparison-final-highlight"', false)
        ->assertSee('Hindu', false);
});

test('correct candidate shows empty outcome when no field resolution', function () {
    phase5eEnableGate();
    $admin = phase5eAdmin();
    $intake = phase5eIntake(['field_resolution_json' => null]);
    [$batch, $item] = phase5eLinkBulk($admin, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]))
        ->assertOk()
        ->assertSee('data-outcome="empty"', false)
        ->assertSee('data-reason="'.OcrEnsemblePhase5Constants::EMPTY_STATE_LEGACY_INTAKE.'"', false);
});
