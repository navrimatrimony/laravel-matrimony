<?php

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeMergerInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionMeta;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeResponse;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeResponseField;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase4Constants;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  array<string, FieldResolutionFieldRecord>  $overrides
 */
function phase4eEnvelope(array $overrides = [], int $intakeId = 910): FieldResolutionEnvelope
{
    $fields = [];
    foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
        $fields[$fieldKey] = FieldResolutionFieldRecord::missingSkeleton('phase4e_missing');
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
            attemptCount: 1,
            enginesPresent: [OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR],
            voteMode: OcrEnsemblePhase3Constants::VOTE_MODE_SINGLE_ENGINE_PASS_THROUGH,
            assemblyVersion: OcrEnsemblePhase3Constants::ASSEMBLY_VERSION,
        ),
        fields: $fields,
    );
}

function phase4eResolved(string $final, ?float $confidence = 0.5): FieldResolutionFieldRecord
{
    return new FieldResolutionFieldRecord(
        final: $final,
        status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
        source: OcrEnsemblePhase3Constants::FIELD_SOURCE_VALIDATOR,
        winningEngine: OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR,
        confidence: $confidence,
        reason: 'phase4e_resolved',
        candidates: [OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => $final],
        normalized: [OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => $final],
        validator: [
            'passed' => true,
            'code' => 'test_match',
            'detail' => 'phase3',
        ],
    );
}

function phase4eMissing(): FieldResolutionFieldRecord
{
    return FieldResolutionFieldRecord::missingSkeleton('phase4e_missing');
}

/**
 * @param  list<array{field_name: string, value: ?string, confidence?: ?float, reason?: ?string}>  $fields
 */
function phase4eResponse(array $fields, bool $ok = true): SarvamJudgeResponse
{
    $dtoFields = [];
    foreach ($fields as $row) {
        $dtoFields[] = SarvamJudgeResponseField::fromArray($row);
    }

    if (! $ok) {
        return SarvamJudgeResponse::failure(
            outcome: SarvamJudgeResponse::OUTCOME_HTTP_ERROR,
            errorCode: 'http_500',
            attemptCount: 1,
        );
    }

    return SarvamJudgeResponse::success($dtoFields, attemptCount: 1);
}

test('no-op merge when response has no applicable improvements', function () {
    $envelope = phase4eEnvelope([
        'full_name' => phase4eResolved('राम शर्मा', 0.9),
        'date_of_birth' => phase4eResolved('1992-01-04', 0.9),
        'primary_contact_number' => phase4eResolved('9876543210', 0.9),
        'religion' => phase4eResolved('Hindu', 0.9),
    ]);
    $before = $envelope->toArray();

    $result = app(OcrEnsembleSarvamJudgeMergerInterface::class)->merge(
        $envelope,
        phase4eResponse([
            ['field_name' => 'religion', 'value' => 'Hinduism', 'confidence' => 0.4],
        ]),
    );

    expect($result->changed)->toBeFalse()
        ->and($result->updatedFields)->toBe([])
        ->and($result->envelope->toArray())->toBe($before)
        ->and($envelope->toArray())->toBe($before);
});

test('single field improvement replaces missing dob', function () {
    $envelope = phase4eEnvelope([
        'date_of_birth' => phase4eMissing(),
        'religion' => phase4eResolved('Hindu', 0.8),
    ]);

    $result = app(OcrEnsembleSarvamJudgeMergerInterface::class)->merge(
        $envelope,
        phase4eResponse([
            ['field_name' => 'date_of_birth', 'value' => '1992-01-04', 'confidence' => 0.95, 'reason' => 'vision'],
        ]),
    );

    $dob = $result->envelope->fields['date_of_birth'];

    expect($result->changed)->toBeTrue()
        ->and($result->updatedFields)->toBe(['date_of_birth'])
        ->and($dob->final)->toBe('1992-01-04')
        ->and($dob->source)->toBe(OcrEnsemblePhase4Constants::FIELD_SOURCE_SARVAM_JUDGE)
        ->and($dob->winningEngine)->toBe(OcrEnsemblePhase4Constants::ENGINE_SARVAM_JUDGE)
        ->and($dob->confidence)->toBe(0.95)
        ->and($dob->status)->toBe(OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED)
        ->and($dob->candidates)->toBe([])
        ->and($dob->normalized)->toBe([])
        ->and($dob->validator['code'])->toBe(OcrEnsemblePhase4Constants::VALIDATOR_CODE_SARVAM_JUDGE)
        ->and($dob->merge['previous_status'])->toBe(OcrEnsemblePhase3Constants::FIELD_STATUS_MISSING)
        ->and($result->envelope->fields['religion']->final)->toBe('Hindu');
});

