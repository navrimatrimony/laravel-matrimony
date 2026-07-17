<?php

/**
 * Loop 30 — Exhaust RAW OCR approaches on D(8).jpeg DOB (invent forbidden).
 *
 * Sections: A (Tesseract prod modes on tight crop), B (glyph enlargements),
 * C (segmentation crops), D (preprocess matrix), E (pipeline path dump).
 * Engines EasyOCR/Paddle/DocTR: run via tools/ocr-loop30-d8-engines.py
 *
 * Usage: php tools/ocr-loop30-d8-exhaustive.php
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

$outRoot = storage_path('app/private/ocr-temp/d8-loop30');
$dirs = [
    'crops' => $outRoot.'/crops',
    'glyph' => $outRoot.'/glyph',
    'seg' => $outRoot.'/seg',
    'prep' => $outRoot.'/prep',
    'boxes' => $outRoot.'/boxes',
];
foreach ($dirs as $d) {
    if (! is_dir($d)) {
        mkdir($d, 0755, true);
    }
}

$orig = new Imagick($src);
$ow = $orig->getImageWidth();
$oh = $orig->getImageHeight();
$meta = [
    'source' => $src,
    'width' => $ow,
    'height' => $oh,
    'bytes' => filesize($src),
    'format' => $orig->getImageFormat(),
    'quality' => $orig->getImageCompressionQuality(),
    'sha256' => hash_file('sha256', $src),
];

$tess = app(TesseractMultiPassOcrService::class);
$ref = new ReflectionClass($tess);
$run = $ref->getMethod('runTesseractAttempt');
$run->setAccessible(true);

$tesseractBin = trim((string) shell_exec('where tesseract 2>nul')) ?: 'tesseract';
$tesseractBin = preg_split('/\r\n|\n|\r/', $tesseractBin)[0] ?? 'tesseract';

/**
 * @return array{text: string, conf: float|null, duration_ms: int, words: list<array<string,mixed>>}
 */
function tessRaw(string $bin, string $path, string $lang, int $psm): array
{
    $started = hrtime(true);
    $cmd = sprintf(
        '%s %s stdout -l %s --psm %d tsv 2>nul',
        escapeshellarg($bin),
        escapeshellarg($path),
        escapeshellarg($lang),
        $psm
    );
    $tsv = (string) shell_exec($cmd);
    $durationMs = (int) round((hrtime(true) - $started) / 1e6);

    $words = [];
    $texts = [];
    $confs = [];
    $lines = preg_split('/\r\n|\n|\r/', $tsv) ?: [];
    $header = true;
    foreach ($lines as $line) {
        if ($header) {
            $header = false;
            continue;
        }
        if ($line === '') {
            continue;
        }
        $cols = explode("\t", $line);
        if (count($cols) < 12) {
            continue;
        }
        $level = (int) $cols[0];
        $text = $cols[11] ?? '';
        $conf = is_numeric($cols[10] ?? null) ? (float) $cols[10] : -1.0;
        if ($level === 5 && $text !== '') {
            $words[] = [
                'text' => $text,
                'conf' => $conf,
                'left' => (int) $cols[6],
                'top' => (int) $cols[7],
                'width' => (int) $cols[8],
                'height' => (int) $cols[9],
            ];
            $texts[] = $text;
            if ($conf >= 0) {
                $confs[] = $conf;
            }
        }
    }

    // Also plain text (raw, no normalize)
    $started2 = hrtime(true);
    $cmd2 = sprintf(
        '%s %s stdout -l %s --psm %d 2>nul',
        escapeshellarg($bin),
        escapeshellarg($path),
        escapeshellarg($lang),
        $psm
    );
    $plain = (string) shell_exec($cmd2);
    $durationMs = max($durationMs, (int) round((hrtime(true) - $started2) / 1e6));

    return [
        'text' => $plain,
        'conf' => $confs === [] ? null : round(array_sum($confs) / count($confs), 2),
        'duration_ms' => $durationMs,
        'words' => $words,
    ];
}

