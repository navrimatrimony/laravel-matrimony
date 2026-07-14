<?php

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\User;
use App\Services\Intake\IntakeOcrEnsembleGate;
use App\Services\Intake\IntakeOcrEnsemblePhase5Service;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionMeta;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonTable;
use App\Services\Intake\OcrEnsemble\Data\Phase5ComparisonResult;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase5Constants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function phase5dEnableGate(): void
{
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, true);
    config()->set('ocr.ensemble.phase5.enabled', true);
}

function phase5dCreateIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'sample ocr text for phase five orchestration tests with enough characters',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function phase5dCreateAttempt(BiodataIntake $intake, array $overrides = []): BiodataIntakeOcrAttempt
{
    return BiodataIntakeOcrAttempt::create(array_merge([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'tesseract orchestration evidence',
        'is_primary' => false,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ], $overrides));
}

function phase5dEnvelopeWithReligion(int $intakeId, array $candidates, string $reason, ?string $winningEngine): array
{
    $fields = [];
    foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
        $fields[$fieldKey] = FieldResolutionFieldRecord::missingSkeleton('not_present');
    }

    $fields['religion'] = new FieldResolutionFieldRecord(
        final: 'Hindu',
        status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
        source: OcrEnsemblePhase3Constants::FIELD_SOURCE_SINGLE_ENGINE,
        winningEngine: $winningEngine,
        confidence: null,
        reason: $reason,
        candidates: $candidates,
        normalized: $candidates,
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

test('phase5 orchestration skips when gate disabled', function () {
    AdminSetting::setValue(IntakeOcrEnsembleGate::SETTING_KEY, false);
    config()->set('ocr.ensemble.phase5.enabled', true);

    $intake = phase5dCreateIntake();

    $result = app(IntakeOcrEnsemblePhase5Service::class)->buildComparisonForIntake($intake);

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->reason)->toBe('phase5_gate_disabled')
        ->and($result->table)->toBeNull();
});

test('phase5 orchestration returns empty when no evidence', function () {
    phase5dEnableGate();

    $intake = phase5dCreateIntake(['field_resolution_json' => null]);

    $result = app(IntakeOcrEnsemblePhase5Service::class)->buildComparisonForIntake($intake);

    expect($result->wasEmpty())->toBeTrue()
        ->and($result->reason)->toBe(OcrEnsemblePhase5Constants::EMPTY_STATE_LEGACY_INTAKE)
        ->and($result->table)->not->toBeNull()
        ->and($result->table->fieldKeys())->toBe(OcrEnsemblePhase3Constants::STRUCTURED_FIELDS)
        ->and($result->table->audit->emptyState)->toBe(OcrEnsemblePhase5Constants::EMPTY_STATE_LEGACY_INTAKE);
});

