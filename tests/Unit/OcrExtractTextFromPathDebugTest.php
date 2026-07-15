<?php

use App\Services\Ocr\TesseractMultiPassOcrService;
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

    $mock = Mockery::mock(TesseractMultiPassOcrService::class);
    $mock->shouldReceive('extractFromImage')->once()->with($abs, $rel, 'test.png', 'off')->andReturn([
        'text' => '',
        'debug' => [
            'kind' => 'image',
            'preprocess_used' => false,
            'final_ocr_input_path' => $abs,
            'skipped_preprocessing_reason' => 'off',
        ],
    ]);
    $this->app->instance(TesseractMultiPassOcrService::class, $mock);

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

    $derived = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ocr_derived_'.uniqid('', true).'.png';
    file_put_contents($derived, 'derived-bytes');

    $mock = Mockery::mock(TesseractMultiPassOcrService::class);
    $mock->shouldReceive('extractFromImage')->once()->with($abs, $rel, 'test.png', 'noisy_scan')->andReturn([
        'text' => '',
        'debug' => [
            'kind' => 'image',
            'preprocess_used' => true,
            'final_ocr_input_path' => $derived,
            'original_absolute_path' => $abs,
            'derived_absolute_path' => $derived,
        ],
    ]);
    $this->app->instance(TesseractMultiPassOcrService::class, $mock);

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

    $derived = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ocr_keep_'.uniqid('', true).'.png';
    file_put_contents($derived, 'keep-me');

    $mock = Mockery::mock(TesseractMultiPassOcrService::class);
    $mock->shouldReceive('extractFromImage')->once()->with($abs, $rel, 'test.png', 'marathi_printed')->andReturn([
        'text' => '',
        'debug' => [
            'kind' => 'image',
            'derived_kept_on_disk' => true,
            'derived_absolute_path' => $derived,
        ],
    ]);
    $this->app->instance(TesseractMultiPassOcrService::class, $mock);

    $ocr = $this->app->make(OcrService::class);
    $ocr->extractTextFromPath($rel, 'test.png', 'marathi_printed');
    $dbg = $ocr->getLastExtractTextFromPathDebug();

    expect($dbg['derived_kept_on_disk'])->toBeTrue()
        ->and(is_file($derived))->toBeTrue();

    @unlink($abs);
    @unlink($derived);
});

test('scanned pdf with empty embedded text falls back to raster page OCR when Imagick can read PDF', function () {
    if (! class_exists(\Imagick::class)) {
        $this->markTestSkipped('Imagick required for PDF raster OCR fallback');
    }

    $pdf = new \Imagick;
    $pdf->newImage(200, 200, new \ImagickPixel('white'));
    $pdf->setImageFormat('pdf');
    $blob = $pdf->getImagesBlob();
    $pdf->clear();

    $rel = 'intakes/ocr_pdf_raster_'.uniqid('', true).'.pdf';
    $abs = storage_path('app/private/'.$rel);
    if (! is_dir(dirname($abs))) {
        mkdir(dirname($abs), 0755, true);
    }
    file_put_contents($abs, $blob);

    $mock = Mockery::mock(TesseractMultiPassOcrService::class);
    $mock->shouldReceive('extractFromImage')
        ->zeroOrMoreTimes()
        ->andReturn([
            'text' => "नाव : परीक्षण\nजन्म तारीख : 08 ऑगस्ट 1997",
            'debug' => [
                'kind' => 'image',
                'chosen_variant' => 'original',
                'preprocess_used' => false,
            ],
        ]);
    $this->app->instance(TesseractMultiPassOcrService::class, $mock);

    $ocr = $this->app->make(OcrService::class);
    $text = $ocr->extractTextFromPath($rel, 'scan.pdf', 'off');
    $dbg = $ocr->getLastExtractTextFromPathDebug();

    expect($dbg['kind'])->toBe('pdf')
        ->and($dbg['pdf_embedded_usable'])->toBeFalse();

    // Windows Imagick needs Ghostscript to rasterize PDFs. When GS is present we get OCR text;
    // when absent we record a clear raster error (production-safe degrade).
    if (($dbg['pdf_pipeline'] ?? null) === 'raster_ocr_fallback') {
        expect($text)->toContain('जन्म तारीख');
    } else {
        expect($dbg['pdf_pipeline'])->toBe('pdf_raster_failed_empty')
            ->and($dbg['pdf_raster_error'] ?? null)->not->toBeNull();
    }

    @unlink($abs);
});
test('usable embedded pdf text heuristics prefer real biodata layers', function () {
    $ref = new ReflectionClass(OcrService::class);
    $method = $ref->getMethod('pdfEmbeddedTextIsUsable');
    $method->setAccessible(true);
    $ocr = $this->app->make(OcrService::class);
    $long = str_repeat('नाव जन्म तारीख शिक्षण व्यवसाय मोबाइल ', 20);

    expect($method->invoke($ocr, ''))->toBeFalse()
        ->and($method->invoke($ocr, 'short'))->toBeFalse()
        ->and($method->invoke($ocr, $long))->toBeTrue()
        ->and($method->invoke(
            $ocr,
            'Hello biodata DOB name mobile education caste religion height text here with enough latin keywords'
        ))->toBeTrue();
});