function writePng(Imagick $im, string $path): void
{
    $im->setImageFormat('png');
    $im->writeImage($path);
}

function cropRel(Imagick $src, float $x0, float $y0, float $x1, float $y1): Imagick
{
    $w = $src->getImageWidth();
    $h = $src->getImageHeight();
    $cx = (int) max(0, round($w * $x0));
    $cy = (int) max(0, round($h * $y0));
    $cw = (int) max(1, round($w * ($x1 - $x0)));
    $ch = (int) max(1, round($h * ($y1 - $y0)));
    if ($cx + $cw > $w) {
        $cw = $w - $cx;
    }
    if ($cy + $ch > $h) {
        $ch = $h - $cy;
    }
    $c = clone $src;
    $c->cropImage($cw, $ch, $cx, $cy);
    $c->setImagePage(0, 0, 0, 0);

    return $c;
}

// --- Locate DOB line via TSV on mid band ---
$band = cropRel($orig, 0.0, 0.10, 1.0, 0.30);
$bandPath = $dirs['crops'].'/locate_band.png';
writePng($band, $bandPath);
$band->clear();

$locate = tessRaw($tesseractBin, $bandPath, 'mar+eng', 6);
$dobBox = null;
foreach ($locate['words'] as $w) {
    if (mb_stripos($w['text'], 'जन्म') !== false || preg_match('/२[१४०]|24|21|०३|1999|१९९९/u', $w['text'])) {
        if ($dobBox === null) {
            $dobBox = $w;
        } else {
            $dobBox['left'] = min($dobBox['left'], $w['left']);
            $dobBox['top'] = min($dobBox['top'], $w['top']);
            $right = max($dobBox['left'] + $dobBox['width'], $w['left'] + $w['width']);
            $bottom = max($dobBox['top'] + $dobBox['height'], $w['top'] + $w['height']);
            $dobBox['width'] = $right - $dobBox['left'];
            $dobBox['height'] = $bottom - $dobBox['top'];
            $dobBox['text'] .= ' '.$w['text'];
        }
    }
}

// Fallback tight crop if TSV weak: empirical mid-upper DOB line
$bandY0 = (int) round($oh * 0.10);
$tight = [
    // relative to full image
    'x0' => 0.02,
    'y0' => 0.14,
    'x1' => 0.72,
    'y1' => 0.22,
];
if ($dobBox !== null) {
    $padX = 8;
    $padY = 6;
    $absLeft = max(0, $dobBox['left'] - $padX);
    $absTop = max(0, $bandY0 + $dobBox['top'] - $padY);
    $absRight = min($ow, $dobBox['left'] + $dobBox['width'] + $padX);
    $absBottom = min($oh, $bandY0 + $dobBox['top'] + $dobBox['height'] + $padY);
    $tight = [
        'x0' => $absLeft / $ow,
        'y0' => $absTop / $oh,
        'x1' => $absRight / $ow,
        'y1' => $absBottom / $oh,
    ];
}

$evidence = [
    'loop' => 30,
    'meta' => $meta,
    'locate' => [
        'band' => 'y10_30',
        'raw_text' => $locate['text'],
        'dob_box_in_band' => $dobBox,
        'tight_rel' => $tight,
    ],
    'A_tesseract' => [],
    'B_glyph' => [],
    'C_segmentation' => [],
    'D_preprocess' => [],
    'E_pipeline' => [],
];

// --- A: tight DOB crop + production Tesseract modes ---
$tightIm = cropRel($orig, $tight['x0'], $tight['y0'], $tight['x1'], $tight['y1']);
$tightPath = $dirs['crops'].'/dob_line_tight.png';
writePng($tightIm, $tightPath);

$prodPsms = config('ocr.tesseract_multipass.psm_modes', [6, 4, 11]);
$extraPsms = [3, 7, 8, 13];
$psms = array_values(array_unique(array_merge($prodPsms, $extraPsms)));
$langs = ['mar+eng', 'mar', 'eng'];

