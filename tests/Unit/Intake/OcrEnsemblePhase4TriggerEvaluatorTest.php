<?php

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeTriggerEvaluatorInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionMeta;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase4Constants;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  array<string, FieldResolutionFieldRecord>  $overrides
 */
function phase4TriggerEnvelope(array $overrides = []): FieldResolutionEnvelope
{
    $fields = [];
    foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
        $fields[$fieldKey] = FieldResolutionFieldRecord::missingSkeleton('phase4b_test_missing');
    }

    foreach ($overrides as $fieldKey => $record) {
        $fields[$fieldKey] = $record;
    }

    return new FieldResolutionEnvelope(
        meta: FieldResolutionMeta::skeleton(740),
        fields: $fields,
    );
}

function phase4ResolvedRecord(string $final, ?float $confidence = null): FieldResolutionFieldRecord
{
    return new FieldResolutionFieldRecord(
        final: $final,
        status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
        source: OcrEnsemblePhase3Constants::FIELD_SOURCE_VALIDATOR,
        winningEngine: OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR,
        confidence: $confidence,
        reason: 'phase4b_test_resolved',
        candidates: [OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => $final],
        normalized: [OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => $final],
        validator: [
            'passed' => true,
            'code' => 'test_match',
            'detail' => null,
        ],
    );
}

function phase4MissingRecord(): FieldResolutionFieldRecord
{
    return FieldResolutionFieldRecord::missingSkeleton('phase4b_test_missing');
}

function phase4ConflictRecord(string $a, string $b): FieldResolutionFieldRecord
{
    return new FieldResolutionFieldRecord(
        final: null,
        status: OcrEnsemblePhase3Constants::FIELD_STATUS_CONFLICT,
        source: OcrEnsemblePhase3Constants::FIELD_SOURCE_VOTE,
        winningEngine: null,
        confidence: null,
        reason: 'phase4b_test_conflict',
        candidates: [
            OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => $a,
            OcrEnsemblePhase3Constants::ENGINE_SECOND_OCR => $b,
        ],
        normalized: [
            OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => $a,
            OcrEnsemblePhase3Constants::ENGINE_SECOND_OCR => $b,
        ],
        validator: [
            'passed' => false,
            'code' => 'conflict_unresolved',
            'detail' => null,
        ],
    );
}

/**
 * Four trigger fields resolved; other structured fields left missing.
 *
 * @return array<string, FieldResolutionFieldRecord>
 */
function phase4CriticalResolvedOverrides(): array
{
    return [
        'full_name' => phase4ResolvedRecord('अविनाश अर्जुन खोडवे'),
        'date_of_birth' => phase4ResolvedRecord('1992-01-04'),
        'primary_contact_number' => phase4ResolvedRecord('9876543210'),
        'religion' => phase4ResolvedRecord('Hindu'),
    ];
}

test('fully resolved trigger fields do not invoke sarvam judge', function () {
    $report = app(OcrEnsembleSarvamJudgeTriggerEvaluatorInterface::class)
        ->evaluate(phase4TriggerEnvelope(phase4CriticalResolvedOverrides()));

    expect($report->shouldJudge())->toBeFalse()
        ->and($report->shouldInvokeSarvam)->toBeFalse()
        ->and($report->triggerFields())->toBe([])
        ->and($report->reasons)->toBe([])
        ->and($report->unresolvedCount)->toBe(0)
        ->and($report->conflictingCount)->toBe(0)
        ->and($report->skipReason)->toBe('no_triggers')
        ->and($report->evaluatedTriggerFields)->toBe(OcrEnsemblePhase4Constants::TRIGGER_FIELDS);
});

test('missing full_name triggers sarvam judge', function () {
    $overrides = phase4CriticalResolvedOverrides();
    $overrides['full_name'] = phase4MissingRecord();

    $report = app(OcrEnsembleSarvamJudgeTriggerEvaluatorInterface::class)
        ->evaluate(phase4TriggerEnvelope($overrides));

    expect($report->shouldJudge())->toBeTrue()
        ->and($report->triggeredFields)->toHaveKey('full_name')
        ->and($report->triggeredFields['full_name'])->toBe(OcrEnsemblePhase4Constants::TRIGGER_REASON_NAME_CONFLICT)
        ->and($report->triggerFields())->toBe(['full_name'])
        ->and($report->unresolvedCount)->toBe(1);
});

