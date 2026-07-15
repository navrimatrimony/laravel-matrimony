<?php

/**
 * Product Metrics — GT-20 remasure (production OCR + Phase 3 extractor).
 * Usage: php tools/ocr-product-metrics-gt20.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Intake\OcrEnsemble\OcrEnsembleFieldExtractor;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsembleBenchmarkFieldExtractor;
use App\Services\Intake\OcrEnsembleBenchmarkFieldMatcher;
use App\Services\OcrService;

$scorePath = storage_path('app/private/ocr-ensemble-benchmark/sprint2_gt20_score_20260715_130342.json');
$batch = storage_path('app/ocr-dev-batches/Batch-001');
$score = json_decode(file_get_contents($scorePath), true);
$items = $score['tesseract']['items'] ?? [];
$fields = OcrEnsembleBenchmarkFieldExtractor::CRITICAL_FIELDS;
$ocr = app(OcrService::class);
$ex = app(OcrEnsembleFieldExtractor::class);
$key = OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR;

$counts = [];
$ok = [];
foreach ($fields as $f) {
    $counts[$f] = 0;
    $ok[$f] = 0;
}
$pdfDob = ['n' => 0, 'ok' => 0];
$criticalOk = 0;
$criticalN = 0;
$perFile = [];

foreach ($items as $fn => $row) {
    $src = $batch.DIRECTORY_SEPARATOR.$fn;
    if (! is_file($src)) {
        continue;
    }
    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
    $rel = 'intakes/_metrics_'.md5($fn).'.'.$ext;
    $abs = storage_path('app/private/'.$rel);
    if (! is_dir(dirname($abs))) {
        mkdir(dirname($abs), 0755, true);
    }
    copy($src, $abs);
    $text = $ocr->extractTextFromPath($rel, $fn, null);
    $predDto = $ex->extractFromText($text, $key);
    @unlink($abs);

    $fileRow = ['file' => $fn, 'fields' => []];
    $isPdf = $ext === 'pdf';
    foreach ($fields as $f) {
        $truth = $row['fields'][$f]['truth'] ?? null;
        if ($truth === null || $truth === '') {
            continue;
        }
        $counts[$f]++;
        $criticalN++;
        $pred = $predDto->field($f);
        $match = OcrEnsembleBenchmarkFieldMatcher::match($f, (string) $truth, $pred !== null ? (string) $pred : null);
        if ($match) {
            $ok[$f]++;
            $criticalOk++;
        }
        $fileRow['fields'][$f] = ['truth' => $truth, 'pred' => $pred, 'ok' => $match];
        if ($isPdf && $f === 'date_of_birth') {
            $pdfDob['n']++;
            if ($match) {
                $pdfDob['ok']++;
            }
        }
    }
    $perFile[] = $fileRow;
    echo ($fileRow['fields']['date_of_birth']['ok'] ?? false ? 'OK' : 'MISS')." DOB $fn\n";
}

$pct = static function (int $a, int $b): float {
    return $b > 0 ? round(100 * $a / $b, 1) : 0.0;
};

$out = [
    'generated_at' => date('c'),
    'pipeline' => 'production_multipass_phase3_extractor',
    'critical_accuracy' => [
        'ok' => $criticalOk,
        'n' => $criticalN,
        'pct' => $pct($criticalOk, $criticalN),
    ],
    'fields' => [],
    'pdf_dob' => [
        'ok' => $pdfDob['ok'],
        'n' => $pdfDob['n'],
        'pct' => $pct($pdfDob['ok'], $pdfDob['n']),
    ],
    'baseline_sprint2' => [
        'critical_pct' => 42.11,
        'date_of_birth' => 25.0,
        'full_name' => 30.0,
        'primary_contact_number' => 55.6,
        'religion' => 47.1,
        'gender' => 55.0,
    ],
    'files' => $perFile,
];

foreach ($fields as $f) {
    $out['fields'][$f] = [
        'ok' => $ok[$f],
        'n' => $counts[$f],
        'pct' => $pct($ok[$f], $counts[$f]),
    ];
}

$stamp = date('Ymd_His');
$dir = storage_path('app/private/ocr-ensemble-benchmark');
$path = $dir.'/product_metrics_gt20_'.$stamp.'.json';
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "critical={$out['critical_accuracy']['pct']}% DOB={$out['fields']['date_of_birth']['pct']}% pdf_dob={$out['pdf_dob']['pct']}%\n";
echo "artifact=$path\n";