test('multiple field improvements apply in frozen trigger order', function () {
    $envelope = phase4eEnvelope([
        'full_name' => phase4eMissing(),
        'date_of_birth' => phase4eMissing(),
        'religion' => phase4eMissing(),
    ]);

    $result = app(OcrEnsembleSarvamJudgeMergerInterface::class)->merge(
        $envelope,
        phase4eResponse([
            ['field_name' => 'religion', 'value' => 'Hindu', 'confidence' => 0.8],
            ['field_name' => 'full_name', 'value' => 'अविनाश खोडवे', 'confidence' => 0.7],
            ['field_name' => 'date_of_birth', 'value' => '1992-01-04', 'confidence' => 0.9],
        ]),
    );

    expect($result->updatedFields)->toBe(['full_name', 'date_of_birth', 'religion'])
        ->and($result->envelope->fields['full_name']->final)->toBe('अविनाश खोडवे')
        ->and($result->envelope->fields['date_of_birth']->final)->toBe('1992-01-04')
        ->and($result->envelope->fields['religion']->final)->toBe('Hindu');
});

test('lower sarvam confidence is ignored and phase3 preserved', function () {
    $phase3 = phase4eResolved('9876543210', 0.8);
    $envelope = phase4eEnvelope([
        'primary_contact_number' => $phase3,
    ]);

    $result = app(OcrEnsembleSarvamJudgeMergerInterface::class)->merge(
        $envelope,
        phase4eResponse([
            ['field_name' => 'primary_contact_number', 'value' => '9999999999', 'confidence' => 0.5],
        ]),
    );

    expect($result->changed)->toBeFalse()
        ->and($result->skippedFields['primary_contact_number'])->toBe('lower_or_equal_confidence')
        ->and($result->envelope->fields['primary_contact_number'])->toBe($phase3)
        ->and($result->envelope->fields['primary_contact_number']->final)->toBe('9876543210');
});

test('empty response is a no-op', function () {
    $envelope = phase4eEnvelope([
        'religion' => phase4eMissing(),
    ]);
    $before = $envelope->toArray();

    $result = app(OcrEnsembleSarvamJudgeMergerInterface::class)->merge(
        $envelope,
        SarvamJudgeResponse::success([], attemptCount: 1),
    );

    expect($result->changed)->toBeFalse()
        ->and($result->skippedFields['_merge'])->toBe('empty_response')
        ->and($result->envelope->toArray())->toBe($before);
});

test('unknown fields in response are ignored', function () {
    $envelope = phase4eEnvelope([
        'education' => phase4eMissing(),
        'religion' => phase4eMissing(),
    ]);

    $result = app(OcrEnsembleSarvamJudgeMergerInterface::class)->merge(
        $envelope,
        phase4eResponse([
            ['field_name' => 'education', 'value' => 'BE', 'confidence' => 0.99],
            ['field_name' => 'caste', 'value' => 'Maratha', 'confidence' => 0.99],
            ['field_name' => 'religion', 'value' => 'Hindu', 'confidence' => 0.8],
        ]),
    );

    expect($result->updatedFields)->toBe(['religion'])
        ->and($result->skippedFields['education'])->toBe('non_trigger_field')
        ->and($result->skippedFields['caste'])->toBe('non_trigger_field')
        ->and($result->envelope->fields['education']->final)->toBeNull();
});

test('gender is never modified even when present in response', function () {
    $gender = phase4eMissing();
    $envelope = phase4eEnvelope([
        'gender' => $gender,
        'religion' => phase4eMissing(),
    ]);

    $result = app(OcrEnsembleSarvamJudgeMergerInterface::class)->merge(
        $envelope,
        phase4eResponse([
            ['field_name' => 'gender', 'value' => 'male', 'confidence' => 0.99],
            ['field_name' => 'religion', 'value' => 'Hindu', 'confidence' => 0.8],
        ]),
    );

    expect($result->envelope->fields['gender'])->toBe($gender)
        ->and($result->envelope->fields['gender']->final)->toBeNull()
        ->and($result->skippedFields['gender'])->toBe('gender_never_modified')
        ->and($result->updatedFields)->toBe(['religion']);
});

