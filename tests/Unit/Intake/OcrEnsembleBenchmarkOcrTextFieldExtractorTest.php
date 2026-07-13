<?php

use App\Services\Intake\OcrEnsembleBenchmarkCommunityExtractor;
use App\Services\Intake\OcrEnsembleBenchmarkFieldMatcher;
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
        ->and($fields['primary_contact_number'])->toBe('8149379216')
        ->and($fields['religion'])->toBe('Hindu')
        ->and($fields['caste'])->toBe('Maratha');
});