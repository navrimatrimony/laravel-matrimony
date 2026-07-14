<?php

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeRequestBuilderInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionMeta;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeRequest;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeTriggerReport;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase4Constants;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  array<string, FieldResolutionFieldRecord>  $overrides
 */
function phase4cEnvelope(array $overrides = [], int $intakeId = 840): FieldResolutionEnvelope
{
    $fields = [];
    foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
        $fields[$fieldKey] = FieldResolutionFieldRecord::missingSkeleton('phase4c_missing');
    }
    foreach ($overrides as $fieldKey => $record) {
        $fields[$fieldKey] = $record;
    }

    return new FieldResolutionEnvelope(
        meta: new FieldResolutionMeta(
            schemaVersion: OcrEnsemblePhase3Constants::SCHEMA_VERSION,
            pipelineVersion: OcrEnsemblePhase3Constants::PIPELINE_VERSION,
            resolvedAt: '2026-01-01T00:00:00+00:00',
            intakeId: $intakeId,
            attemptCount: 2,
            enginesPresent: [
                OcrEnsemblePhase3Constants::ENGINE_SECOND_OCR,
                OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR,
            ],
            voteMode: OcrEnsemblePhase3Constants::VOTE_MODE_MULTI_ENGINE,
            assemblyVersion: OcrEnsemblePhase3Constants::ASSEMBLY_VERSION,
        ),
        fields: $fields,
    );
}

function phase4cMissingRecord(): FieldResolutionFieldRecord
{
    return FieldResolutionFieldRecord::missingSkeleton('phase4c_missing');
}

function phase4cConflictNameRecord(): FieldResolutionFieldRecord
{
    return new FieldResolutionFieldRecord(
        final: null,
        status: OcrEnsemblePhase3Constants::FIELD_STATUS_CONFLICT,
        source: OcrEnsemblePhase3Constants::FIELD_SOURCE_VOTE,
        winningEngine: null,
        confidence: null,
        reason: 'name_engines_disagree',
        candidates: [
            OcrEnsemblePhase3Constants::ENGINE_SECOND_OCR => 'श्याम शर्मा',
            OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => 'राम शर्मा',
        ],
        normalized: [
            OcrEnsemblePhase3Constants::ENGINE_SECOND_OCR => 'श्याम शर्मा',
            OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => 'राम शर्मा',
        ],
        validator: [
            'passed' => false,
            'code' => 'conflict_unresolved',
            'detail' => null,
        ],
    );
}

function phase4cTriggerReport(array $triggeredFields): SarvamJudgeTriggerReport
{
    return new SarvamJudgeTriggerReport(
        shouldInvokeSarvam: $triggeredFields !== [],
        triggeredFields: $triggeredFields,
        evaluatedTriggerFields: OcrEnsemblePhase4Constants::TRIGGER_FIELDS,
        skipReason: $triggeredFields === [] ? 'no_triggers' : null,
        unresolvedCount: count($triggeredFields),
        conflictingCount: 0,
        reasons: array_values($triggeredFields),
    );
}

function phase4cSampleOcr(): string
{
    return implode("\n", [
        'मुलाचे नाव : राम शर्मा',
        'जन्म तारीख :',
        'मोबाईल : 98xxx',
        'धर्म :',
        'लिंग : पुरुष',
        'शिक्षण : BE',
    ]);
}

test('single trigger builds request with only that field', function () {
    $request = app(OcrEnsembleSarvamJudgeRequestBuilderInterface::class)->build(
        phase4cTriggerReport([
            'date_of_birth' => OcrEnsemblePhase4Constants::TRIGGER_REASON_DOB_MISSING,
        ]),
        phase4cEnvelope(['date_of_birth' => phase4cMissingRecord()]),
        phase4cSampleOcr(),
    );

    expect($request->isEmpty())->toBeFalse()
        ->and($request->fieldNames())->toBe(['date_of_birth'])
        ->and($request->triggerReasons)->toBe([
            'date_of_birth' => OcrEnsemblePhase4Constants::TRIGGER_REASON_DOB_MISSING,
        ])
        ->and($request->fields[0]->fieldName)->toBe('date_of_birth')
        ->and($request->fields[0]->triggerReason)->toBe(OcrEnsemblePhase4Constants::TRIGGER_REASON_DOB_MISSING)
        ->and($request->fields[0]->resolvedValue)->toBeNull()
        ->and($request->fields[0]->ocrSnippets)->toContain('जन्म तारीख :')
        ->and($request->intakeId)->toBe(840)
        ->and($request->schemaVersion)->toBe(OcrEnsemblePhase4Constants::SCHEMA_VERSION);
});

