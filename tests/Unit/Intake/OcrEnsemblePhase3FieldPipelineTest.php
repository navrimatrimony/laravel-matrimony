<?php

use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\OcrEnsembleFieldNormalizer;
use App\Services\Intake\OcrEnsemble\OcrEnsembleFieldValidator;
use App\Services\Intake\OcrEnsemble\OcrEnsembleFieldVoter;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleFieldVoteSupport;
use Tests\TestCase;

uses(TestCase::class);

test('field normalizer canonicalizes dob mobile and gender per engine', function () {
    $normalizer = app(OcrEnsembleFieldNormalizer::class);
    $engines = [
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => [
            'full_name' => '* कु. अभिजीत अशोक पाटील',
            'date_of_birth' => '04/01/1992',
            'gender' => 'पुरुष',
            'primary_contact_number' => 'Call 9876543210',
            'height' => "5'6\"",
            'income' => 'Rs. 5,00,000',
            'marital_status' => 'अविवाहित',
        ],
    ];

    $normalized = [];
    foreach ($engines[OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR] as $field => $value) {
        $normalized[$field] = $normalizer->normalizeField($field, [
            OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => $value,
        ])[OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR];
    }

    expect($normalized['full_name'])->toBe('अभिजीत अशोक पाटील')
        ->and($normalized['date_of_birth'])->toBe('1992-01-04')
        ->and($normalized['gender'])->toBe('male')
        ->and($normalized['primary_contact_number'])->toBe('9876543210')
        ->and($normalized['height'])->toContain('168 cm')
        ->and($normalized['income'])->toBe('500,000')
        ->and($normalized['marital_status'])->toBe('अविवाहित');
});

test('field normalizer nulls invalid dob and gender values', function () {
    $normalizer = app(OcrEnsembleFieldNormalizer::class);

    $dob = $normalizer->normalizeField('date_of_birth', [
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => '09/99/9987',
    ]);
    $gender = $normalizer->normalizeField('gender', [
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => 'unknown-gender',
    ]);

    expect($dob[OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR])->toBeNull()
        ->and($gender[OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR])->toBeNull();
});

test('field voter single engine pass through resolves eligible values', function () {
    $normalized = [
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => '9876543210',
    ];

    $record = app(OcrEnsembleFieldVoter::class)->voteField(
        'primary_contact_number',
        $normalized,
        OcrEnsemblePhase3Constants::VOTE_MODE_SINGLE_ENGINE_PASS_THROUGH,
    );

    expect($record)->toBeInstanceOf(FieldResolutionFieldRecord::class)
        ->and($record->status)->toBe(OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED)
        ->and($record->source)->toBe(OcrEnsemblePhase3Constants::FIELD_SOURCE_SINGLE_ENGINE)
        ->and($record->winningEngine)->toBe(OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR)
        ->and($record->final)->toBe('9876543210')
        ->and($record->reason)->toBe('single_engine_pass_through')
        ->and($record->validator['code'])->toBe('pending_validation');
});

test('field voter filters null and ineligible candidates before voting', function () {
    $normalized = [
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => null,
        OcrEnsemblePhase3Constants::ENGINE_SECOND_OCR => '12345',
    ];

    $record = app(OcrEnsembleFieldVoter::class)->voteField(
        'primary_contact_number',
        $normalized,
        OcrEnsemblePhase3Constants::VOTE_MODE_SINGLE_ENGINE_PASS_THROUGH,
    );

    expect($record->status)->toBe(OcrEnsemblePhase3Constants::FIELD_STATUS_MISSING)
        ->and($record->reason)->toBe('no_eligible_candidate')
        ->and($record->final)->toBeNull();
});

test('field voter majority mode picks unanimous multi engine values', function () {
    $normalized = [
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => 'Hindu',
        OcrEnsemblePhase3Constants::ENGINE_SECOND_OCR => 'hindu',
    ];

    $record = app(OcrEnsembleFieldVoter::class)->voteField(
        'religion',
        $normalized,
        OcrEnsemblePhase3Constants::VOTE_MODE_MULTI_ENGINE,
    );

    expect($record->status)->toBe(OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED)
        ->and($record->reason)->toBe('majority_vote')
        ->and($record->final)->toBe('Hindu');
});

test('field voter majority mode reports disagreement for conflicting valid values', function () {
    $normalized = [
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => '9876543210',
        OcrEnsemblePhase3Constants::ENGINE_SECOND_OCR => '9123456789',
    ];

    $record = app(OcrEnsembleFieldVoter::class)->voteField(
        'primary_contact_number',
        $normalized,
        OcrEnsemblePhase3Constants::VOTE_MODE_MULTI_ENGINE,
    );

    expect($record->status)->toBe(OcrEnsemblePhase3Constants::FIELD_STATUS_MISSING)
        ->and($record->reason)->toBe('engine_disagreement');
});