foreach ($langs as $lang) {
    foreach ($psms as $psm) {
        $r = tessRaw($tesseractBin, $tightPath, $lang, (int) $psm);
        $evidence['A_tesseract'][] = [
            'engine' => 'tesseract',
            'lang' => $lang,
            'psm' => (int) $psm,
            'prod_mode' => in_array((int) $psm, $prodPsms, true) && $lang === 'mar+eng',
            'raw_text' => $r['text'],
            'confidence' => $r['conf'],
            'duration_ms' => $r['duration_ms'],
            'word_count' => count($r['words']),
        ];
        echo sprintf(
            "A tess lang=%s psm=%d conf=%s ms=%d raw=%s\n",
            $lang,
            $psm,
            $r['conf'] === null ? 'n/a' : (string) $r['conf'],
            $r['duration_ms'],
            json_encode(mb_substr(preg_replace('/\s+/u', ' ', $r['text']) ?? '', 0, 100), JSON_UNESCAPED_UNICODE)
        );
    }
}

// Multipass production path on ORIGINAL (full page) for baseline
$mp = $tess->extractFromImage($src, 'ocr-dev-batches/Batch-001/D (8).jpeg', 'D (8).jpeg', null);
$evidence['A_multipass_full'] = [
    'raw_text_snip' => mb_substr($mp['text'], 0, 500),
    'debug' => [
        'final_ocr_input_path' => $mp['debug']['final_ocr_input_path'] ?? null,
        'original_absolute_path' => $mp['debug']['original_absolute_path'] ?? null,
        'chosen_variant' => $mp['debug']['chosen_variant'] ?? null,
        'chosen_psm' => $mp['debug']['chosen_psm'] ?? null,
        'chosen_language' => $mp['debug']['chosen_language'] ?? null,
        'score' => $mp['debug']['score'] ?? null,
    ],
];
if (preg_match('/जन्म[^\n]{0,60}/u', $mp['text'], $mm)) {
    $evidence['A_multipass_full']['dobish'] = $mm[0];
}

// --- B: glyph enlargements + char boxes ---
$glyphBase = clone $tightIm;
foreach ([2, 4, 8] as $z) {
    $g = clone $glyphBase;
    $g->resizeImage($g->getImageWidth() * $z, $g->getImageHeight() * $z, Imagick::FILTER_LANCZOS, 1);
    $gp = $dirs['glyph']."/dob_line_x{$z}.png";
    writePng($g, $gp);
    $r = tessRaw($tesseractBin, $gp, 'mar+eng', 7);
    $evidence['B_glyph'][] = [
        'scale' => $z,
        'path' => $gp,
        'raw_text' => $r['text'],
        'confidence' => $r['conf'],
        'duration_ms' => $r['duration_ms'],
        'words' => $r['words'],
    ];

    // Draw word boxes
    $draw = clone $g;
    $draw->setImageColorspace(Imagick::COLORSPACE_RGB);
    $stroke = new ImagickDraw;
    $stroke->setStrokeColor(new ImagickPixel('red'));
    $stroke->setFillColor(new ImagickPixel('transparent'));
    $stroke->setStrokeWidth(max(1, (int) round($z / 2)));
    foreach ($r['words'] as $w) {
        $stroke->rectangle(
            $w['left'],
            $w['top'],
            $w['left'] + $w['width'],
            $w['top'] + $w['height']
        );
    }
    $draw->drawImage($stroke);
    writePng($draw, $dirs['boxes']."/dob_line_x{$z}_boxes.png");
    $draw->clear();
    $g->clear();
    echo "B glyph x{$z} raw=".json_encode(mb_substr(preg_replace('/\s+/u', ' ', $r['text']) ?? '', 0, 80), JSON_UNESCAPED_UNICODE)."\n";
}
$glyphBase->clear();