test('missing date_of_birth triggers sarvam judge', function () {
    $overrides = phase4CriticalResolvedOverrides();
    $overrides['date_of_birth'] = phase4MissingRecord();

    $report = app(OcrEnsembleSarvamJudgeTriggerEvaluatorInterface::class)
        ->evaluate(phase4TriggerEnvelope($overrides));

    expect($report->shouldJudge())->toBeTrue()
        ->and($report->triggeredFields['date_of_birth'])->toBe(OcrEnsemblePhase4Constants::TRIGGER_REASON_DOB_MISSING)
        ->and($report->reasons)->toContain(OcrEnsemblePhase4Constants::TRIGGER_REASON_DOB_MISSING);
});

test('missing primary_contact_number triggers sarvam judge', function () {
    $overrides = phase4CriticalResolvedOverrides();
    $overrides['primary_contact_number'] = phase4MissingRecord();

    $report = app(OcrEnsembleSarvamJudgeTriggerEvaluatorInterface::class)
        ->evaluate(phase4TriggerEnvelope($overrides));

    expect($report->shouldJudge())->toBeTrue()
        ->and($report->triggeredFields['primary_contact_number'])->toBe(OcrEnsemblePhase4Constants::TRIGGER_REASON_MOBILE_MISSING);
});

test('missing religion triggers sarvam judge', function () {
    $overrides = phase4CriticalResolvedOverrides();
    $overrides['religion'] = phase4MissingRecord();

    $report = app(OcrEnsembleSarvamJudgeTriggerEvaluatorInterface::class)
        ->evaluate(phase4TriggerEnvelope($overrides));

    expect($report->shouldJudge())->toBeTrue()
        ->and($report->triggeredFields['religion'])->toBe(OcrEnsemblePhase4Constants::TRIGGER_REASON_RELIGION_MISSING);
});

test('gender unresolved alone does not trigger sarvam judge', function () {
    $overrides = phase4CriticalResolvedOverrides();
    $overrides['gender'] = phase4MissingRecord();

    $report = app(OcrEnsembleSarvamJudgeTriggerEvaluatorInterface::class)
        ->evaluate(phase4TriggerEnvelope($overrides));

    expect($report->shouldJudge())->toBeFalse()
        ->and($report->triggeredFields)->toBe([])
        ->and($report->skipReason)->toBe('no_triggers');
});

test('non trigger structured fields unresolved alone do not invoke judge', function () {
    $overrides = phase4CriticalResolvedOverrides();
    foreach ([
        'education', 'occupation', 'height', 'income', 'caste', 'sub_caste',
        'village', 'taluka', 'district', 'state', 'marital_status',
    ] as $fieldKey) {
        $overrides[$fieldKey] = phase4MissingRecord();
    }

    $report = app(OcrEnsembleSarvamJudgeTriggerEvaluatorInterface::class)
        ->evaluate(phase4TriggerEnvelope($overrides));

    expect($report->shouldJudge())->toBeFalse()
        ->and($report->triggerFields())->toBe([]);
});

test('multiple missing trigger fields are all listed', function () {
    $overrides = phase4CriticalResolvedOverrides();
    $overrides['date_of_birth'] = phase4MissingRecord();
    $overrides['religion'] = phase4MissingRecord();

    $report = app(OcrEnsembleSarvamJudgeTriggerEvaluatorInterface::class)
        ->evaluate(phase4TriggerEnvelope($overrides));

    expect($report->shouldJudge())->toBeTrue()
        ->and($report->triggerFields())->toBe(['date_of_birth', 'religion'])
        ->and($report->reasons)->toBe([
            OcrEnsemblePhase4Constants::TRIGGER_REASON_DOB_MISSING,
            OcrEnsemblePhase4Constants::TRIGGER_REASON_RELIGION_MISSING,
        ])
        ->and($report->unresolvedCount)->toBe(2);
});

test('name conflict status triggers with name_conflict reason', function () {
    $overrides = phase4CriticalResolvedOverrides();
    $overrides['full_name'] = phase4ConflictRecord('राम शर्मा', 'श्याम शर्मा');

    $report = app(OcrEnsembleSarvamJudgeTriggerEvaluatorInterface::class)
        ->evaluate(phase4TriggerEnvelope($overrides));

    expect($report->shouldJudge())->toBeTrue()
        ->and($report->triggeredFields['full_name'])->toBe(OcrEnsemblePhase4Constants::TRIGGER_REASON_NAME_CONFLICT)
        ->and($report->conflictingCount)->toBe(1)
        ->and($report->unresolvedCount)->toBe(1);
});

test('unknown field in envelope is ignored', function () {
    $overrides = phase4CriticalResolvedOverrides();
    $envelope = phase4TriggerEnvelope($overrides);
    $fields = $envelope->fields;
    $fields['totally_unknown_field'] = phase4MissingRecord();

    $report = app(OcrEnsembleSarvamJudgeTriggerEvaluatorInterface::class)
        ->evaluate(new FieldResolutionEnvelope(meta: $envelope->meta, fields: $fields));

    expect($report->shouldJudge())->toBeFalse()
        ->and($report->triggeredFields)->not->toHaveKey('totally_unknown_field');
});

