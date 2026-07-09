<?php

use App\Services\Intake\BulkIntakeCandidateMobileCollector;
use App\Support\MobileNumber;

test('collector merges parsed and ocr mobiles', function () {
    $collector = app(BulkIntakeCandidateMobileCollector::class);

    $display = $collector->displayFromSources(
        ['core' => ['primary_contact_number' => '9876543210']],
        "नाव : OCR Candidate\nमोबाईल : 9123456789 / 9988776655"
    );

    expect($display)->toBe('9876543210, 9123456789, 9988776655');
});

test('mobile normalize accepts sample ocr numbers', function () {
    expect(MobileNumber::normalize('9123456789'))->toBe('9123456789')
        ->and(MobileNumber::normalize('9988776655'))->toBe('9988776655');
});

test('collector parse input handles spaced indian prefix', function () {
    $collector = app(BulkIntakeCandidateMobileCollector::class);

    expect($collector->parseInput('+91 98765 43210'))->toBe(['9876543210']);
});