test('multiple triggers are ordered by frozen trigger field list', function () {
    $request = app(OcrEnsembleSarvamJudgeRequestBuilderInterface::class)->build(
        phase4cTriggerReport([
            'religion' => OcrEnsemblePhase4Constants::TRIGGER_REASON_RELIGION_MISSING,
            'full_name' => OcrEnsemblePhase4Constants::TRIGGER_REASON_NAME_CONFLICT,
            'primary_contact_number' => OcrEnsemblePhase4Constants::TRIGGER_REASON_MOBILE_MISSING,
        ]),
        phase4cEnvelope([
            'full_name' => phase4cConflictNameRecord(),
            'primary_contact_number' => phase4cMissingRecord(),
            'religion' => phase4cMissingRecord(),
        ]),
        phase4cSampleOcr(),
    );

    expect($request->fieldNames())->toBe([
        'full_name',
        'primary_contact_number',
        'religion',
    ])->and(array_keys($request->triggerReasons))->toBe([
        'full_name',
        'primary_contact_number',
        'religion',
    ]);
});

test('empty trigger report produces empty request without crash', function () {
    $request = app(OcrEnsembleSarvamJudgeRequestBuilderInterface::class)->build(
        SarvamJudgeTriggerReport::empty('no_triggers'),
        phase4cEnvelope(),
        phase4cSampleOcr(),
    );

    expect($request->isEmpty())->toBeTrue()
        ->and($request->fields)->toBe([])
        ->and($request->triggerReasons)->toBe([])
        ->and($request->intakeId)->toBe(840);
});

test('request never duplicates field entries', function () {
    $request = app(OcrEnsembleSarvamJudgeRequestBuilderInterface::class)->build(
        phase4cTriggerReport([
            'date_of_birth' => OcrEnsemblePhase4Constants::TRIGGER_REASON_DOB_MISSING,
            'religion' => OcrEnsemblePhase4Constants::TRIGGER_REASON_RELIGION_MISSING,
        ]),
        phase4cEnvelope(),
        phase4cSampleOcr(),
    );

    expect($request->fieldNames())->toBe(['date_of_birth', 'religion'])
        ->and(count($request->fieldNames()))->toBe(count(array_unique($request->fieldNames())));
});

test('serialization is deterministic across builds', function () {
    $trigger = phase4cTriggerReport([
        'full_name' => OcrEnsemblePhase4Constants::TRIGGER_REASON_NAME_CONFLICT,
        'date_of_birth' => OcrEnsemblePhase4Constants::TRIGGER_REASON_DOB_MISSING,
    ]);
    $envelope = phase4cEnvelope([
        'full_name' => phase4cConflictNameRecord(),
        'date_of_birth' => phase4cMissingRecord(),
    ]);
    $ocr = phase4cSampleOcr();
    $builder = app(OcrEnsembleSarvamJudgeRequestBuilderInterface::class);

    $first = $builder->build($trigger, $envelope, $ocr);
    $second = $builder->build($trigger, $envelope, $ocr);

    expect($first->toCanonicalJson())->toBe($second->toCanonicalJson())
        ->and($first->toArray())->toBe($second->toArray())
        ->and(SarvamJudgeRequest::fromArray($first->toArray())->toArray())->toBe($first->toArray());
});

test('candidates and normalized maps are stably sorted by engine key', function () {
    $request = app(OcrEnsembleSarvamJudgeRequestBuilderInterface::class)->build(
        phase4cTriggerReport([
            'full_name' => OcrEnsemblePhase4Constants::TRIGGER_REASON_NAME_CONFLICT,
        ]),
        phase4cEnvelope(['full_name' => phase4cConflictNameRecord()]),
        phase4cSampleOcr(),
    );

    $field = $request->fields[0];
    expect(array_keys($field->candidates))->toBe([
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR,
        OcrEnsemblePhase3Constants::ENGINE_SECOND_OCR,
    ])->and(array_keys($field->normalized))->toBe([
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR,
        OcrEnsemblePhase3Constants::ENGINE_SECOND_OCR,
    ])->and($field->engineMetadata['candidate_engines'])->toBe([
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR,
        OcrEnsemblePhase3Constants::ENGINE_SECOND_OCR,
    ]);
});

