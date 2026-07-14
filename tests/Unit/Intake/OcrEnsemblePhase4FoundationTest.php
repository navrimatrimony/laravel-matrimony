<?php

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Intake\IntakeOcrEnsembleGate;
use App\Services\Intake\IntakeOcrEnsemblePhase4Service;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\Phase4JudgeResult;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeTriggerReport;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase4Constants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('phase4 trigger fields match frozen blueprint list and exclude gender', function () {
    expect(OcrEnsemblePhase4Constants::TRIGGER_FIELDS)->toBe([
        'full_name',
        'date_of_birth',
        'primary_contact_number',
        'religion',
    ])->and(OcrEnsemblePhase4Constants::NON_TRIGGER_FIELDS)->toContain('gender')
        ->and(OcrEnsemblePhase4Constants::TRIGGER_FIELDS)->not->toContain('gender');
});

test('sarvam judge trigger report round trips through array', function () {
    $original = new SarvamJudgeTriggerReport(
        shouldInvokeSarvam: true,
        triggeredFields: ['date_of_birth' => OcrEnsemblePhase4Constants::TRIGGER_REASON_DOB_MISSING],
        evaluatedTriggerFields: OcrEnsemblePhase4Constants::TRIGGER_FIELDS,
        skipReason: null,
    );

    expect(SarvamJudgeTriggerReport::fromArray($original->toArray())->toArray())->toBe($original->toArray());
});

test('phase4 gate requires ensemble phase3 and phase4 config', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, false);
    config()->set('ocr.ensemble.phase3.enabled', true);
    config()->set('ocr.ensemble.phase4.enabled', true);

    expect(app(IntakeOcrEnsembleGate::class)->isPhase4Enabled())->toBeFalse();

    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, true);
    config()->set('ocr.ensemble.phase3.enabled', false);

    expect(app(IntakeOcrEnsembleGate::class)->isPhase4Enabled())->toBeFalse();

    config()->set('ocr.ensemble.phase3.enabled', true);
    config()->set('ocr.ensemble.phase4.enabled', false);

    expect(app(IntakeOcrEnsembleGate::class)->isPhase4Enabled())->toBeFalse();

    config()->set('ocr.ensemble.phase4.enabled', true);

    expect(app(IntakeOcrEnsembleGate::class)->isPhase4Enabled())->toBeTrue();
});

test('phase4 service skips when gate is disabled', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, false);

    $item = new BulkIntakeBatchItem([
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
    ]);

    $result = app(IntakeOcrEnsemblePhase4Service::class)->runForBulkItemIfApplicable($item);

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->reason)->toBe('phase4_gate_disabled');
});

test('phase4 service skips text bulk items', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, true);
    config()->set('ocr.ensemble.phase3.enabled', true);
    config()->set('ocr.ensemble.phase4.enabled', true);

    $item = new BulkIntakeBatchItem([
        'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
    ]);

    $result = app(IntakeOcrEnsemblePhase4Service::class)->runForBulkItemIfApplicable($item);

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->reason)->toBe('bulk_item_ineligible');
});

test('phase4 service skips when field_resolution_json is missing', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, true);
    config()->set('ocr.ensemble.phase3.enabled', true);
    config()->set('ocr.ensemble.phase4.enabled', true);

    $user = User::factory()->create();
    $batch = BulkIntakeBatch::create([
        'uploaded_by_user_id' => $user->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_status' => BulkIntakeBatch::STATUS_PENDING,
    ]);
    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'sample ocr text for phase four skip path',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    $item = BulkIntakeBatchItem::create([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ]);

    $result = app(IntakeOcrEnsemblePhase4Service::class)->runForBulkItemIfApplicable($item);

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->reason)->toBe('missing_field_resolution_json');
});

test('phase4 judge returns not implemented skeleton outcome', function () {
    $user = User::factory()->create();
    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'sample ocr text for phase four skeleton judge',
        'field_resolution_json' => FieldResolutionEnvelope::skeleton(737)->toArray(),
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    $result = app(IntakeOcrEnsemblePhase4Service::class)->judge($intake);

    expect($result->wasNotImplemented())->toBeTrue()
        ->and($result->reason)->toBe('phase4_v1_skeleton');
});

test('phase4 resolution result helpers', function () {
    expect(Phase4JudgeResult::skipped('x')->wasSkipped())->toBeTrue()
        ->and(Phase4JudgeResult::notImplemented('y')->wasNotImplemented())->toBeTrue()
        ->and(Phase4JudgeResult::noop('z')->wasNoop())->toBeTrue()
        ->and(Phase4JudgeResult::resolved(FieldResolutionEnvelope::skeleton(1))->wasResolved())->toBeTrue();
});

test('phase4 production files do not import benchmark classes', function () {
    $paths = [
        app_path('Services/Intake/IntakeOcrEnsemblePhase4Service.php'),
        app_path('Services/Intake/OcrEnsemble/OcrEnsemblePhase4Constants.php'),
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleSarvamJudgeTriggerEvaluator.php'),
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleSarvamJudgeRequestBuilder.php'),
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleSarvamJudgeClient.php'),
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleSarvamJudgeMerger.php'),
        app_path('Services/Intake/OcrEnsemble/Data/Phase4JudgeResult.php'),
        app_path('Services/Intake/OcrEnsemble/Data/SarvamJudgeTriggerReport.php'),
        app_path('Services/Intake/OcrEnsemble/Data/SarvamJudgeRequest.php'),
        app_path('Services/Intake/OcrEnsemble/Data/SarvamJudgeRequestField.php'),
        app_path('Services/Intake/OcrEnsemble/Support/OcrEnsembleSarvamJudgeRequestSupport.php'),
    ];

    foreach ($paths as $file) {
        $contents = (string) file_get_contents($file);
        expect($contents)->not->toContain('OcrEnsembleBenchmark');
    }
});

test('phase4 trigger evaluator evaluates skeleton envelope as all trigger fields missing', function () {
    $report = app(\App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeTriggerEvaluatorInterface::class)
        ->evaluate(FieldResolutionEnvelope::skeleton(735));

    expect($report->shouldInvokeSarvam)->toBeTrue()
        ->and($report->shouldJudge())->toBeTrue()
        ->and($report->triggerFields())->toBe(OcrEnsemblePhase4Constants::TRIGGER_FIELDS)
        ->and($report->unresolvedCount)->toBe(4)
        ->and($report->skipReason)->toBeNull();
});
