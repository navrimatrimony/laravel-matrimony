<?php

use App\Services\Ocr\ImagePreprocessingService;
use App\Services\Ocr\TesseractMultiPassOcrService;
use App\Services\OcrService;

uses(Tests\TestCase::class);

afterEach(function () {
    \Mockery::close();
    ImagePreprocessingService::resetDriverProbesForTests();
});

test('getDriverCapabilityReport includes availability flags and resolved driver', function () {
    ImagePreprocessingService::resetDriverProbesForTests();
    $report = app(ImagePreprocessingService::class)->getDriverCapabilityReport();

    expect($report)->toHaveKeys([
        'resolved_driver',
        'skipped_reason_if_none',
        'imagick_available',
        'gd_available',
        'diagnostics',
    ])
        ->and($report['resolved_driver'])->toBeIn(['imagick', 'gd', 'none'])
        ->and(is_bool($report['imagick_available']))->toBeTrue()
        ->and(is_bool($report['gd_available']))->toBeTrue();

    if ($report['resolved_driver'] === 'none') {
        expect($report['skipped_reason_if_none'])->toBeString()->not->toBe('');
    } else {
        expect($report['skipped_reason_if_none'])->toBeNull();
    }
});

test('when preprocess reports no driver, OCR debug uses original path and skip reason', function () {
    config(['ocr.preprocessing.enabled' => true, 'app.debug' => true]);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    $rel = 'intakes/ocr_dbg_nodriver_'.uniqid('', true).'.png';
    $abs = storage_path('app/private/'.$rel);
    if (! is_dir(dirname($abs))) {
        mkdir(dirname($abs), 0755, true);
    }
    file_put_contents($abs, $png !== false ? $png : '');

    $mock = Mockery::mock(TesseractMultiPassOcrService::class);
    $mock->shouldReceive('extractFromImage')->once()->with($abs, $rel, 'test.png', 'marathi_printed')->andReturn([
        'text' => '',
        'debug' => [
            'kind' => 'image',
            'preprocess_used' => false,
            'final_ocr_input_path' => $abs,
            'skipped_preprocessing_reason' => 'no_supported_image_driver',
            'driver' => 'none',
            'driver_resolution_diagnostics' => ['test' => true],
        ],
    ]);
    $this->app->instance(TesseractMultiPassOcrService::class, $mock);

    $ocr = $this->app->make(OcrService::class);
    $ocr->extractTextFromPath($rel, 'test.png', 'marathi_printed');
    $dbg = $ocr->getLastExtractTextFromPathDebug();

    expect($dbg['preprocess_used'])->toBeFalse()
        ->and($dbg['final_ocr_input_path'])->toBe($abs)
        ->and($dbg['skipped_preprocessing_reason'])->toBe('no_supported_image_driver')
        ->and($dbg['driver'])->toBe('none')
        ->and($dbg['driver_resolution_diagnostics'])->toBeArray();

    @unlink($abs);
});

test('successful mock preprocess yields final OCR input different from original', function () {
    config(['ocr.preprocessing.enabled' => true, 'app.debug' => false]);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    $rel = 'intakes/ocr_dbg_derived_'.uniqid('', true).'.png';
    $abs = storage_path('app/private/'.$rel);
    if (! is_dir(dirname($abs))) {
        mkdir(dirname($abs), 0755, true);
    }
    file_put_contents($abs, $png !== false ? $png : '');

    $derived = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ocr_mock_'.uniqid('', true).'.png';
    file_put_contents($derived, 'png-bytes');

    $mock = Mockery::mock(TesseractMultiPassOcrService::class);
    $mock->shouldReceive('extractFromImage')->once()->with($abs, $rel, 'test.png', null)->andReturn([
        'text' => '',
        'debug' => [
            'kind' => 'image',
            'final_ocr_input_path' => $derived,
        ],
    ]);
    $this->app->instance(TesseractMultiPassOcrService::class, $mock);

    $ocr = $this->app->make(OcrService::class);
    $ocr->extractTextFromPath($rel, 'test.png', null);
    $dbg = $ocr->getLastExtractTextFromPathDebug();

    expect($dbg['final_ocr_input_path'])->toBe($derived)
        ->and($dbg['final_ocr_input_path'])->not->toBe($abs);

    @unlink($abs);
    @unlink($derived);
});