test('empty envelope fields does not crash and treats trigger fields as missing', function () {
    $envelope = new FieldResolutionEnvelope(
        meta: FieldResolutionMeta::skeleton(741),
        fields: [],
    );

    $report = app(OcrEnsembleSarvamJudgeTriggerEvaluatorInterface::class)->evaluate($envelope);

    expect($report->shouldJudge())->toBeTrue()
        ->and($report->triggerFields())->toBe(OcrEnsemblePhase4Constants::TRIGGER_FIELDS)
        ->and($report->unresolvedCount)->toBe(4)
        ->and($report->conflictingCount)->toBe(0);
});

test('null final value on resolved status is handled safely as trigger', function () {
    $overrides = phase4CriticalResolvedOverrides();
    $overrides['date_of_birth'] = new FieldResolutionFieldRecord(
        final: null,
        status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
        source: OcrEnsemblePhase3Constants::FIELD_SOURCE_VALIDATOR,
        winningEngine: OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR,
        confidence: null,
        reason: 'phase4b_null_final',
        candidates: [],
        normalized: [],
        validator: [
            'passed' => true,
            'code' => 'odd',
            'detail' => null,
        ],
    );

    $report = app(OcrEnsembleSarvamJudgeTriggerEvaluatorInterface::class)
        ->evaluate(phase4TriggerEnvelope($overrides));

    expect($report->shouldJudge())->toBeTrue()
        ->and($report->triggeredFields['date_of_birth'])->toBe(OcrEnsemblePhase4Constants::TRIGGER_REASON_DOB_MISSING);
});

test('blank final string is treated as missing', function () {
    $overrides = phase4CriticalResolvedOverrides();
    $overrides['religion'] = new FieldResolutionFieldRecord(
        final: '   ',
        status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
        source: OcrEnsemblePhase3Constants::FIELD_SOURCE_VALIDATOR,
        winningEngine: OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR,
        confidence: null,
        reason: 'phase4b_blank_final',
        candidates: [],
        normalized: [],
        validator: [
            'passed' => true,
            'code' => 'odd',
            'detail' => null,
        ],
    );

    $report = app(OcrEnsembleSarvamJudgeTriggerEvaluatorInterface::class)
        ->evaluate(phase4TriggerEnvelope($overrides));

    expect($report->shouldJudge())->toBeTrue()
        ->and($report->triggeredFields['religion'])->toBe(OcrEnsemblePhase4Constants::TRIGGER_REASON_RELIGION_MISSING);
});

test('validator failed on otherwise present value still triggers', function () {
    $overrides = phase4CriticalResolvedOverrides();
    $overrides['primary_contact_number'] = new FieldResolutionFieldRecord(
        final: '123',
        status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
        source: OcrEnsemblePhase3Constants::FIELD_SOURCE_VOTE,
        winningEngine: OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR,
        confidence: 0.9,
        reason: 'phase4b_validator_fail',
        candidates: [OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => '123'],
        normalized: [OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => '123'],
        validator: [
            'passed' => false,
            'code' => 'mobile_invalid',
            'detail' => null,
        ],
    );

    $report = app(OcrEnsembleSarvamJudgeTriggerEvaluatorInterface::class)
        ->evaluate(phase4TriggerEnvelope($overrides));

    expect($report->shouldJudge())->toBeTrue()
        ->and($report->triggeredFields['primary_contact_number'])->toBe(OcrEnsemblePhase4Constants::TRIGGER_REASON_MOBILE_MISSING);
});

test('religion resolved with passing validator does not trigger even when confidence is low', function () {
    $overrides = phase4CriticalResolvedOverrides();
    $overrides['religion'] = phase4ResolvedRecord('Hindu', 0.4);

    $report = app(OcrEnsembleSarvamJudgeTriggerEvaluatorInterface::class)
        ->evaluate(phase4TriggerEnvelope($overrides));

    expect($report->shouldJudge())->toBeFalse()
        ->and($report->triggeredFields)->toBe([]);
});

test('trigger evaluator has no http or sarvam client usage', function () {
    $path = app_path('Services/Intake/OcrEnsemble/OcrEnsembleSarvamJudgeTriggerEvaluator.php');
    $contents = (string) file_get_contents($path);

    expect($contents)->not->toContain('Http::')
        ->and($contents)->not->toContain('curl_')
        ->and($contents)->not->toContain('Guzzle')
        ->and($contents)->not->toContain('AiVisionExtractionService')
        ->and($contents)->not->toContain('OcrEnsembleSarvamJudgeClient')
        ->and($contents)->not->toContain('OcrEnsembleBenchmark');
});
