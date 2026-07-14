<?php

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleComparisonTableBuilderInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionMeta;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonAttemptSummary;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonEngineEvidence;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonEvidenceBundle;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonTable;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase5Constants;
use Tests\TestCase;

uses(TestCase::class);

function phase5cAttempt(string $engine, int $id = 1, bool $primary = false): OcrComparisonAttemptSummary
{
    return new OcrComparisonAttemptSummary(
        attemptId: $id,
        engine: $engine,
        source: null,
        status: 'success',
        isPrimary: $primary,
        rawText: $engine.' raw',
        engineMetaJson: null,
        qualityScore: null,
        durationMs: null,
        selectedReason: null,
        preprocessingVersion: null,
        promptVersion: null,
        parserVersion: null,
    );
}

function phase5cEngine(string $engineKey, string $column, ?OcrComparisonAttemptSummary $attempt): OcrComparisonEngineEvidence
{
    if ($attempt === null) {
        return OcrComparisonEngineEvidence::empty($engineKey, $column);
    }

    return OcrComparisonEngineEvidence::fromAttempt($engineKey, $column, $attempt);
}

/**
 * @param  array<string, FieldResolutionFieldRecord>  $fields
 * @param  list<string>  $enginesPresent
 * @param  list<OcrComparisonAttemptSummary>  $summaries
 */
function phase5cBundle(
    ?array $fields,
    array $enginesPresent,
    array $summaries,
    OcrComparisonEngineEvidence $tesseract,
    OcrComparisonEngineEvidence $secondOcr,
    OcrComparisonEngineEvidence $sarvam,
    int $intakeId = 501,
): OcrComparisonEvidenceBundle {
    $fr = null;
    if ($fields !== null) {
        $fr = (new FieldResolutionEnvelope(
            meta: FieldResolutionMeta::skeleton($intakeId),
            fields: $fields,
        ))->toArray();
    }

    return new OcrComparisonEvidenceBundle(
        intakeId: $intakeId,
        fieldResolutionJson: $fr,
        attemptSummaries: $summaries,
        enginesPresent: $enginesPresent,
        tesseract: $tesseract,
        secondOcr: $secondOcr,
        sarvam: $sarvam,
        primaryAttempt: $tesseract->attempt?->isPrimary ? $tesseract->attempt : null,
    );
}

function phase5cRecord(
    ?string $final,
    array $candidates,
    string $reason,
    ?string $winningEngine = null,
    string $status = OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
    string $source = OcrEnsemblePhase3Constants::FIELD_SOURCE_SINGLE_ENGINE,
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
            'passed' => true,
            'code' => 'ok',
            'detail' => null,
        ],
    );
}

function phase5cRowMap(OcrComparisonTable $table): array
{
    $map = [];
    foreach ($table->rows as $row) {
        $map[$row->fieldKey] = $row;
    }

    return $map;
}

test('table builder with only tesseract fills tesseract column and leaves others empty', function () {
    $tesseract = phase5cAttempt(OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT, 11, true);
    $fields = [
        'religion' => phase5cRecord(
            'Hindu',
            [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Hindu'],
            'single_engine_pass_through',
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        ),
        'full_name' => phase5cRecord(
            'Ram',
            [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Ram'],
            'single_engine_pass_through',
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        ),
    ];

    $bundle = phase5cBundle(
        $fields,
        [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT],
        [$tesseract],
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT, OcrEnsemblePhase5Constants::COLUMN_TESSERACT, $tesseract),
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR, OcrEnsemblePhase5Constants::COLUMN_SECOND_OCR, null),
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM, OcrEnsemblePhase5Constants::COLUMN_SARVAM, null),
    );

    $table = app(OcrEnsembleComparisonTableBuilderInterface::class)->build($bundle);
    $rows = phase5cRowMap($table);

    expect($table->columns)->toBe(OcrEnsemblePhase5Constants::TABLE_COLUMNS)
        ->and($rows['religion']->finalValue)->toBe('Hindu')
        ->and($rows['religion']->tesseractValue)->toBe('Hindu')
        ->and($rows['religion']->secondOcrValue)->toBeNull()
        ->and($rows['religion']->sarvamValue)->toBeNull()
        ->and($rows['religion']->reason)->toBe('single_engine_pass_through')
        ->and($table->audit->enginesPresent)->toBe([OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT])
        ->and($table->audit->emptyState)->toBeNull()
        ->and($table->audit->ensembleRan)->toBeTrue();
});

