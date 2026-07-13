<?php

use App\Services\Intake\OcrEnsembleBenchmarkCommunityExtractor;
use App\Services\Intake\OcrEnsembleBenchmarkDobNormalizer;
use App\Services\Intake\OcrEnsembleBenchmarkFieldMatcher;
use App\Services\Intake\OcrEnsembleBenchmarkMobileSelector;
use App\Services\Intake\OcrEnsembleBenchmarkNameExtractor;
use App\Services\Intake\OcrEnsembleBenchmarkOcrTextFieldExtractor;

test('community extractor handles absent caste without runtime error', function () {
    $lines = ['धर्म : Hindu', 'मुलाचे नाव : Test Candidate'];
    $result = app(OcrEnsembleBenchmarkCommunityExtractor::class)->extract($lines);

    expect($result['religion'])->toBe('Hindu')
        ->and($result['caste'])->toBeNull()
        ->and($result['sub_caste'])->toBeNull();
});

test('community extractor splits hindu maratha jati line', function () {
    $lines = ['जात : हिंदू - मराठा (९६ कुळी)'];
    $result = app(OcrEnsembleBenchmarkCommunityExtractor::class)->extract($lines);

    expect($result['religion'])->toBe('Hindu')
        ->and($result['caste'])->toBe('Maratha')
        ->and($result['sub_caste'])->toBe('96 कुळी');
});

test('ocr text field extractor strips ku honorific from name', function () {
    $text = "* कु. अभिजीत अशोक पाटील\nजात : हिंदू - मराठा\nशिक्षण : B.E. Computer\nनोकरी : Software Engineer";

    $fields = app(OcrEnsembleBenchmarkOcrTextFieldExtractor::class)->extractFromText($text);

    expect($fields['full_name'])->toBe('अभिजीत अशोक पाटील')
        ->and($fields['religion'])->toBe('Hindu')
        ->and($fields['caste'])->toBe('Maratha')
        ->and($fields['education'])->toContain('B.E.')
        ->and($fields['occupation'])->toBe('Software Engineer');
});

test('matcher treats hindu and maratha labels as equivalent across scripts', function () {
    expect(OcrEnsembleBenchmarkFieldMatcher::match('religion', 'Hindu', 'हिंदू'))->toBeTrue()
        ->and(OcrEnsembleBenchmarkFieldMatcher::match('caste', 'Maratha', 'मराठा'))->toBeTrue();
});

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
        ->and($fields['date_of_birth'])->toBe('1992-01-04')
        ->and($fields['primary_contact_number'])->toBe('8149379216')
        ->and($fields['religion'])->toBe('Hindu')
        ->and($fields['caste'])->toBe('Maratha');
});

test('dob normalizer parses dd/mm/yyyy and recovers common ocr digit substitutions', function () {
    $normalizer = app(OcrEnsembleBenchmarkDobNormalizer::class);

    expect($normalizer->normalize('04/01/1992'))->toBe('1992-01-04')
        ->and($normalizer->normalize('O4/O1/1992'))->toBe('1992-01-04')
        ->and($normalizer->normalize('15-08-1998'))->toBe('1998-08-15');
});

test('mobile selector prefers candidate mobile over relative numbers', function () {
    $lines = [
        'मुलाचे नाव : राहुल शिंदे',
        'वडील मोबाईल : 9123456789',
        'मोबाईल : 9876543210',
        'कौटुंबिक माहिती',
        'आई मोबाईल : 9988776655',
    ];

    $phone = app(OcrEnsembleBenchmarkMobileSelector::class)->selectPrimary($lines);

    expect($phone)->toBe('9876543210');
});

test('name extractor tolerates ocr text with no candidate name', function () {
    $extractor = app(OcrEnsembleBenchmarkNameExtractor::class);
    $lines = [
        '* कु.',
        'जन्म तारीख : 04/01/1992',
        'मोबाईल : 9876543210',
        'धर्म : Hindu',
        'जात : Maratha',
    ];

    expect($extractor->extract($lines))->toBeNull();
});

test('ocr text field extractor tolerates ocr text without candidate name', function () {
    $text = <<<'TXT'
* कु.
जन्म तारीख : 04/01/1992
मोबाईल : 9876543210
धर्म : Hindu
जात : Maratha
शिक्षण : B.E. Computer
TXT;

    $fields = app(OcrEnsembleBenchmarkOcrTextFieldExtractor::class)->extractFromText($text);

    expect($fields['full_name'])->toBeNull()
        ->and($fields['date_of_birth'])->toBe('1992-01-04')
        ->and($fields['primary_contact_number'])->toBe('9876543210');
});

test('name extractor strips html fragments and prefers labeled candidate name', function () {
    $extractor = app(OcrEnsembleBenchmarkNameExtractor::class);
    $lines = [
        '<td>बायो डाटा</td>',
        'मुलाचे नाव : <span>सुनील राजेश पवार</span>',
        'à¤ à¤¨à¤¾à¤µ',
    ];

    expect($extractor->extract($lines))->toBe('सुनील राजेश पवार')
        ->and($extractor->cleanBenchmarkName('* कु. अभिजीत अशोक पाटील'))->toBe('अभिजीत अशोक पाटील');
});

test('ocr text field extractor chooses owner mobile when multiple numbers exist', function () {
    $text = <<<'TXT'
मुलाचे नाव : राहुल शिंदे
जन्म तारीख : 04/01/1992
वडील : राम शिंदे मोबाईल 9123456789
मोबाईल : 9876543210
TXT;

    $fields = app(OcrEnsembleBenchmarkOcrTextFieldExtractor::class)->extractFromText($text);

    expect($fields['primary_contact_number'])->toBe('9876543210')
        ->and($fields['date_of_birth'])->toBe('1992-01-04');
});