test('field validator accepts valid mobile and rejects invalid mobile', function () {
    $validator = app(OcrEnsembleFieldValidator::class);

    $pass = $validator->validateField('primary_contact_number', [
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => '9876543210',
    ]);
    $fail = $validator->validateField('primary_contact_number', [
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => '1234567890',
    ]);

    expect($pass['passed'])->toBeTrue()
        ->and($pass['code'])->toBe('mobile_regex_valid')
        ->and($pass['final'])->toBe('9876543210')
        ->and($fail['passed'])->toBeFalse()
        ->and($fail['code'])->toBe('no_eligible_candidate');
});

test('field validator enforces dob age range and rejects invalid calendar dates', function () {
    $validator = app(OcrEnsembleFieldValidator::class);

    $pass = $validator->validateField('date_of_birth', [
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => '1992-01-04',
    ]);
    $fail = $validator->validateField('date_of_birth', [
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => '2009-01-01',
    ]);

    expect($pass['passed'])->toBeTrue()
        ->and($pass['final'])->toBe('1992-01-04')
        ->and($fail['passed'])->toBeFalse()
        ->and($fail['code'])->toBe('dob_age_out_of_range');
});

test('field validator treats missing gender as soft missing not fatal error shape', function () {
    $validator = app(OcrEnsembleFieldValidator::class);

    $result = $validator->validateField('gender', [
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => null,
    ]);

    expect($result['passed'])->toBeFalse()
        ->and($result['code'])->toBe('no_eligible_candidate')
        ->and($result['final'])->toBeNull();
});

test('field validator accepts valid gender enum values', function () {
    $validator = app(OcrEnsembleFieldValidator::class);

    $result = $validator->validateField('gender', [
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => 'female',
    ]);

    expect($result['passed'])->toBeTrue()
        ->and($result['final'])->toBe('female');
});

test('field validator uses soft income validation', function () {
    $validator = app(OcrEnsembleFieldValidator::class);

    $pass = $validator->validateField('income', [
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => '500,000',
    ]);
    $fail = $validator->validateField('income', [
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => 'not-an-income',
    ]);

    expect($pass['passed'])->toBeTrue()
        ->and($pass['code'])->toBe('income_soft_valid')
        ->and($fail['passed'])->toBeFalse()
        ->and($fail['code'])->toBe('no_eligible_candidate');
});

test('field validator rejects pure digit names', function () {
    $validator = app(OcrEnsembleFieldValidator::class);

    $result = $validator->validateField('full_name', [
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => '123456',
    ]);

    expect($result['passed'])->toBeFalse()
        ->and($result['code'])->toBe('no_eligible_candidate');
});

test('vote support eligibility mirrors voter lightweight rules', function () {
    expect(OcrEnsembleFieldVoteSupport::isEligible('primary_contact_number', '9876543210'))->toBeTrue()
        ->and(OcrEnsembleFieldVoteSupport::isEligible('primary_contact_number', '1234567890'))->toBeFalse()
        ->and(OcrEnsembleFieldVoteSupport::isEligible('full_name', 'अविनाश खोडवे'))->toBeTrue()
        ->and(OcrEnsembleFieldVoteSupport::isEligible('full_name', '99'))->toBeFalse();
});

test('normalize vote validate pipeline composes without persistence', function () {
    $engine = OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR;
    $candidates = [
        $engine => [
            'full_name' => 'चि. अविनाश अर्जुन खोडवे',
            'date_of_birth' => '04/01/1992',
            'primary_contact_number' => '9876543210',
        ],
    ];

    $normalizer = app(OcrEnsembleFieldNormalizer::class);
    $voter = app(OcrEnsembleFieldVoter::class);
    $validator = app(OcrEnsembleFieldValidator::class);

    foreach (['full_name', 'date_of_birth', 'primary_contact_number'] as $fieldKey) {
        $normalized = $normalizer->normalizeField($fieldKey, [
            $engine => $candidates[$engine][$fieldKey],
        ]);
        $vote = $voter->voteField(
            $fieldKey,
            $normalized,
            OcrEnsemblePhase3Constants::VOTE_MODE_SINGLE_ENGINE_PASS_THROUGH,
        );
        $validation = $validator->validateField($fieldKey, $normalized);

        expect($vote->winningEngine)->toBe($engine)
            ->and($validation['passed'])->toBeTrue()
            ->and($validation['final'])->not->toBeNull()
            ->and($vote->final)->toBe($validation['final']);
    }
});

test('phase3 pipeline classes do not import benchmark classes', function () {
    $paths = [
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleFieldNormalizer.php'),
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleFieldVoter.php'),
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleFieldValidator.php'),
        app_path('Services/Intake/OcrEnsemble/Support/OcrEnsembleFieldVoteSupport.php'),
    ];

    foreach ($paths as $file) {
        expect((string) file_get_contents($file))->not->toContain('OcrEnsembleBenchmark');
    }
});
