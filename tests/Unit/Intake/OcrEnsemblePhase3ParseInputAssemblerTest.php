<?php

use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\OcrEnsembleParseInputAssembler;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleParseInputAssemblySupport;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @return array<string, FieldResolutionFieldRecord>
 */
function phase3ResolvedEnvelopeFields(array $resolved): array
{
    $fields = [];
    foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
        $fields[$fieldKey] = FieldResolutionFieldRecord::missingSkeleton();
    }

    foreach ($resolved as $fieldKey => $final) {
        $fields[$fieldKey] = new FieldResolutionFieldRecord(
            final: $final,
            status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            source: OcrEnsemblePhase3Constants::FIELD_SOURCE_VALIDATOR,
            winningEngine: OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR,
            confidence: null,
            reason: 'single_engine_pass_through_after_validator',
            candidates: [OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => $final],
            normalized: [OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR => $final],
            validator: [
                'passed' => true,
                'code' => 'test_match',
                'detail' => null,
            ],
        );
    }

    return $fields;
}

test('parse input assembler builds canonical labeled header for resolved fields', function () {
    $envelope = new FieldResolutionEnvelope(
        meta: FieldResolutionEnvelope::skeleton(735)->meta,
        fields: phase3ResolvedEnvelopeFields([
            'full_name' => 'अविनाश अर्जुन खोडवे',
            'date_of_birth' => '1992-01-04',
            'primary_contact_number' => '9876543210',
            'religion' => 'Hindu',
            'caste' => 'Maratha',
        ]),
    );

    $assembled = app(OcrEnsembleParseInputAssembler::class)->assemble($envelope, '');

    expect($assembled)->toContain('मुलाचे नाव : अविनाश अर्जुन खोडवे')
        ->and($assembled)->toContain('जन्म तारीख : 04/01/1992')
        ->and($assembled)->toContain('मोबाईल : 9876543210')
        ->and($assembled)->toContain('धर्म : Hindu')
        ->and($assembled)->toContain('जात : Maratha');
});

test('parse input assembler omits missing gender and marital status lines', function () {
    $envelope = new FieldResolutionEnvelope(
        meta: FieldResolutionEnvelope::skeleton(736)->meta,
        fields: phase3ResolvedEnvelopeFields([
            'full_name' => 'Test Candidate',
            'gender' => 'male',
        ]),
    );

    $assembled = app(OcrEnsembleParseInputAssembler::class)->assemble($envelope, '');

    expect($assembled)->toContain('मुलाचे नाव : Test Candidate')
        ->and($assembled)->toContain('लिंग : पुरुष')
        ->and($assembled)->not->toContain('वैवाहिक स्थिती');
});

test('parse input assembler formats gender and marital status for parser labels', function () {
    $envelope = new FieldResolutionEnvelope(
        meta: FieldResolutionEnvelope::skeleton(736)->meta,
        fields: phase3ResolvedEnvelopeFields([
            'gender' => 'female',
            'marital_status' => 'never_married',
        ]),
    );

    $assembled = app(OcrEnsembleParseInputAssembler::class)->assemble($envelope, '');

    expect($assembled)->toContain('लिंग : स्त्री')
        ->and($assembled)->toContain('वैवाहिक स्थिती : अविवाहित');
});

test('parse input assembler deduplicates superseded structured lines from primary ocr body', function () {
    $envelope = new FieldResolutionEnvelope(
        meta: FieldResolutionEnvelope::skeleton(737)->meta,
        fields: phase3ResolvedEnvelopeFields([
            'full_name' => 'अविनाश अर्जुन खोडवे',
            'date_of_birth' => '1992-01-04',
            'primary_contact_number' => '9876543210',
        ]),
    );

    $primaryOcr = <<<'TXT'
मुलाचे नाव : Wrong OCR Name
जन्म तारीख : 01/01/1980
मोबाईल : 9000000000
कौटुंबिक माहिती
वडीलांचे नाव : रामदास खोडवे
स्वप्निल शब्दांत माझी अपेक्षा उंच आहे.
TXT;

    $assembled = app(OcrEnsembleParseInputAssembler::class)->assemble($envelope, $primaryOcr);

    expect($assembled)->toContain('मुलाचे नाव : अविनाश अर्जुन खोडवे')
        ->and($assembled)->toContain('वडीलांचे नाव : रामदास खोडवे')
        ->and($assembled)->toContain('स्वप्निल शब्दांत')
        ->and($assembled)->not->toContain('Wrong OCR Name')
        ->and($assembled)->not->toContain('9000000000')
        ->and($assembled)->not->toContain('01/01/1980');
});

test('parse input assembler keeps header before deduplicated body with blank separator', function () {
    $envelope = new FieldResolutionEnvelope(
        meta: FieldResolutionEnvelope::skeleton(735)->meta,
        fields: phase3ResolvedEnvelopeFields([
            'full_name' => 'Test Candidate',
        ]),
    );

    $assembled = app(OcrEnsembleParseInputAssembler::class)->assemble(
        $envelope,
        "मुलाचे नाव : Old Name\nपरिचय अनुच्छेद ठेवला जावा.",
    );

    expect($assembled)->toMatch('/मुलाचे नाव : Test Candidate\n\nपरिचय अनुच्छेद ठेवला जावा\./u');
});

test('parse input assembly support formats dob iso to dd/mm/yyyy', function () {
    expect(OcrEnsembleParseInputAssemblySupport::formatDobForParser('1992-01-04'))->toBe('04/01/1992');
});

test('parse input assembler does not import benchmark classes', function () {
    $paths = [
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleParseInputAssembler.php'),
        app_path('Services/Intake/OcrEnsemble/Support/OcrEnsembleParseInputAssemblySupport.php'),
    ];

    foreach ($paths as $file) {
        expect((string) file_get_contents($file))->not->toContain('OcrEnsembleBenchmark');
    }
});

test('assembled parse input meets minimum usable length when body is present', function () {
    $envelope = new FieldResolutionEnvelope(
        meta: FieldResolutionEnvelope::skeleton(735)->meta,
        fields: phase3ResolvedEnvelopeFields([
            'full_name' => 'Test Candidate',
        ]),
    );

    $assembled = app(OcrEnsembleParseInputAssembler::class)->assemble(
        $envelope,
        'कौटुंबिक माहिती आणि इतर माहिती ठेवली जाते.',
    );

    expect(mb_strlen($assembled, 'UTF-8'))->toBeGreaterThanOrEqual(
        OcrEnsembleParseInputAssemblySupport::MIN_ASSEMBLED_TEXT_LENGTH,
    );
});
