<?php

/**
 * Loop 13 — probe Mode A images via PHP preprocess presets + Tesseract.
 * Usage: php tools/ocr-loop13-raw-image-probe.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Ocr\ImagePreprocessingService;
use App\Services\Ocr\TesseractMultiPassOcrService;
use App\Services\OcrService;

$batch = storage_path('app/ocr-dev-batches/Batch-001');
$cases = [
    'snehal.jpeg' => ['स्नेहल', 'शहाजी', 'भोसले', 'स्त्री', 'मुलगी', 'कुमारी'],
    '1.1.jpeg' => ['अनिल', 'जयवंत', 'शिंदे'],
    'D (8).jpeg' => ['हिंदू', 'Hindu', 'स्त्री', 'मुलगी', '२१/०३', '21/03'],
    'photo_2026-06-05_10-33-07.jpg' => ['हिंदू', 'Hindu', 'धर्म'],
    '1.jpeg' => ['स्त्री', 'मुलगी', 'कुमारी'],
    'WhatsApp Image 2025-12-03 at 11.40.19 AM.jpeg' => ['डाकवे'],
    'photo_2026-06-05_10-33-22.jpg' => ['स्त्री', 'मुलगी', 'कुमारी', 'चि.', 'कु.'],
];

$presets = ['off', 'marathi_printed', 'photo_capture', 'clean_document', 'high_contrast', 'noisy_scan'];
$pre = app(ImagePreprocessingService::class);
$tess = app(TesseractMultiPassOcrService::class);
$ref = new ReflectionClass($tess);
$run = $ref->getMethod('runTesseractAttempt');
$run->setAccessible(true);

$out = [];
foreach ($cases as $file => $needles) {
    $src = $batch.DIRECTORY_SEPARATOR.$file;
    if (! is_file($src)) {
        echo "SKIP $file\n";
        continue;
    }
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $rel = 'intakes/_probe_'.md5($file).'.'.$ext;
    $abs = storage_path('app/private/'.$rel);
    if (! is_dir(dirname($abs))) {
        mkdir(dirname($abs), 0755, true);
    }
    copy($src, $abs);

    $prod = app(OcrService::class)->extractTextFromPath($rel, $file, null);
    $prodHits = [];
    foreach ($needles as $n) {
        $prodHits[$n] = mb_stripos($prod, $n) !== false;
    }
    echo "=== $file prod=".json_encode($prodHits, JSON_UNESCAPED_UNICODE)."\n";

    $row = ['file' => $file, 'prod_hits' => $prodHits, 'presets' => []];
    foreach ($presets as $preset) {
        $path = $abs;
        $cleanup = null;
        if ($preset !== 'off') {
            try {
                $result = $pre->preprocessForOcr($abs, $rel, $file, $preset);
                $outPath = is_string($result['output_absolute_path'] ?? null) ? $result['output_absolute_path'] : '';
                if (($result['used'] ?? false) && $outPath !== '' && is_file($outPath)) {
                    $path = $outPath;
                    $cleanup = $outPath;
                }
            } catch (Throwable $e) {
                echo "  preset $preset FAIL ".$e->getMessage()."\n";
                continue;
            }
        }
        $bestHits = array_fill_keys($needles, false);
        $bestChars = 0;
        foreach ([6, 4, 3] as $psm) {
            try {
                $text = (string) $run->invoke($tess, $path, ['mar+eng'], $psm);
            } catch (Throwable $e) {
                continue;
            }
            $bestChars = max($bestChars, mb_strlen($text));
            foreach ($needles as $n) {
                if (mb_stripos($text, $n) !== false) {
                    $bestHits[$n] = true;
                }
            }
        }
        $new = false;
        foreach ($needles as $n) {
            if ($bestHits[$n] && ! $prodHits[$n]) {
                $new = true;
            }
        }
        $row['presets'][$preset] = ['hits' => $bestHits, 'new_vs_prod' => $new, 'chars' => $bestChars];
        echo "  $preset new=$new hits=".json_encode($bestHits, JSON_UNESCAPED_UNICODE)."\n";
        if ($cleanup && is_file($cleanup) && $cleanup !== $abs) {
            @unlink($cleanup);
        }
    }
    @unlink($abs);
    $out[] = $row;
}

$dir = storage_path('app/private/ocr-ensemble-benchmark');
$path = $dir.'/loop13_raw_image_probe_'.date('Ymd_His').'.json';
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "artifact=$path\n";
