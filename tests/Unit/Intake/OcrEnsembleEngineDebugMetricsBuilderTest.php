<?php

use App\Services\Intake\OcrEnsemble\Data\OcrComparisonAttemptSummary;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonAuditMeta;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonFieldRow;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonTable;
use App\Services\Intake\OcrEnsemble\Data\Phase5ComparisonResult;
use App\Services\Intake\OcrEnsemble\OcrEnsembleEngineDebugMetricsBuilder;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase5Constants;

test('engine debug metrics count fields confidence time and judge', function () {
    $attempt = new OcrComparisonAttemptSummary(
        attemptId: 11,
        engine: OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
        source: 'server',
        status: 'success',
        isPrimary: true,
        rawText: 'sample',
        engineMetaJson: null,
        qualityScore: 0.82,
        durationMs: 4500,
        selectedReason: null,
        preprocessingVersion: null,
        promptVersion: null,
        parserVersion: null,
    );

    $rows = [
        new OcrComparisonFieldRow('full_name', 'Name', 'A', 'A', null, null, 'ok', 'resolved', 'single_engine'),
        new OcrComparisonFieldRow('date_of_birth', 'DOB', null, null, null, null, 'missing', 'missing', 'missing'),
        new OcrComparisonFieldRow('gender', 'Gender', 'male', 'male', null, null, 'ok', 'resolved', 'single_engine'),
        new OcrComparisonFieldRow('primary_contact_number', 'Mobile', '9', '9', null, null, 'ok', 'resolved', 'single_engine'),
        new OcrComparisonFieldRow('religion', 'Religion', 'Hindu', 'Hindu', null, null, 'ok', 'resolved', 'single_engine'),
    ];

    $table = new OcrComparisonTable(
        columns: OcrEnsemblePhase5Constants::TABLE_COLUMNS,
        rows: $rows,
        audit: OcrComparisonAuditMeta::skeleton(1),
    );

    $metrics = (new OcrEnsembleEngineDebugMetricsBuilder)->build(
        [$attempt],
        Phase5ComparisonResult::resolved($table),
    );

    expect($metrics)->toHaveCount(1)
        ->and($metrics[0]['engine'])->toBe(OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT)
        ->and($metrics[0]['confidence'])->toBe(0.82)
        ->and($metrics[0]['duration_ms'])->toBe(4500)
        ->and($metrics[0]['fields_found'])->toBe(4)
        ->and($metrics[0]['fields_missing'])->toBe(1)
        ->and($metrics[0]['critical_errors'])->toBe(1)
        ->and($metrics[0]['judge_used'])->toBeFalse();
});

test('engine debug metrics marks judge used when sarvam column filled', function () {
    $attempt = new OcrComparisonAttemptSummary(
        attemptId: 12,
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

    $rows = [
        new OcrComparisonFieldRow(
            OcrEnsemblePhase3Constants::CRITICAL_FIELDS[0],
            'Name',
            'A',
            'A',
            null,
            'A',
            'judge',
            'resolved',
            'sarvam_judge',
        ),
    ];

    $table = new OcrComparisonTable(
        columns: OcrEnsemblePhase5Constants::TABLE_COLUMNS,
        rows: $rows,
        audit: OcrComparisonAuditMeta::skeleton(2),
    );

    $metrics = (new OcrEnsembleEngineDebugMetricsBuilder)->build(
        [$attempt],
        Phase5ComparisonResult::resolved($table),
    );

    expect($metrics[0]['judge_used'])->toBeTrue();
});
