<?php

/**
 * Loop 24 — Mode A name-band crop probe (no production wiring).
 * Crops top fraction of image, OCR with multipass presets, checks truth needles.
 *
 * Usage: php tools/ocr-loop24-name-band-probe.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Ocr\ImagePreprocessingService;
use App\Services\Ocr\TesseractMultiPassOcrService;
use App\Services\OcrService;

if (! class_exists(Imagick::class)) {
    fwrite(STDERR, "Imagick required\n");
    exit(1);
}

$batch = storage_path('app/ocr-dev-batches/Batch-001');
$cases = [
    'snehal.jpeg' => ['स्नेहल', 'शहाजी', 'भोसले'],
    '1.1.jpeg' => ['अनिल', 'जयवंत', 'शिंदे'],
    'testing 16 to 20 pdf and with photo (1).pdf' => ['नवनाथ', 'प्रकाश', 'पाटील', 'कदम'],
];

$fractions = [0.12, 0.18, 0.25];
$presets = ['off', 'marathi_printed', 'photo_capture', 'clean_document'];
$pre = app(ImagePreprocessingService::class);
$tess = app(TesseractMultiPassOcrService::class);
$ref = new ReflectionClass($tess);
$run = $ref->getMethod('runTesseractAttempt');
$run->setAccessible(true);

$anyNew = false;

foreach ($cases as $file => $needles) {
    $src = $batch.DIRECTORY_SEPARATOR.$file;
    if (! is_file($src)) {
        echo "SKIP missing $file\n";
        continue;
    }

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $rel = 'intakes/_band_'.md5($file).'.'.$ext;
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

    // Raster source for crop (PDF → first page).
    $raster = $abs;
    $tmpRaster = null;
    if ($ext === 'pdf') {
        $im = new Imagick;
        $im->setResolution(200, 200);
        $im->readImage($abs.'[0]');
        $im->setImageFormat('png');
        $tmpRaster = storage_path('app/private/intakes/_band_raster_'.md5($file).'.png');
        $im->writeImage($tmpRaster);
        $im->clear();
        $raster = $tmpRaster;
    }

    foreach ($fractions as $frac) {
        $base = new Imagick($raster);
        $w = $base->getImageWidth();
        $h = $base->getImageHeight();
        $bandH = max(40, (int) round($h * $frac));
        $base->cropImage($w, $bandH, 0, 0);
        $base->setImagePage(0, 0, 0, 0);
        $cropPath = storage_path('app/private/intakes/_band_crop_'.md5($file.$frac).'.png');
        $base->writeImage($cropPath);
        $base->clear();

        foreach ($presets as $preset) {
            $path = $cropPath;
            $cleanup = null;
            if ($preset !== 'off') {
                try {
                    $result = $pre->preprocessForOcr($cropPath, 'intakes/_band_pre.png', $file, $preset);
                    $outPath = is_string($result['output_absolute_path'] ?? null) ? $result['output_absolute_path'] : '';
                    if (($result['used'] ?? false) && $outPath !== '' && is_file($outPath)) {
                        $path = $outPath;
                        $cleanup = $outPath;
                    }
                } catch (Throwable $e) {
                    echo "  frac=$frac preset=$preset FAIL ".$e->getMessage()."\n";
                    continue;
                }
            }

            $bestHits = array_fill_keys($needles, false);
            $snippet = '';
            foreach ([6, 4, 3, 7] as $psm) {
                try {
                    $text = (string) $run->invoke($tess, $path, ['mar+eng'], $psm);
                } catch (Throwable $e) {
                    continue;
                }
                if ($snippet === '' || mb_strlen($text) > mb_strlen($snippet)) {
                    $snippet = $text;
                }
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
                    $anyNew = true;
                }
            }
            if ($new || array_sum(array_map(static fn ($v) => $v ? 1 : 0, $bestHits)) > 0) {
                echo "  frac=$frac preset=$preset new=$new hits=".json_encode($bestHits, JSON_UNESCAPED_UNICODE)
                    .' snip='.json_encode(mb_substr(preg_replace('/\s+/u', ' ', $snippet) ?? '', 0, 100), JSON_UNESCAPED_UNICODE)."\n";
            }

            if ($cleanup && is_file($cleanup) && $cleanup !== $cropPath) {
                @unlink($cleanup);
            }
        }
        @unlink($cropPath);
    }

    @unlink($abs);
    if ($tmpRaster) {
        @unlink($tmpRaster);
    }
}

echo ($anyNew ? "LOOP24_NAME_BAND_HAS_NEW_NEEDLES\n" : "LOOP24_NAME_BAND_NO_NEW_NEEDLES\n");
exit($anyNew ? 0 : 2);
