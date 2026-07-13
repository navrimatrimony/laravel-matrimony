<?php

use App\Services\Intake\OcrEnsembleBenchmarkOcrTextFieldExtractor;

test('ocr text field extractor reads name and mobile from ocr text', function () {
    $text = <<<'TXT'
मुलाचे नाव : अविनाश अर्जुन खोडवे
जन्म तारीख : 04/01/1992
मोबाईल : 8149379216
धर्म : Hindu
जात : Maratha
TXT;

    $fields = app(OcrEnsembleBenchmarkOcrTextFieldExtractor::class)->extractFromText($text);

    expect($fields['full_name'])->toContain('अविनाश')
        ->and($fields['primary_contact_number'])->toBe('8149379216')
        ->and($fields['religion'])->toBe('Hindu')
        ->and($fields['caste'])->toBe('Maratha');
});
