<?php

/**
 * Loop 29 — D(8) DOB region probes on ORIGINAL Batch-001 file (not preview).
 * Crops DOB band; tries zoom/contrast/sharpen/threshold/DPI-like upscale + Marathi digit check.
 *
 * Usage: php tools/ocr-loop29-d8-dob-probe.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Ocr\TesseractMultiPassOcrService;

if (! class_exists(Imagick::class)) {
    fwrite(STDERR, "Imagick required\n");
    exit(1);
}

$src = storage_path('app/ocr-dev-batches/Batch-001/D (8).jpeg');
if (! is_file($src)) {
    fwrite(STDERR, "missing $src\n");
    exit(1);
}

$orig = new Imagick($src);
$ow = $orig->getImageWidth();
$oh = $orig->getImageHeight();
echo "ORIGINAL path=$src\n";
echo "ORIGINAL size={$ow}x{$oh} bytes=".filesize($src)." format=".$orig->getImageFormat()
    .' quality='.$orig->getImageCompressionQuality()."\n";

$tess = app(TesseractMultiPassOcrService::class);
$ref = new ReflectionClass($tess);
$run = $ref->getMethod('runTesseractAttempt');
$run->setAccessible(true);

// Full-page baseline (original, no preprocess)
$full = (string) $run->invoke($tess, $src, ['mar+eng'], 6);
echo "FULL_PAGE has_2403=".json_encode(mb_stripos($full, '२४०३') !== false || preg_match('/२४\s*०?३|24\s*0?3/', $full) === 1)."\n";
echo "FULL_PAGE has_21=".json_encode(mb_stripos($full, '२१') !== false || str_contains($full, '21'))."\n";
if (preg_match('/जन्म[^\n]{0,40}/u', $full, $m)) {
    echo 'FULL_PAGE dobish='.json_encode($m[0], JSON_UNESCAPED_UNICODE)."\n";
}

// Horizontal bands around typical DOB y ( empirically ~ mid-upper on biodata)
$bands = [
    ['y0' => 0.08, 'y1' => 0.22, 'label' => 'top_08_22'],
    ['y0' => 0.12, 'y1' => 0.28, 'label' => 'mid_12_28'],
    ['y0' => 0.15, 'y1' => 0.32, 'label' => 'mid_15_32'],
    ['y0' => 0.18, 'y1' => 0.35, 'label' => 'mid_18_35'],
];

$ops = [
    'raw' => static function (Imagick $im): void {},
    'gray' => static function (Imagick $im): void {
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
    },
    'gray_contrast' => static function (Imagick $im): void {
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->normalizeImage();
        $im->contrastImage(true);
    },
    'gray_sharpen' => static function (Imagick $im): void {
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->normalizeImage();
        $im->sharpenImage(0, 1.2);
    },
    'gray_threshold' => static function (Imagick $im): void {
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->normalizeImage();
        $im->thresholdImage(0.45 * $im->getQuantum());
    },
    'zoom2_gray' => static function (Imagick $im): void {
        $im->resizeImage($im->getImageWidth() * 2, $im->getImageHeight() * 2, Imagick::FILTER_LANCZOS, 1);
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->normalizeImage();
        $im->sharpenImage(0, 1.0);
    },
    'zoom3_gray_contrast' => static function (Imagick $im): void {
        $im->resizeImage($im->getImageWidth() * 3, $im->getImageHeight() * 3, Imagick::FILTER_LANCZOS, 1);
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->normalizeImage();
        $im->contrastImage(true);
        $im->sharpenImage(0, 1.2);
    },
    'red_channel' => static function (Imagick $im): void {
        $im->separateImageChannel(Imagick::CHANNEL_RED);
        $im->normalizeImage();
        $im->negateImage(false);
    },
];

$found21Dob = false;
$outDir = storage_path('app/private/ocr-temp/d8-dob-probe');
if (! is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

foreach ($bands as $band) {
    $y0 = (int) round($oh * $band['y0']);
    $h = max(40, (int) round($oh * ($band['y1'] - $band['y0'])));
    foreach ($ops as $opName => $op) {
        $crop = clone $orig;
        $crop->cropImage($ow, $h, 0, $y0);
        $crop->setImagePage(0, 0, 0, 0);
        $op($crop);
        $crop->setImageFormat('png');
        $path = $outDir.'/'.$band['label'].'_'.$opName.'.png';
        $crop->writeImage($path);
        $crop->clear();

        $bestHit = ['21' => false, '24' => false, 'snip' => ''];
        foreach ([6, 7, 8, 4] as $psm) {
            try {
                $text = (string) $run->invoke($tess, $path, ['mar+eng'], $psm);
            } catch (Throwable $e) {
                continue;
            }
            $norm = $text;
            // Marathi digits already in text; also check Arabic
            $has21 = (bool) preg_match('/(?:^|[^\d०-९])(?:२१|21)(?:[^\d०-९]|$)/u', $norm)
                || (bool) preg_match('/जन्म[^\n]{0,30}(?:२१|21)/u', $norm);
            $has24 = (bool) preg_match('/(?:२४|24)\s*[\/.\-]?०?३|(?:२४|24)०३/u', $norm)
                || mb_stripos($norm, '२४०३') !== false
                || (bool) preg_match('/जन्म[^\n]{0,30}(?:२४|24)/u', $norm);
            if ($has21 || $has24 || $bestHit['snip'] === '') {
                $bestHit['21'] = $bestHit['21'] || $has21;
                $bestHit['24'] = $bestHit['24'] || $has24;
                if (preg_match('/जन्म[^\n]{0,50}/u', $norm, $mm)) {
                    $bestHit['snip'] = $mm[0];
                } elseif ($bestHit['snip'] === '') {
                    $bestHit['snip'] = mb_substr(preg_replace('/\s+/u', ' ', $norm) ?? '', 0, 80);
                }
            }
            if ($has21 && ! $has24) {
                $found21Dob = true;
            }
        }
        if ($bestHit['21'] || $bestHit['24']) {
            echo sprintf(
                "BAND %s op=%s day21=%s day24=%s snip=%s\n",
                $band['label'],
                $opName,
                $bestHit['21'] ? 'Y' : 'n',
                $bestHit['24'] ? 'Y' : 'n',
                json_encode($bestHit['snip'], JSON_UNESCAPED_UNICODE)
            );
        }
    }
}

$orig->clear();
echo ($found21Dob ? "LOOP29_FOUND_DAY_21_WITHOUT_24\n" : "LOOP29_NO_CLEAN_DAY_21_SIGNAL\n");
exit($found21Dob ? 0 : 2);