test('table builder with tesseract and sarvam populates those columns', function () {
    $tesseract = phase5cAttempt(OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT, 1, true);
    $sarvam = phase5cAttempt(OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM, 2);
    $fields = [
        'religion' => phase5cRecord(
            'Hindu',
            [
                OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Hidnu',
                OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM => 'Hindu',
            ],
            'sarvam_judge_merge',
            OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
            OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            'sarvam_judge',
        ),
    ];

    $bundle = phase5cBundle(
        $fields,
        [
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
            OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
        ],
        [$tesseract, $sarvam],
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT, OcrEnsemblePhase5Constants::COLUMN_TESSERACT, $tesseract),
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR, OcrEnsemblePhase5Constants::COLUMN_SECOND_OCR, null),
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM, OcrEnsemblePhase5Constants::COLUMN_SARVAM, $sarvam),
    );

    $row = phase5cRowMap(app(OcrEnsembleComparisonTableBuilderInterface::class)->build($bundle))['religion'];

    expect($row->finalValue)->toBe('Hindu')
        ->and($row->tesseractValue)->toBe('Hidnu')
        ->and($row->secondOcrValue)->toBeNull()
        ->and($row->sarvamValue)->toBe('Hindu')
        ->and($row->reason)->toBe('sarvam_judge_merge')
        ->and($row->source)->toBe('sarvam_judge');
});

test('table builder with all engines fills every comparison column', function () {
    $tesseract = phase5cAttempt(OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT, 1, true);
    $second = phase5cAttempt(OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR, 2);
    $sarvam = phase5cAttempt(OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM, 3);
    $fields = [
        'full_name' => phase5cRecord(
            'Avinash',
            [
                OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Avinas',
                OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR => 'Avinash',
                OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM => 'Avinash K',
            ],
            '2/2 agree after normalize',
            OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR,
            OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            OcrEnsemblePhase3Constants::FIELD_SOURCE_VOTE,
        ),
    ];

    $bundle = phase5cBundle(
        $fields,
        [
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
            OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR,
            OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
        ],
        [$tesseract, $second, $sarvam],
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT, OcrEnsemblePhase5Constants::COLUMN_TESSERACT, $tesseract),
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR, OcrEnsemblePhase5Constants::COLUMN_SECOND_OCR, $second),
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM, OcrEnsemblePhase5Constants::COLUMN_SARVAM, $sarvam),
    );

    $row = phase5cRowMap(app(OcrEnsembleComparisonTableBuilderInterface::class)->build($bundle))['full_name'];

    expect($row->finalValue)->toBe('Avinash')
        ->and($row->tesseractValue)->toBe('Avinas')
        ->and($row->secondOcrValue)->toBe('Avinash')
        ->and($row->sarvamValue)->toBe('Avinash K')
        ->and($row->reason)->toBe('2/2 agree after normalize');
});

test('table builder missing second OCR keeps second column empty even if candidates exist', function () {
    $tesseract = phase5cAttempt(OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT, 1, true);
    $fields = [
        'caste' => phase5cRecord(
            'Maratha',
            [
                OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'Maratha',
                OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR => 'SHOULD_NOT_SHOW',
            ],
            'single_engine_pass_through',
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        ),
    ];

    $bundle = phase5cBundle(
        $fields,
        [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT],
        [$tesseract],
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT, OcrEnsemblePhase5Constants::COLUMN_TESSERACT, $tesseract),
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR, OcrEnsemblePhase5Constants::COLUMN_SECOND_OCR, null),
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM, OcrEnsemblePhase5Constants::COLUMN_SARVAM, null),
    );

    $row = phase5cRowMap(app(OcrEnsembleComparisonTableBuilderInterface::class)->build($bundle))['caste'];

    expect($row->tesseractValue)->toBe('Maratha')
        ->and($row->secondOcrValue)->toBeNull()
        ->and($row->sarvamValue)->toBeNull();
});

test('table builder missing field_resolution_json still emits canonical rows with empty finals', function () {
    $bundle = OcrComparisonEvidenceBundle::empty(88);

    $table = app(OcrEnsembleComparisonTableBuilderInterface::class)->build($bundle);

    expect($table->fieldKeys())->toBe(OcrEnsemblePhase3Constants::STRUCTURED_FIELDS)
        ->and(count($table->rows))->toBe(16)
        ->and($table->audit->hasFieldResolutionJson)->toBeFalse()
        ->and($table->audit->ensembleRan)->toBeFalse()
        ->and($table->audit->emptyState)->toBe(OcrEnsemblePhase5Constants::EMPTY_STATE_LEGACY_INTAKE)
        ->and($table->rows[0]->finalValue)->toBeNull()
        ->and($table->rows[0]->tesseractValue)->toBeNull()
        ->and($table->rows[0]->reason)->toBe('missing_field_resolution');
});

