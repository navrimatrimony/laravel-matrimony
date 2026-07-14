<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\User;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleComparisonEvidenceLoaderInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonAttemptSummary;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonEngineEvidence;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonEvidenceBundle;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase5Constants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function phase5bCreateIntake(array $overrides = []): BiodataIntake
{
    $user = User::factory()->create();

    return BiodataIntake::create(array_merge([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => 'sample ocr text for phase five evidence loader tests with enough length',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function phase5bCreateAttempt(BiodataIntake $intake, array $overrides = []): BiodataIntakeOcrAttempt
{
    return BiodataIntakeOcrAttempt::create(array_merge([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'tesseract raw text evidence',
        'is_primary' => false,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_SERVER,
    ], $overrides));
}

test('evidence loader with only tesseract keeps second and sarvam slots empty', function () {
    $intake = phase5bCreateIntake([
        'field_resolution_json' => FieldResolutionEnvelope::skeleton(1)->toArray(),
    ]);
    $attempt = phase5bCreateAttempt($intake, [
        'is_primary' => true,
        'raw_text' => 'only tesseract transcript',
        'selected_reason' => 'phase1_primary',
    ]);

    $bundle = app(OcrEnsembleComparisonEvidenceLoaderInterface::class)->loadForIntake($intake->fresh());

    expect($bundle->hasFieldResolution())->toBeTrue()
        ->and($bundle->attemptCount())->toBe(1)
        ->and($bundle->enginesPresent)->toBe([OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT])
        ->and($bundle->tesseract->present)->toBeTrue()
        ->and($bundle->tesseract->attempt?->attemptId)->toBe($attempt->id)
        ->and($bundle->tesseract->attempt?->isPrimary)->toBeTrue()
        ->and($bundle->tesseract->attempt?->rawText)->toBe('only tesseract transcript')
        ->and($bundle->secondOcr->present)->toBeFalse()
        ->and($bundle->secondOcr->attempt)->toBeNull()
        ->and($bundle->sarvam->present)->toBeFalse()
        ->and($bundle->sarvam->attempt)->toBeNull()
        ->and($bundle->primaryAttempt?->attemptId)->toBe($attempt->id)
        ->and(count($bundle->comparisonEngines()))->toBe(3);
});

test('evidence loader with tesseract and sarvam leaves second ocr empty', function () {
    $intake = phase5bCreateIntake([
        'field_resolution_json' => FieldResolutionEnvelope::skeleton(2)->toArray(),
    ]);
    phase5bCreateAttempt($intake, [
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'is_primary' => true,
        'raw_text' => 'tesseract text',
    ]);
    $sarvam = phase5bCreateAttempt($intake, [
        'engine' => BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
        'source' => 'phase4_sarvam_judge',
        'is_primary' => false,
        'raw_text' => "religion : Hindu\nfull_name : Test",
        'engine_meta_json' => [
            'phase' => 'phase4_sarvam_judge',
            'trigger_field_names' => ['religion'],
        ],
        'prompt_version' => 'phase4_judge_v1',
    ]);

    $bundle = app(OcrEnsembleComparisonEvidenceLoaderInterface::class)->loadForIntake($intake->fresh());

    expect($bundle->enginesPresent)->toBe([
        OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
    ])
        ->and($bundle->tesseract->present)->toBeTrue()
        ->and($bundle->secondOcr->present)->toBeFalse()
        ->and($bundle->sarvam->present)->toBeTrue()
        ->and($bundle->sarvam->attempt?->attemptId)->toBe($sarvam->id)
        ->and($bundle->sarvam->attempt?->source)->toBe('phase4_sarvam_judge')
        ->and($bundle->sarvam->attempt?->engineMetaJson['phase'] ?? null)->toBe('phase4_sarvam_judge')
        ->and($bundle->attemptCount())->toBe(2);
});

test('evidence loader with all comparison engines populates every slot', function () {
    $intake = phase5bCreateIntake([
        'field_resolution_json' => FieldResolutionEnvelope::skeleton(3)->toArray(),
    ]);
    $tesseract = phase5bCreateAttempt($intake, [
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'is_primary' => true,
        'raw_text' => 'tesseract all engines',
    ]);
    $second = phase5bCreateAttempt($intake, [
        'engine' => OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR,
        'is_primary' => false,
        'raw_text' => 'second ocr transcript',
    ]);
    $sarvam = phase5bCreateAttempt($intake, [
        'engine' => BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
        'is_primary' => false,
        'raw_text' => 'sarvam transcript',
    ]);

    $bundle = app(OcrEnsembleComparisonEvidenceLoaderInterface::class)->loadForIntake($intake->fresh());

    expect($bundle->enginesPresent)->toBe([
        OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR,
        OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
    ])
        ->and($bundle->tesseract->attempt?->attemptId)->toBe($tesseract->id)
        ->and($bundle->secondOcr->attempt?->attemptId)->toBe($second->id)
        ->and($bundle->sarvam->attempt?->attemptId)->toBe($sarvam->id)
        ->and($bundle->attemptCount())->toBe(3)
        ->and($bundle->primaryAttempt?->attemptId)->toBe($tesseract->id);
});

test('evidence loader allows missing field_resolution_json', function () {
    $intake = phase5bCreateIntake(['field_resolution_json' => null]);
    phase5bCreateAttempt($intake, ['is_primary' => true]);

    $bundle = app(OcrEnsembleComparisonEvidenceLoaderInterface::class)->loadForIntake($intake->fresh());

    expect($bundle->fieldResolutionJson)->toBeNull()
        ->and($bundle->hasFieldResolution())->toBeFalse()
        ->and($bundle->tesseract->present)->toBeTrue()
        ->and($bundle->secondOcr->present)->toBeFalse()
        ->and($bundle->sarvam->present)->toBeFalse();
});

test('evidence loader with no ocr attempts still returns empty engine slots', function () {
    $intake = phase5bCreateIntake([
        'field_resolution_json' => FieldResolutionEnvelope::skeleton(9)->toArray(),
    ]);

    $bundle = app(OcrEnsembleComparisonEvidenceLoaderInterface::class)->loadForIntake($intake->fresh());

    expect($bundle->attemptCount())->toBe(0)
        ->and($bundle->attemptSummaries)->toBe([])
        ->and($bundle->enginesPresent)->toBe([])
        ->and($bundle->primaryAttempt)->toBeNull()
        ->and($bundle->tesseract->present)->toBeFalse()
        ->and($bundle->secondOcr->present)->toBeFalse()
        ->and($bundle->sarvam->present)->toBeFalse()
        ->and($bundle->hasFieldResolution())->toBeTrue()
        ->and(array_keys($bundle->toArray()['engines']))->toBe(['tesseract', 'second_ocr', 'sarvam']);
});

test('evidence DTOs are immutable array round trips and deep copy field resolution', function () {
    $intake = phase5bCreateIntake([
        'field_resolution_json' => [
            '_meta' => ['intake_id' => 99],
            'fields' => ['religion' => ['final' => 'Hindu']],
        ],
    ]);
    phase5bCreateAttempt($intake, [
        'is_primary' => true,
        'raw_text' => 'immutable check',
    ]);

    $fresh = $intake->fresh();
    $bundle = app(OcrEnsembleComparisonEvidenceLoaderInterface::class)->loadForIntake($fresh);

    expect(OcrComparisonEvidenceBundle::fromArray($bundle->toArray())->toArray())->toBe($bundle->toArray());

    // Model cast mutation must not affect the already-loaded deep-copied bundle.
    $modelJson = $fresh->field_resolution_json;
    $modelJson['fields']['religion']['final'] = 'MUTATED';
    $fresh->field_resolution_json = $modelJson;

    expect($fresh->field_resolution_json['fields']['religion']['final'])->toBe('MUTATED')
        ->and($bundle->fieldResolutionJson['fields']['religion']['final'])->toBe('Hindu');

    $summary = new OcrComparisonAttemptSummary(
        attemptId: 1,
        engine: OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        source: null,
        status: 'success',
        isPrimary: true,
        rawText: 'x',
        engineMetaJson: null,
        qualityScore: null,
        durationMs: null,
        selectedReason: null,
        preprocessingVersion: null,
        promptVersion: null,
        parserVersion: null,
    );
    $engine = OcrComparisonEngineEvidence::fromAttempt(
        OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        OcrEnsemblePhase5Constants::COLUMN_TESSERACT,
        $summary,
    );

    expect(OcrComparisonAttemptSummary::fromArray($summary->toArray())->toArray())->toBe($summary->toArray())
        ->and(OcrComparisonEngineEvidence::fromArray($engine->toArray())->toArray())->toBe($engine->toArray())
        ->and(OcrComparisonEngineEvidence::empty(
            OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR,
            OcrEnsemblePhase5Constants::COLUMN_SECOND_OCR,
        )->present)->toBeFalse();
});

test('evidence loader production files do not import benchmark classes or write paths', function () {
    $paths = [
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleComparisonEvidenceLoader.php'),
        app_path('Services/Intake/OcrEnsemble/Data/OcrComparisonAttemptSummary.php'),
        app_path('Services/Intake/OcrEnsemble/Data/OcrComparisonEngineEvidence.php'),
        app_path('Services/Intake/OcrEnsemble/Data/OcrComparisonEvidenceBundle.php'),
    ];

    foreach ($paths as $file) {
        $contents = (string) file_get_contents($file);
        expect($contents)->not->toContain('OcrEnsembleBenchmark')
            ->and($contents)->not->toContain('->save(')
            ->and($contents)->not->toContain('->update(')
            ->and($contents)->not->toContain('->create(')
            ->and($contents)->not->toContain('Http::')
            ->and($contents)->not->toContain('ParseIntakeJob')
            ->and($contents)->not->toContain('OcrComparisonTable')
            ->and($contents)->not->toContain('OcrComparisonFieldRow');
    }
});