test('merge is deterministic for identical inputs', function () {
    $envelope = phase4eEnvelope([
        'full_name' => phase4eMissing(),
        'date_of_birth' => phase4eMissing(),
    ]);
    $response = phase4eResponse([
        ['field_name' => 'date_of_birth', 'value' => '1992-01-04', 'confidence' => 0.9],
        ['field_name' => 'full_name', 'value' => 'Test Name', 'confidence' => 0.8],
    ]);
    $merger = app(OcrEnsembleSarvamJudgeMergerInterface::class);

    $first = $merger->merge($envelope, $response);
    $second = $merger->merge($envelope, $response);

    expect($first->toArray())->toBe($second->toArray());
});

test('input envelope remains immutable after merge', function () {
    $envelope = phase4eEnvelope([
        'religion' => phase4eMissing(),
        'education' => phase4eResolved('BE', 0.6),
    ]);
    $snapshot = $envelope->toArray();

    app(OcrEnsembleSarvamJudgeMergerInterface::class)->merge(
        $envelope,
        phase4eResponse([
            ['field_name' => 'religion', 'value' => 'Hindu', 'confidence' => 0.9],
        ]),
    );

    expect($envelope->toArray())->toBe($snapshot)
        ->and($envelope->fields['religion']->final)->toBeNull();
});

test('higher confidence preserves original candidates and normalized maps', function () {
    $phase3 = new FieldResolutionFieldRecord(
        final: null,
        status: OcrEnsemblePhase3Constants::FIELD_STATUS_CONFLICT,
        source: OcrEnsemblePhase3Constants::FIELD_SOURCE_VOTE,
        winningEngine: null,
        confidence: 0.4,
        reason: 'conflict',
        candidates: [
            OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => 'राम',
            OcrEnsemblePhase3Constants::ENGINE_SECOND_OCR => 'श्याम',
        ],
        normalized: [
            OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => 'राम',
            OcrEnsemblePhase3Constants::ENGINE_SECOND_OCR => 'श्याम',
        ],
        validator: [
            'passed' => false,
            'code' => 'conflict_unresolved',
            'detail' => null,
        ],
    );
    $envelope = phase4eEnvelope(['full_name' => $phase3]);

    $result = app(OcrEnsembleSarvamJudgeMergerInterface::class)->merge(
        $envelope,
        phase4eResponse([
            ['field_name' => 'full_name', 'value' => 'राम शर्मा', 'confidence' => 0.95],
        ]),
    );

    $merged = $result->envelope->fields['full_name'];

    expect($merged->candidates)->toBe($phase3->candidates)
        ->and($merged->normalized)->toBe($phase3->normalized)
        ->and($merged->final)->toBe('राम शर्मा')
        ->and($merged->merge['previous_confidence'])->toBe(0.4)
        ->and($merged->merge['previous_validator']['code'])->toBe('conflict_unresolved');
});

test('non trigger fields remain byte-for-byte identical in envelope array', function () {
    $education = phase4eResolved('BE Computer', 0.7);
    $envelope = phase4eEnvelope([
        'education' => $education,
        'occupation' => phase4eResolved('Engineer', 0.6),
        'religion' => phase4eMissing(),
    ]);
    $beforeEducation = $envelope->toArray()['fields']['education'];
    $beforeOccupation = $envelope->toArray()['fields']['occupation'];

    $result = app(OcrEnsembleSarvamJudgeMergerInterface::class)->merge(
        $envelope,
        phase4eResponse([
            ['field_name' => 'religion', 'value' => 'Hindu', 'confidence' => 0.9],
        ]),
    );

    expect($result->envelope->toArray()['fields']['education'])->toBe($beforeEducation)
        ->and($result->envelope->toArray()['fields']['occupation'])->toBe($beforeOccupation)
        ->and($result->envelope->fields['education'])->toBe($education);
});

test('failed response is a no-op', function () {
    $envelope = phase4eEnvelope(['religion' => phase4eMissing()]);

    $result = app(OcrEnsembleSarvamJudgeMergerInterface::class)->merge(
        $envelope,
        phase4eResponse([], ok: false),
    );

    expect($result->changed)->toBeFalse()
        ->and($result->skippedFields['_merge'])->toBe('response_not_ok');
});

test('merger production files do not import benchmark or persist', function () {
    $paths = [
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleSarvamJudgeMerger.php'),
        app_path('Services/Intake/OcrEnsemble/Data/SarvamJudgeMergeResult.php'),
    ];

    foreach ($paths as $file) {
        $contents = (string) file_get_contents($file);
        expect($contents)->not->toContain('OcrEnsembleBenchmark')
            ->and($contents)->not->toContain('->save(')
            ->and($contents)->not->toContain('->update(')
            ->and($contents)->not->toContain('ParseIntakeJob')
            ->and($contents)->not->toContain('Http::');
    }
});