// Day-token micro crop (left portion of date after label) — empirical split of tight crop
$tw = $tightIm->getImageWidth();
$th = $tightIm->getImageHeight();
// Assume date digits occupy ~35–75% of tight width when label present
$dayCrop = clone $tightIm;
$dayCrop->cropImage((int) max(20, round($tw * 0.18)), $th, (int) round($tw * 0.38), 0);
$dayCrop->setImagePage(0, 0, 0, 0);
$dayPath = $dirs['glyph'].'/day_digits_guess.png';
writePng($dayCrop, $dayPath);
foreach ([1, 2, 4, 8] as $z) {
    $d = clone $dayCrop;
    if ($z > 1) {
        $d->resizeImage($d->getImageWidth() * $z, $d->getImageHeight() * $z, Imagick::FILTER_LANCZOS, 1);
    }
    $dp = $dirs['glyph']."/day_digits_x{$z}.png";
    writePng($d, $dp);
    $r = tessRaw($tesseractBin, $dp, 'mar+eng', 8);
    $evidence['B_glyph'][] = [
        'region' => 'day_digits_guess',
        'scale' => $z,
        'path' => $dp,
        'raw_text' => $r['text'],
        'confidence' => $r['conf'],
        'duration_ms' => $r['duration_ms'],
        'words' => $r['words'],
    ];
    $d->clear();
}
$dayCrop->clear();

// --- C: segmentation ---
$segments = [
    'full_page' => [0.0, 0.0, 1.0, 1.0],
    'dob_line' => [$tight['x0'], $tight['y0'], $tight['x1'], $tight['y1']],
    'date_token' => [max(0.0, $tight['x0'] + ($tight['x1'] - $tight['x0']) * 0.35), $tight['y0'], $tight['x1'], $tight['y1']],
    'day_digits' => [max(0.0, $tight['x0'] + ($tight['x1'] - $tight['x0']) * 0.35), $tight['y0'], min(1.0, $tight['x0'] + ($tight['x1'] - $tight['x0']) * 0.55), $tight['y1']],
    'month_digits' => [max(0.0, $tight['x0'] + ($tight['x1'] - $tight['x0']) * 0.52), $tight['y0'], min(1.0, $tight['x0'] + ($tight['x1'] - $tight['x0']) * 0.70), $tight['y1']],
    'year_only' => [max(0.0, $tight['x0'] + ($tight['x1'] - $tight['x0']) * 0.65), $tight['y0'], $tight['x1'], $tight['y1']],
];

foreach ($segments as $label => [$x0, $y0, $x1, $y1]) {
    $c = $label === 'full_page' ? clone $orig : cropRel($orig, $x0, $y0, $x1, $y1);
    $sp = $dirs['seg']."/{$label}.png";
    writePng($c, $sp);
    $c->clear();
    foreach ([6, 7, 8] as $psm) {
        $r = tessRaw($tesseractBin, $sp, 'mar+eng', $psm);
        $evidence['C_segmentation'][] = [
            'segment' => $label,
            'psm' => $psm,
            'path' => $sp,
            'raw_text' => $r['text'],
            'confidence' => $r['conf'],
            'duration_ms' => $r['duration_ms'],
        ];
    }
    echo "C seg={$label} psm6=".json_encode(mb_substr(preg_replace('/\s+/u', ' ', $evidence['C_segmentation'][array_key_last($evidence['C_segmentation']) - 2]['raw_text'] ?? ''), 0, 60), JSON_UNESCAPED_UNICODE)."\n";
}

