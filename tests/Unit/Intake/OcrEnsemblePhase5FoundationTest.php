<?php

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\User;
use App\Services\Intake\IntakeOcrEnsembleGate;
use App\Services\Intake\IntakeOcrEnsemblePhase5Service;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleComparisonEvidenceLoaderInterface;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleComparisonTableBuilderInterface;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonAuditMeta;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonEvidenceBundle;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonFieldRow;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonTable;
use App\Services\Intake\OcrEnsemble\Data\Phase5ComparisonResult;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase5Constants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('phase5 table columns match blueprint comparison contract', function () {
    expect(OcrEnsemblePhase5Constants::TABLE_COLUMNS)->toBe([
        'field',
        'final',
        'tesseract',
        'second_ocr',
        'sarvam',
        'reason',
    ])->and(OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT)->toBe(OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR)
        ->and(OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM)->toBe(OcrEnsemblePhase3Constants::ENGINE_SARVAM_AI_VISION)
        ->and(OcrEnsemblePhase5Constants::SURFACE_CORRECT_CANDIDATE)->toBe('correct_candidate');
});

test('phase5 comparison DTOs round trip through array', function () {
    $row = new OcrComparisonFieldRow(
        fieldKey: 'religion',
        fieldLabel: 'धर्म',
        finalValue: 'Hindu',
        tesseractValue: 'Hindu',
        secondOcrValue: null,
        sarvamValue: 'Hindu',
        reason: 'sarvam_judge_merge',
        status: 'resolved',
        source: 'sarvam_judge',
    );
    $audit = new OcrComparisonAuditMeta(
        schemaVersion: OcrEnsemblePhase5Constants::SCHEMA_VERSION,
        pipelineVersion: OcrEnsemblePhase5Constants::PIPELINE_VERSION,
        intakeId: 501,
        surface: OcrEnsemblePhase5Constants::SURFACE_CORRECT_CANDIDATE,
        ensembleRan: true,
        hasFieldResolutionJson: true,
        attemptCount: 2,
        enginesPresent: [
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
            OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
        ],
        emptyState: null,
    );
    $table = new OcrComparisonTable(
        columns: OcrEnsemblePhase5Constants::TABLE_COLUMNS,
        rows: [$row],
        audit: $audit,
    );

    expect(OcrComparisonTable::fromArray($table->toArray())->toArray())->toBe($table->toArray())
        ->and(OcrComparisonEvidenceBundle::fromArray(
            OcrComparisonEvidenceBundle::empty(9)->toArray()
        )->toArray())->toBe(OcrComparisonEvidenceBundle::empty(9)->toArray());
});

test('phase5 gate requires ensemble and phase5 config', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, false);
    config()->set('ocr.ensemble.phase5.enabled', true);

    expect(app(IntakeOcrEnsembleGate::class)->isPhase5Enabled())->toBeFalse();

    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, true);
    config()->set('ocr.ensemble.phase5.enabled', false);

    expect(app(IntakeOcrEnsembleGate::class)->isPhase5Enabled())->toBeFalse();

    config()->set('ocr.ensemble.phase5.enabled', true);

    expect(app(IntakeOcrEnsembleGate::class)->isPhase5Enabled())->toBeTrue();
});

test('phase5 service skips when gate is disabled', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, false);

    $intake = new BiodataIntake(['id' => 1]);
    $intake->exists = true;

    $result = app(IntakeOcrEnsemblePhase5Service::class)->buildComparisonForIntake($intake);

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->reason)->toBe('phase5_gate_disabled');
});

test('phase5 service returns not implemented skeleton when gated on', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, true);
    config()->set('ocr.ensemble.phase5.enabled', true);

    $user = User::factory()->create();
    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'sample ocr text for phase five skeleton comparison',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    $result = app(IntakeOcrEnsemblePhase5Service::class)->buildComparisonForIntake($intake);

    expect($result->wasNotImplemented())->toBeTrue()
        ->and($result->reason)->toBe('phase5_v1_skeleton');
});

test('phase5 result helpers', function () {
    $empty = OcrComparisonTable::empty(OcrComparisonAuditMeta::skeleton(1));

    expect(Phase5ComparisonResult::skipped('x')->wasSkipped())->toBeTrue()
        ->and(Phase5ComparisonResult::notImplemented('y')->wasNotImplemented())->toBeTrue()
        ->and(Phase5ComparisonResult::empty('z', $empty)->wasEmpty())->toBeTrue()
        ->and(Phase5ComparisonResult::resolved($empty)->wasResolved())->toBeTrue();
});

test('phase5 evidence loader and table builder skeletons are bound', function () {
    $loader = app(OcrEnsembleComparisonEvidenceLoaderInterface::class);
    $builder = app(OcrEnsembleComparisonTableBuilderInterface::class);

    $intake = new BiodataIntake;
    $intake->id = 44;

    $bundle = $loader->loadForIntake($intake);
    $table = $builder->build($bundle);

    expect($bundle->intakeId)->toBe(44)
        ->and($bundle->hasFieldResolution())->toBeFalse()
        ->and($table->columns)->toBe(OcrEnsemblePhase5Constants::TABLE_COLUMNS)
        ->and($table->isEmpty())->toBeTrue()
        ->and($table->audit->emptyState)->toBe(OcrEnsemblePhase5Constants::EMPTY_STATE_ENSEMBLE_NOT_RUN);
});

test('phase5 production files do not import benchmark classes or write paths', function () {
    $paths = [
        app_path('Services/Intake/IntakeOcrEnsemblePhase5Service.php'),
        app_path('Services/Intake/OcrEnsemble/OcrEnsemblePhase5Constants.php'),
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleComparisonEvidenceLoader.php'),
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleComparisonTableBuilder.php'),
        app_path('Services/Intake/OcrEnsemble/Data/OcrComparisonTable.php'),
        app_path('Services/Intake/OcrEnsemble/Data/OcrComparisonFieldRow.php'),
        app_path('Services/Intake/OcrEnsemble/Data/OcrComparisonAuditMeta.php'),
        app_path('Services/Intake/OcrEnsemble/Data/OcrComparisonEvidenceBundle.php'),
        app_path('Services/Intake/OcrEnsemble/Data/Phase5ComparisonResult.php'),
    ];

    foreach ($paths as $file) {
        $contents = (string) file_get_contents($file);
        expect($contents)->not->toContain('OcrEnsembleBenchmark')
            ->and($contents)->not->toContain('->save(')
            ->and($contents)->not->toContain('->update(')
            ->and($contents)->not->toContain('Http::')
            ->and($contents)->not->toContain('ParseIntakeJob');
    }
});