test('phase5 orchestration resolves when field_resolution_json only', function () {
    phase5dEnableGate();

    $intake = phase5dCreateIntake();
    $intake->field_resolution_json = phase5dEnvelopeWithReligion(
        (int) $intake->id,
        [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Hindu'],
        'single_engine_pass_through',
        OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
    );
    $intake->save();

    $result = app(IntakeOcrEnsemblePhase5Service::class)->buildComparisonForIntake($intake->fresh());

    expect($result->wasResolved())->toBeTrue()
        ->and($result->reason)->toBe('resolved')
        ->and($result->table)->not->toBeNull()
        ->and($result->table->audit->hasFieldResolutionJson)->toBeTrue()
        ->and($result->table->audit->enginesPresent)->toBe([])
        ->and($result->table->rows)->toHaveCount(16);

    $religion = collect($result->table->rows)->firstWhere('fieldKey', 'religion');
    expect($religion)->not->toBeNull()
        ->and($religion->finalValue)->toBe('Hindu')
        // Engine absent in evidence → column empty even if FR candidate exists.
        ->and($religion->tesseractValue)->toBeNull()
        ->and($religion->reason)->toBe('single_engine_pass_through');
});

test('phase5 orchestration resolves with all engines', function () {
    phase5dEnableGate();

    $intake = phase5dCreateIntake();
    phase5dCreateAttempt($intake, [
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'is_primary' => true,
        'raw_text' => 'tesseract all engines',
    ]);
    phase5dCreateAttempt($intake, [
        'engine' => OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR,
        'raw_text' => 'second ocr all engines',
    ]);
    phase5dCreateAttempt($intake, [
        'engine' => BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
        'source' => 'phase4_sarvam_judge',
        'raw_text' => 'sarvam all engines',
    ]);

    $intake->field_resolution_json = phase5dEnvelopeWithReligion(
        (int) $intake->id,
        [
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Hidnu',
            OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR => 'Hindu',
            OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM => 'Hindu',
        ],
        '2/2 agree after normalize',
        OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR,
    );
    $intake->save();

    $result = app(IntakeOcrEnsemblePhase5Service::class)->buildComparisonForIntake($intake->fresh());
    $religion = collect($result->table->rows)->firstWhere('fieldKey', 'religion');

    expect($result->wasResolved())->toBeTrue()
        ->and($result->table->audit->enginesPresent)->toBe([
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
            OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR,
            OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
        ])
        ->and($religion->finalValue)->toBe('Hindu')
        ->and($religion->tesseractValue)->toBe('Hidnu')
        ->and($religion->secondOcrValue)->toBe('Hindu')
        ->and($religion->sarvamValue)->toBe('Hindu')
        ->and($religion->reason)->toBe('2/2 agree after normalize');
});

test('phase5 orchestration output is deterministic across repeated calls', function () {
    phase5dEnableGate();

    $intake = phase5dCreateIntake();
    phase5dCreateAttempt($intake, ['is_primary' => true]);
    $intake->field_resolution_json = phase5dEnvelopeWithReligion(
        (int) $intake->id,
        [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Hindu'],
        'single_engine_pass_through',
        OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
    );
    $intake->save();
    $fresh = $intake->fresh();

    $service = app(IntakeOcrEnsemblePhase5Service::class);
    $first = $service->buildComparisonForIntake($fresh);
    $second = $service->buildComparisonForIntake($fresh);

    expect($first->wasResolved())->toBeTrue()
        ->and($second->wasResolved())->toBeTrue()
        ->and($first->outcome)->toBe($second->outcome)
        ->and($first->reason)->toBe($second->reason)
        ->and($first->table?->toArray())->toBe($second->table?->toArray())
        ->and($first->table->fieldKeys())->toBe(OcrEnsemblePhase3Constants::STRUCTURED_FIELDS);
});

test('phase5 orchestration result is immutable via table array round trip', function () {
    phase5dEnableGate();

    $intake = phase5dCreateIntake();
    $intake->field_resolution_json = FieldResolutionEnvelope::skeleton((int) $intake->id)->toArray();
    $intake->save();

    $result = app(IntakeOcrEnsemblePhase5Service::class)->buildComparisonForIntake($intake->fresh());
    $roundTrip = Phase5ComparisonResult::resolved(
        OcrComparisonTable::fromArray($result->table->toArray())
    );

    expect($result->wasResolved())->toBeTrue()
        ->and($roundTrip->wasResolved())->toBeTrue()
        ->and($roundTrip->table->toArray())->toBe($result->table->toArray());
});

test('phase5 orchestration production files do not import benchmark classes or write paths', function () {
    $contents = (string) file_get_contents(app_path('Services/Intake/IntakeOcrEnsemblePhase5Service.php'));

    expect($contents)->not->toContain('OcrEnsembleBenchmark')
        ->and($contents)->not->toContain('->save(')
        ->and($contents)->not->toContain('->update(')
        ->and($contents)->not->toContain('->create(')
        ->and($contents)->not->toContain('Http::')
        ->and($contents)->not->toContain('ParseIntakeJob')
        ->and($contents)->not->toContain('SarvamJudgeClient')
        ->and($contents)->not->toContain('field_resolution_json')
        ->and($contents)->toContain('evidenceLoader')
        ->and($contents)->toContain('tableBuilder');
});
