<?php

/**
 * Tier A residual-pack replay (revised OCR workflow).
 *
 * Replays extractor against cached OCR text for current misses + canaries.
 * Does NOT re-OCR unless --refresh-cache is passed.
 *
 * Usage:
 *   php tools/ocr-residual-pack.php [baseline_metrics.json] [--refresh-cache]
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Intake\OcrEnsemble\OcrEnsembleFieldExtractor;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsembleBenchmarkFieldMatcher;
use App\Services\OcrService;

$baselinePath = $argv[1] ?? storage_path('app/private/ocr-ensemble-benchmark/product_metrics_gt20_20260717_092259.json');
$refresh = in_array('--refresh-cache', $argv, true);

if (! is_file($baselinePath)) {
    fwrite(STDERR, "baseline missing: $baselinePath\n");
    exit(1);
}

$baseline = json_decode(file_get_contents($baselinePath), true);
$batch = storage_path('app/ocr-dev-batches/Batch-001');
$cacheDir = storage_path('app/private/ocr-ensemble-benchmark/raw-cache');
if (! is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$missFiles = [];
$canaries = [];
foreach ($baseline['files'] as $fileRow) {
    $fn = $fileRow['file'];
    $hasMiss = false;
    foreach ($fileRow['fields'] as $field => $row) {
        if (empty($row['ok']) && ($row['truth'] ?? '') !== '') {
            $hasMiss = true;
            break;
        }
    }
    if ($hasMiss) {
        $missFiles[$fn] = $fileRow;
    } else {
        $canaries[$fn] = $fileRow;
    }
}

// Keep 5 canaries that cover PDF + image diversity.
$canaryPick = array_slice(array_keys($canaries), 0, 5, true);
$pack = $missFiles + array_intersect_key($canaries, array_flip($canaryPick));

$ocr = app(OcrService::class);
$ex = app(OcrEnsembleFieldExtractor::class);
$key = OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR;

$gains = [];
$losses = [];
$unchangedMiss = [];
$canaryOk = 0;
$canaryN = 0;

foreach ($pack as $fn => $fileRow) {
    $cachePath = $cacheDir.'/'.md5($fn).'.txt';
    $metaPath = $cacheDir.'/'.md5($fn).'.meta.json';

    if ($refresh || ! is_file($cachePath)) {
        $src = $batch.DIRECTORY_SEPARATOR.$fn;
        if (! is_file($src)) {
            echo "SKIP missing file $fn\n";
            continue;
        }
        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
        $rel = 'intakes/_residual_'.md5($fn).'.'.$ext;
        $abs = storage_path('app/private/'.$rel);
        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0755, true);
        }
        copy($src, $abs);
        $text = $ocr->extractTextFromPath($rel, $fn, null);
        @unlink($abs);
        file_put_contents($cachePath, $text);
        file_put_contents($metaPath, json_encode([
            'file' => $fn,
            'cached_at' => date('c'),
            'policy' => \App\Services\Ocr\TesseractMultiPassOcrService::SELECTION_POLICY_VERSION,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "CACHE $fn\n";
    }

    $text = file_get_contents($cachePath);
    $predDto = $ex->extractFromText($text, $key);
    $isMissFile = isset($missFiles[$fn]);

    foreach ($fileRow['fields'] as $field => $row) {
        $truth = $row['truth'] ?? null;
        if ($truth === null || $truth === '') {
            continue;
        }
        $was = ! empty($row['ok']);
        $pred = $predDto->field($field);
        $now = OcrEnsembleBenchmarkFieldMatcher::match($field, (string) $truth, $pred !== null ? (string) $pred : null);

        if (! $isMissFile) {
            $canaryN++;
            if ($now) {
                $canaryOk++;
            } elseif ($was && ! $now) {
                $losses[] = ['file' => $fn, 'field' => $field, 'truth' => $truth, 'pred' => $pred, 'canary' => true];
            }
            continue;
        }

        if (! $was && $now) {
            $gains[] = ['file' => $fn, 'field' => $field, 'truth' => $truth, 'pred' => $pred];
        } elseif ($was && ! $now) {
            $losses[] = ['file' => $fn, 'field' => $field, 'truth' => $truth, 'pred' => $pred];
        } elseif (! $was && ! $now) {
            $unchangedMiss[] = ['file' => $fn, 'field' => $field, 'truth' => $truth, 'pred' => $pred];
        }
    }
}

echo 'TIER_A gains='.count($gains).' losses='.count($losses)
    .' unchanged_miss='.count($unchangedMiss)
    .' canary='.$canaryOk.'/'.$canaryN.PHP_EOL;
foreach ($gains as $g) {
    echo 'GAIN '.json_encode($g, JSON_UNESCAPED_UNICODE).PHP_EOL;
}
foreach ($losses as $l) {
    echo 'LOSS '.json_encode($l, JSON_UNESCAPED_UNICODE).PHP_EOL;
}
foreach ($unchangedMiss as $u) {
    echo 'MISS '.json_encode($u, JSON_UNESCAPED_UNICODE).PHP_EOL;
}

$pass = count($losses) === 0 && count($gains) > 0;
echo ($pass ? 'TIER_A_PASS' : 'TIER_A_FAIL_OR_NULL').PHP_EOL;
exit($pass ? 0 : 2);
