<?php

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Intake\IntakeOcrEnsembleGate;
use App\Services\Intake\IntakeOcrEnsemblePhase3Service;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createPhase3IntegrationBatch(User $user): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $user->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_status' => BulkIntakeBatch::STATUS_PENDING,
    ]);
}

function createPhase3IntegrationItem(BulkIntakeBatch $batch, BiodataIntake $intake, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'phase3-integration.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}

test('bulk phase3 hook resolves and persists when gate is enabled', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, true);
    config()->set('ocr.ensemble.phase3.enabled', true);

    $user = User::factory()->create();
    $rawOcr = <<<'TXT'
मुलाचे नाव : Integration Candidate
मोबाईल : 9876543210
जन्म तारीख : 04/01/1992
कौटुंबिक माहिती ठेवली.
TXT;

    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => $rawOcr,
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => $rawOcr,
        'is_primary' => true,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);

    $item = createPhase3IntegrationItem(createPhase3IntegrationBatch($user), $intake);

    $result = app(IntakeOcrEnsemblePhase3Service::class)->runForBulkItemIfApplicable($item);
    $fresh = $intake->fresh();

    expect($result->wasResolved())->toBeTrue()
        ->and($fresh->field_resolution_json)->toBeArray()
        ->and($fresh->field_resolution_json['fields']['full_name']['status'])->toBe(OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED)
        ->and($fresh->last_parse_input_text)->toContain('Integration Candidate');
});

test('bulk phase3 hook does not persist when gate is disabled', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, false);
    config()->set('ocr.ensemble.phase3.enabled', true);

    $user = User::factory()->create();
    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => "मुलाचे नाव : Gate Off\nमोबाईल : 9876543210\nअपेक्षा.",
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => (string) $intake->raw_ocr_text,
        'is_primary' => true,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ]);

    $item = createPhase3IntegrationItem(createPhase3IntegrationBatch($user), $intake);

    $result = app(IntakeOcrEnsemblePhase3Service::class)->runForBulkItemIfApplicable($item);

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->reason)->toBe('phase3_gate_disabled')
        ->and($intake->fresh()->field_resolution_json)->toBeNull();
});

test('bulk phase3 hook skips reused transcript items', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, true);
    config()->set('ocr.ensemble.phase3.enabled', true);

    $user = User::factory()->create();
    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => "मुलाचे नाव : Reused\nमोबाईल : 9876543210\nअपेक्षा.",
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    $item = createPhase3IntegrationItem(
        createPhase3IntegrationBatch($user),
        $intake,
        ['item_meta_json' => ['ocr_ensemble_skip_reason' => 'reused_transcript']],
    );

    $result = app(IntakeOcrEnsemblePhase3Service::class)->runForBulkItemIfApplicable($item);

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->reason)->toBe('reused_transcript');
});
