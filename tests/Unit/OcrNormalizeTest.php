<?php

use App\Services\Ocr\OcrNormalize;

test('normalizeGender converts male to Male', function () {
    $result = OcrNormalize::normalizeGender('male');
    expect($result)->toBe('Male');
});

test('normalizeGender converts female to Female', function () {
    $result = OcrNormalize::normalizeGender('female');
    expect($result)->toBe('Female');
});

test('normalizeGender converts Marathi पुरुष to Male', function () {
    $result = OcrNormalize::normalizeGender('पुरुष');
    expect($result)->toBe('Male');
});

test('normalizeGender converts Marathi स्त्री to Female', function () {
    $result = OcrNormalize::normalizeGender('स्त्री');
    expect($result)->toBe('Female');
});

test('normalizeDigits converts Devanagari to Arabic', function () {
    $result = OcrNormalize::normalizeDigits('२४/१०/१९९८');
    expect($result)->toBe('24/10/1998');
});

test('normalizePhone extracts 10-digit number', function () {
    $result = OcrNormalize::normalizePhone('+91 98765 43210');
    expect($result)->toBe('9876543210');
});

test('normalizePhone removes dashes', function () {
    $result = OcrNormalize::normalizePhone('98765-43210');
    expect($result)->toBe('9876543210');
});
