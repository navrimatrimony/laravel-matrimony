<?php

use App\Services\Ocr\ImagePreprocessingService;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

function ocrMakeTestPng(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ocr_pp_'.uniqid('', true).'.png';
    // 1×1 PNG — no GD required to create the fixture
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    file_put_contents($path, $png !== false ? $png : '');

    return $path;
}

function ocrImageDriverAvailable(): bool
{
    return extension_loaded('imagick') || extension_loaded('gd');
}

beforeEach(function () {
    ImagePreprocessingService::resetDriverProbesForTests();
    config([
        'ocr.preprocessing.enabled' => true,
        'ocr.preprocessing.temp_disk' => 'local',
        'ocr.preprocessing.temp_dir' => 'ocr-preprocessed-test-'.uniqid(),
        'ocr.preprocessing.preset_override' => null,
    ]);
});

afterEach(function () {
    $dir = config('ocr.preprocessing.temp_dir');
    if (is_string($dir) && $dir !== '') {
        $full = storage_path('app/private/'.$dir);
        if (is_dir($full)) {
            File::deleteDirectory($full);
        }
    }
});

test('resolvePreset uses extension mapping for jpg', function () {
    $svc = app(ImagePreprocessingService::class);
    expect($svc->resolvePreset('intakes/x', 'biodata.jpg', null))->toBe('marathi_printed');
});

test('resolvePreset respects explicit override argument', function () {
    $svc = app(ImagePreprocessingService::class);
    expect($svc->resolvePreset('intakes/x', 'biodata.jpg', 'noisy_scan'))->toBe('noisy_scan');
});

test('resolvePreset respects config preset_override', function () {
    config(['ocr.preprocessing.preset_override' => 'clean_document']);
    $svc = app(ImagePreprocessingService::class);
    expect($svc->resolvePreset('intakes/x', 'biodata.jpg', null))->toBe('clean_document');
});

test('non-image bypass: pdf returns used false without fallback', function () {
    $pdf = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ocr_fake_'.uniqid('', true).'.pdf';
    file_put_contents($pdf, '%PDF-1.4 minimal');

    $svc = app(ImagePreprocessingService::class);
    $r = $svc->preprocessForOcr($pdf, 'intakes/doc.pdf', 'doc.pdf', null);

    expect($r['used'])->toBeFalse()
        ->and($r['fallback_used'])->toBeFalse()
        ->and($r['preset'])->toBeNull()
        ->and($r['output_absolute_path'])->toBeNull()
        ->and($r['meta']['skipped_reason'] ?? null)->toBe('preprocessing_not_applicable');

    @unlink($pdf);
});

test('preprocessing failure falls back without crashing', function () {
    if (! ocrImageDriverAvailable()) {
        expect(true)->toBeTrue();

        return;
    }

    $bad = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ocr_bad_'.uniqid('', true).'.png';
    file_put_contents($bad, '');

    $svc = app(ImagePreprocessingService::class);
    $r = $svc->preprocessForOcr($bad, 'intakes/bad.png', 'bad.png', null);

    expect($r['used'])->toBeFalse()
        ->and($r['fallback_used'])->toBeTrue()
        ->and($r['source_path'])->toBe($bad)
        ->and($r['meta']['skipped_reason'] ?? null)->toBe('preprocess_pipeline_failed');

    @unlink($bad);
});

test('successful preprocessing does not modify original file', function () {
    if (! ocrImageDriverAvailable()) {
        expect(true)->toBeTrue();

        return;
    }

    $src = ocrMakeTestPng();
    $before = md5_file($src);

    $svc = app(ImagePreprocessingService::class);
    $r = $svc->preprocessForOcr($src, 'intakes/sample.png', 'sample.png', null);

    expect($r['used'])->toBeTrue()
        ->and(md5_file($src))->toBe($before)
        ->and($r['output_absolute_path'])->not->toBe($src)
        ->and(is_file((string) $r['output_absolute_path']))->toBeTrue();

    if (! empty($r['output_absolute_path']) && is_file($r['output_absolute_path'])) {
        @unlink($r['output_absolute_path']);
    }
    @unlink($src);
});

test('shouldPreprocess is false when disabled in config', function () {
    config(['ocr.preprocessing.enabled' => false]);
    $svc = app(ImagePreprocessingService::class);
    expect($svc->shouldPreprocess('intakes/a.png', 'a.png'))->toBeFalse();
});