// --- D: preprocess matrix on tight crop ---
$prepOps = [
    'raw' => static function (Imagick $im): void {},
    'grayscale' => static function (Imagick $im): void {
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
    },
    'adaptive_threshold' => static function (Imagick $im): void {
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->adaptiveThresholdImage(15, 15, (int) (0.05 * $im->getQuantum()));
    },
    'otsu' => static function (Imagick $im): void {
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->normalizeImage();
        // Approximate Otsu via auto-threshold where available
        if (method_exists($im, 'autoThresholdImage')) {
            $im->autoThresholdImage(Imagick::THRESHOLD_OTSU);
        } else {
            $im->thresholdImage(0.5 * $im->getQuantum());
        }
    },
    'morphology_open' => static function (Imagick $im): void {
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->normalizeImage();
        if (method_exists($im, 'autoThresholdImage')) {
            $im->autoThresholdImage(Imagick::THRESHOLD_OTSU);
        } else {
            $im->thresholdImage(0.5 * $im->getQuantum());
        }
        $kernel = ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '1');
        $im->morphology(Imagick::MORPHOLOGY_OPEN, 1, $kernel);
    },
    'sharpen' => static function (Imagick $im): void {
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->normalizeImage();
        $im->sharpenImage(0, 1.5);
    },
    'denoise' => static function (Imagick $im): void {
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->despeckleImage();
        $im->normalizeImage();
    },
    'clahe' => static function (Imagick $im): void {
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        if (method_exists($im, 'claheImage')) {
            $im->claheImage(8, 8, 128, 3);
        } else {
            $im->normalizeImage();
            $im->equalizeImage();
        }
    },
    'upscale_nn_2' => static function (Imagick $im): void {
        $im->resizeImage($im->getImageWidth() * 2, $im->getImageHeight() * 2, Imagick::FILTER_POINT, 1);
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->normalizeImage();
    },
    'upscale_lanczos_2' => static function (Imagick $im): void {
        $im->resizeImage($im->getImageWidth() * 2, $im->getImageHeight() * 2, Imagick::FILTER_LANCZOS, 1);
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->normalizeImage();
        $im->sharpenImage(0, 1.0);
    },
    'upscale_lanczos_3_clahe' => static function (Imagick $im): void {
        $im->resizeImage($im->getImageWidth() * 3, $im->getImageHeight() * 3, Imagick::FILTER_LANCZOS, 1);
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        if (method_exists($im, 'claheImage')) {
            $im->claheImage(8, 8, 128, 3);
        } else {
            $im->normalizeImage();
            $im->equalizeImage();
        }
        $im->sharpenImage(0, 1.2);
    },
    'dpi300_like' => static function (Imagick $im): void {
        // 720px ≈ phone capture; scale as if targeting ~300 DPI equivalent (~2x on this asset)
        $im->resizeImage($im->getImageWidth() * 2, $im->getImageHeight() * 2, Imagick::FILTER_LANCZOS, 1);
        $im->setImageResolution(300, 300);
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->normalizeImage();
    },
    'dpi400_like' => static function (Imagick $im): void {
        $im->resizeImage((int) round($im->getImageWidth() * 2.5), (int) round($im->getImageHeight() * 2.5), Imagick::FILTER_LANCZOS, 1);
        $im->setImageResolution(400, 400);
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->normalizeImage();
        $im->sharpenImage(0, 1.0);
    },
    'dpi600_like' => static function (Imagick $im): void {
        $im->resizeImage($im->getImageWidth() * 3, $im->getImageHeight() * 3, Imagick::FILTER_LANCZOS, 1);
        $im->setImageResolution(600, 600);
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->normalizeImage();
        $im->sharpenImage(0, 1.2);
    },
    'gray_otsu_morph_lanczos2' => static function (Imagick $im): void {
        $im->resizeImage($im->getImageWidth() * 2, $im->getImageHeight() * 2, Imagick::FILTER_LANCZOS, 1);
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->normalizeImage();
        if (method_exists($im, 'autoThresholdImage')) {
            $im->autoThresholdImage(Imagick::THRESHOLD_OTSU);
        } else {
            $im->thresholdImage(0.5 * $im->getQuantum());
        }
        $kernel = ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '1');
        $im->morphology(Imagick::MORPHOLOGY_CLOSE, 1, $kernel);
    },
    'red_channel_negate' => static function (Imagick $im): void {
        $im->separateImageChannel(Imagick::CHANNEL_RED);
        $im->normalizeImage();
        $im->negateImage(false);
    },
];

$bestPrep = ['score' => -1, 'op' => null, 'has21' => false, 'has24' => false];