test('unknown trigger fields outside frozen list are ignored', function () {
    $request = app(OcrEnsembleSarvamJudgeRequestBuilderInterface::class)->build(
        phase4cTriggerReport([
            'gender' => 'should_never_trigger',
            'education' => 'ignored',
            'date_of_birth' => OcrEnsemblePhase4Constants::TRIGGER_REASON_DOB_MISSING,
            'totally_unknown' => 'ignored',
        ]),
        phase4cEnvelope(),
        phase4cSampleOcr(),
    );

    expect($request->fieldNames())->toBe(['date_of_birth'])
        ->and($request->triggerReasons)->not->toHaveKey('gender')
        ->and($request->triggerReasons)->not->toHaveKey('education')
        ->and($request->triggerReasons)->not->toHaveKey('totally_unknown');
});

test('null and missing envelope field records are handled safely', function () {
    $envelope = new FieldResolutionEnvelope(
        meta: FieldResolutionMeta::skeleton(841),
        fields: [],
    );

    $request = app(OcrEnsembleSarvamJudgeRequestBuilderInterface::class)->build(
        phase4cTriggerReport([
            'religion' => OcrEnsemblePhase4Constants::TRIGGER_REASON_RELIGION_MISSING,
        ]),
        $envelope,
        '',
    );

    expect($request->fields)->toHaveCount(1)
        ->and($request->fields[0]->resolvedValue)->toBeNull()
        ->and($request->fields[0]->normalizedValue)->toBeNull()
        ->and($request->fields[0]->status)->toBe(OcrEnsemblePhase3Constants::FIELD_STATUS_MISSING)
        ->and($request->fields[0]->ocrSnippets)->toBe([])
        ->and($request->fields[0]->validator['passed'])->toBeFalse();
});

test('request excludes unrelated envelope fields and meta timestamps', function () {
    $request = app(OcrEnsembleSarvamJudgeRequestBuilderInterface::class)->build(
        phase4cTriggerReport([
            'full_name' => OcrEnsemblePhase4Constants::TRIGGER_REASON_NAME_CONFLICT,
        ]),
        phase4cEnvelope(['full_name' => phase4cConflictNameRecord()]),
        phase4cSampleOcr(),
    );

    $json = $request->toCanonicalJson();
    $array = $request->toArray();

    expect($array)->not->toHaveKey('parsed_json')
        ->and($array)->not->toHaveKey('resolved_at')
        ->and($json)->not->toContain('शिक्षण')
        ->and($json)->not->toContain('gender')
        ->and($json)->not->toContain('2026-01-01T00:00:00')
        ->and($request->fieldNames())->not->toContain('gender')
        ->and($request->fieldNames())->not->toContain('education');
});

test('ocr snippets include label lines and candidate value matches', function () {
    $request = app(OcrEnsembleSarvamJudgeRequestBuilderInterface::class)->build(
        phase4cTriggerReport([
            'full_name' => OcrEnsemblePhase4Constants::TRIGGER_REASON_NAME_CONFLICT,
        ]),
        phase4cEnvelope(['full_name' => phase4cConflictNameRecord()]),
        phase4cSampleOcr(),
    );

    expect($request->fields[0]->ocrSnippets)->toContain('मुलाचे नाव : राम शर्मा');
});

test('request builder production files do not call http or client', function () {
    $paths = [
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleSarvamJudgeRequestBuilder.php'),
        app_path('Services/Intake/OcrEnsemble/Support/OcrEnsembleSarvamJudgeRequestSupport.php'),
        app_path('Services/Intake/OcrEnsemble/Data/SarvamJudgeRequest.php'),
        app_path('Services/Intake/OcrEnsemble/Data/SarvamJudgeRequestField.php'),
    ];

    foreach ($paths as $file) {
        $contents = (string) file_get_contents($file);
        expect($contents)->not->toContain('Http::')
            ->and($contents)->not->toContain('curl_')
            ->and($contents)->not->toContain('AiVisionExtractionService')
            ->and($contents)->not->toContain('OcrEnsembleSarvamJudgeClient')
            ->and($contents)->not->toContain('OcrEnsembleBenchmark');
    }
});
