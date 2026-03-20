<?php

use App\Services\Ocr\ImagePreprocessingService;
use App\Services\OcrService;

uses(Tests\TestCase::class);

afterEach(function () {
    \Mockery::close();
});

test('preset off skips preprocessor and final OCR input path is the original file', function () {
    config(['ocr.preprocessing.enabled' => true, 'app.debug' => false]);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    $rel = 'intakes/ocr_dbg_off_'.uniqid('', true).'.png';
    $abs = storage_path('app/private/'.$rel);
    $dir = dirname($abs);
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($abs, $png !== false ? $png : '');

    $mock = Mockery::mock(ImagePreprocessingService::class);
    $mock->shouldNotReceive('shouldPreprocess');
    $mock->shouldNotReceive('preprocessForOcr');
    $this->app->instance(ImagePreprocessingService::class, $mock);

    $ocr = $this->app->make(OcrService::class);
    $ocr->extractTextFromPath($rel, 'test.png', 'off');
    $dbg = $ocr->getLastExtractTextFromPathDebug();

    expect($dbg)->toBeArray()
        ->and($dbg['preprocess_used'])->toBeFalse()
        ->and($dbg['final_ocr_input_path'])->toBe($abs)
        ->and($dbg['skipped_preprocessing_reason'])->toBe('off');

    @unlink($abs);
});

test('named preset uses derived path for final OCR input when preprocess succeeds', function () {
    config([
        'ocr.preprocessing.enabled' => true,
        'app.debug' => false,
        'ocr.preprocessing.cleanup_enabled' => true,
    ]);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    $rel = 'intakes/ocr_dbg_named_'.uniqid('', true).'.png';
    $abs = storage_path('app/private/'.$rel);
    $dir = dirname($abs);
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($abs, $png !== false ? $png : '');

    $derived = storage_path('app/private/ocr-preprocessed/derived_'.uniqid('', true).'.png');
    $dDir = dirname($derived);
    if (! is_dir($dDir)) {
        mkdir($dDir, 0755, true);
    }
    file_put_contents($derived, 'derived-bytes');

    $mock = Mockery::mock(ImagePreprocessingService::class);
    $mock->shouldReceive('shouldPreprocess')->once()->andReturn(true);
    $mock->shouldReceive('preprocessForOcr')->once()->andReturn([
        'used' => true,
        'preset' => 'noisy_scan',
        'output_path' => 'ocr-preprocessed/'.basename($derived),
        'output_absolute_path' => $derived,
        'source_path' => $abs,
        'fallback_used' => false,
        'meta' => [
            'driver' => 'imagick',
            'steps' => ['adaptive_threshold_div_22_off_14'],
            'width' => 3,
            'height' => 3,
            'original_width' => 1,
            'original_height' => 1,
            'output_format' => 'png',
            'output_filesize_bytes' => 13,
            'applied_steps' => ['adaptive_threshold_div_22_off_14'],
            'preset_name' => 'noisy_scan',
        ],
    ]);
    $this->app->instance(ImagePreprocessingService::class, $mock);

    $beforeHash = md5_file($abs);

    $ocr = $this->app->make(OcrService::class);
    $ocr->extractTextFromPath($rel, 'test.png', 'noisy_scan');
    $dbg = $ocr->getLastExtractTextFromPathDebug();

    expect(md5_file($abs))->toBe($beforeHash)
        ->and($dbg['preprocess_used'])->toBeTrue()
        ->and($dbg['final_ocr_input_path'])->toBe($derived)
        ->and($dbg['original_absolute_path'])->toBe($abs)
        ->and($dbg['final_ocr_input_path'])->not->toBe($dbg['original_absolute_path']);

    @unlink($abs);
    @unlink($derived);
});

test('debug keep derived skips unlink when app.debug and config enabled', function () {
    config([
        'ocr.preprocessing.enabled' => true,
        'app.debug' => true,
        'ocr.preprocessing.debug_keep_derived_when_app_debug' => true,
        'ocr.preprocessing.cleanup_enabled' => true,
    ]);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    $rel = 'intakes/ocr_dbg_keep_'.uniqid('', true).'.png';
    $abs = storage_path('app/private/'.$rel);
    if (! is_dir(dirname($abs))) {
        mkdir(dirname($abs), 0755, true);
    }
    file_put_contents($abs, $png !== false ? $png : '');

    $derived = storage_path('app/private/ocr-preprocessed/keep_'.uniqid('', true).'.png');
    if (! is_dir(dirname($derived))) {
        mkdir(dirname($derived), 0755, true);
    }
    file_put_contents($derived, 'keep-me');

    $mock = Mockery::mock(ImagePreprocessingService::class);
    $mock->shouldReceive('shouldPreprocess')->once()->andReturn(true);
    $mock->shouldReceive('preprocessForOcr')->once()->andReturn([
        'used' => true,
        'preset' => 'marathi_printed',
        'output_path' => 'ocr-preprocessed/'.basename($derived),
        'output_absolute_path' => $derived,
        'source_path' => $abs,
        'fallback_used' => false,
        'meta' => [
            'driver' => 'imagick',
            'steps' => ['grayscale'],
            'width' => 2,
            'height' => 2,
            'original_width' => 1,
            'original_height' => 1,
            'output_format' => 'png',
            'output_filesize_bytes' => 6,
            'applied_steps' => ['grayscale'],
            'preset_name' => 'marathi_printed',
        ],
    ]);
    $this->app->instance(ImagePreprocessingService::class, $mock);

    $ocr = $this->app->make(OcrService::class);
    $ocr->extractTextFromPath($rel, 'test.png', 'marathi_printed');
    $dbg = $ocr->getLastExtractTextFromPathDebug();

    expect($dbg['derived_kept_on_disk'])->toBeTrue()
        ->and(is_file($derived))->toBeTrue();

    @unlink($abs);
    @unlink($derived);
});