test('table builder row order matches phase3 canonical structured fields', function () {
    $fr = FieldResolutionEnvelope::skeleton(77)->toArray();
    $bundle = new OcrComparisonEvidenceBundle(
        intakeId: 77,
        fieldResolutionJson: $fr,
        attemptSummaries: [],
        enginesPresent: [],
        tesseract: phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT, OcrEnsemblePhase5Constants::COLUMN_TESSERACT, null),
        secondOcr: phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR, OcrEnsemblePhase5Constants::COLUMN_SECOND_OCR, null),
        sarvam: phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM, OcrEnsemblePhase5Constants::COLUMN_SARVAM, null),
    );

    $table = app(OcrEnsembleComparisonTableBuilderInterface::class)->build($bundle);

    expect($table->fieldKeys())->toBe(OcrEnsemblePhase3Constants::STRUCTURED_FIELDS);
});

test('table builder DTOs round trip immutably', function () {
    $tesseract = phase5cAttempt(OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT, 9, true);
    $bundle = phase5cBundle(
        [
            'gender' => phase5cRecord(
                'male',
                [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'male'],
                'single_engine_pass_through',
                OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
            ),
        ],
        [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT],
        [$tesseract],
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT, OcrEnsemblePhase5Constants::COLUMN_TESSERACT, $tesseract),
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR, OcrEnsemblePhase5Constants::COLUMN_SECOND_OCR, null),
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM, OcrEnsemblePhase5Constants::COLUMN_SARVAM, null),
    );

    $table = app(OcrEnsembleComparisonTableBuilderInterface::class)->build($bundle);

    expect(OcrComparisonTable::fromArray($table->toArray())->toArray())->toBe($table->toArray());
});

test('table builder production files do not import benchmark classes or write paths', function () {
    $paths = [
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleComparisonTableBuilder.php'),
        app_path('Services/Intake/OcrEnsemble/Data/OcrComparisonTable.php'),
        app_path('Services/Intake/OcrEnsemble/Data/OcrComparisonFieldRow.php'),
        app_path('Services/Intake/OcrEnsemble/Data/OcrComparisonAuditMeta.php'),
    ];

    foreach ($paths as $file) {
        $contents = (string) file_get_contents($file);
        expect($contents)->not->toContain('OcrEnsembleBenchmark')
            ->and($contents)->not->toContain('->save(')
            ->and($contents)->not->toContain('->update(')
            ->and($contents)->not->toContain('->create(')
            ->and($contents)->not->toContain('Http::')
            ->and($contents)->not->toContain('ParseIntakeJob')
            ->and($contents)->not->toContain('SarvamJudgeClient')
            ->and($contents)->not->toContain('OcrEnsembleFieldVoter')
            ->and($contents)->not->toContain('OcrEnsembleFieldExtractor');
    }
});

test('sarvam winning final fills sarvam column when candidates omit sarvam', function () {
    $tesseract = phase5cAttempt(OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT, 1, true);
    $sarvam = phase5cAttempt(OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM, 2);
    $fields = [
        'date_of_birth' => phase5cRecord(
            '1998-04-15',
            [OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => null],
            'sarvam_judge_merge',
            OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
            OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            'sarvam_judge',
        ),
    ];

    $bundle = phase5cBundle(
        $fields,
        [
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
            OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
        ],
        [$tesseract, $sarvam],
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT, OcrEnsemblePhase5Constants::COLUMN_TESSERACT, $tesseract),
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR, OcrEnsemblePhase5Constants::COLUMN_SECOND_OCR, null),
        phase5cEngine(OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM, OcrEnsemblePhase5Constants::COLUMN_SARVAM, $sarvam),
    );

    $row = phase5cRowMap(app(OcrEnsembleComparisonTableBuilderInterface::class)->build($bundle))['date_of_birth'];

    expect($row->finalValue)->toBe('1998-04-15')
        ->and($row->sarvamValue)->toBe('1998-04-15')
        ->and($row->tesseractValue)->toBeNull();
});