foreach ($prepOps as $opName => $op) {
    $im = clone $tightIm;
    try {
        $op($im);
    } catch (Throwable $e) {
        $evidence['D_preprocess'][] = [
            'op' => $opName,
            'error' => $e->getMessage(),
        ];
        $im->clear();
        continue;
    }
    $pp = $dirs['prep']."/{$opName}.png";
    writePng($im, $pp);
    $im->clear();

    $rowBest = null;
    foreach ([6, 7, 8] as $psm) {
        $r = tessRaw($tesseractBin, $pp, 'mar+eng', $psm);
        $text = $r['text'];
        $has21 = (bool) preg_match('/(?:२१|21)/u', $text);
        $has24 = (bool) preg_match('/(?:२४|24)/u', $text);
        $has1999 = (bool) preg_match('/(?:१९९९|1999)/u', $text);
        $score = ($has1999 ? 2 : 0) + ($has24 || $has21 ? 1 : 0) + ($r['conf'] ?? 0) / 100;
        // Prefer any clean 21-without-24
        if ($has21 && ! $has24) {
            $score += 50;
        }
        $row = [
            'op' => $opName,
            'psm' => $psm,
            'path' => $pp,
            'raw_text' => $text,
            'confidence' => $r['conf'],
            'duration_ms' => $r['duration_ms'],
            'has_21' => $has21,
            'has_24' => $has24,
            'has_1999' => $has1999,
            'score' => $score,
        ];
        $evidence['D_preprocess'][] = $row;
        if ($rowBest === null || $score > $rowBest['score']) {
            $rowBest = $row;
        }
        if ($score > $bestPrep['score']) {
            $bestPrep = [
                'score' => $score,
                'op' => $opName,
                'psm' => $psm,
                'has21' => $has21,
                'has24' => $has24,
                'raw_text' => $text,
                'confidence' => $r['conf'],
            ];
        }
    }
    echo sprintf(
        "D op=%s best21=%s best24=%s snip=%s\n",
        $opName,
        ! empty($rowBest['has_21']) ? 'Y' : 'n',
        ! empty($rowBest['has_24']) ? 'Y' : 'n',
        json_encode(mb_substr(preg_replace('/\s+/u', ' ', $rowBest['raw_text'] ?? ''), 0, 70), JSON_UNESCAPED_UNICODE)
    );
}

$evidence['D_best'] = $bestPrep;

// --- E: pipeline audit evidence (code paths) ---
$evidence['E_pipeline'] = [
    'upload_store' => "IntakeCreationService::prepare → \$file->store('intakes')",
    'ocr_call' => 'OcrService::extractTextFromPath($path, $originalName) on storage/app/private/{path}',
    'image_branch' => 'TesseractMultiPassOcrService::extractFromImage($fullPath, ...) — original absolute path first variant',
    'preview_not_ocr' => 'Thumbnails/previews are separate; multipass may create derived scoring variants but file_path SSOT remains original',
    'this_probe_source' => $src,
    'this_probe_sha256' => $meta['sha256'],
    'multipass_final_input' => $mp['debug']['final_ocr_input_path'] ?? null,
    'multipass_original' => $mp['debug']['original_absolute_path'] ?? null,
];

$tightIm->clear();
$orig->clear();

$jsonPath = $outRoot.'/loop30_evidence_ab_cde.json';
file_put_contents($jsonPath, json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "WROTE $jsonPath\n";
echo 'TIGHT_CROP='.$tightPath."\n";
echo 'BEST_PREP='.json_encode($bestPrep, JSON_UNESCAPED_UNICODE)."\n";

$foundClean21 = false;
foreach (array_merge($evidence['A_tesseract'], $evidence['C_segmentation'], $evidence['D_preprocess']) as $row) {
    $t = $row['raw_text'] ?? '';
    if (preg_match('/जन्म[^\n]{0,40}(?:२१|21)/u', $t) && ! preg_match('/जन्म[^\n]{0,40}(?:२४|24)/u', $t)) {
        $foundClean21 = true;
        break;
    }
    if (! empty($row['has_21']) && empty($row['has_24']) && ($row['has_1999'] ?? false)) {
        $foundClean21 = true;
        break;
    }
}

echo $foundClean21 ? "LOOP30_TESS_FOUND_CLEAN_21\n" : "LOOP30_TESS_NO_CLEAN_21\n";
exit(0);
