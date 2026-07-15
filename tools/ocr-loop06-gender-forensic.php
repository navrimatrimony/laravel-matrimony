<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Ocr\OcrNormalize;
use App\Services\OcrService;

$metricsPath = storage_path('app/private/ocr-ensemble-benchmark/product_metrics_gt20_20260715_200824.json');
$metrics = json_decode(file_get_contents($metricsPath), true);
$batch = storage_path('app/ocr-dev-batches/Batch-001');
$ocr = app(OcrService::class);
$rows = [];

foreach ($metrics['files'] as $file) {
    $fn = $file['file'];
    $m = $file['fields']['gender'] ?? null;
    if ($m === null || ! empty($m['ok']) || ($m['truth'] ?? '') === '') {
        continue;
    }
    $truth = (string) $m['truth'];
    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
    $rel = 'intakes/_genfz_'.md5($fn).'.'.$ext;
    $abs = storage_path('app/private/'.$rel);
    if (! is_file($batch.DIRECTORY_SEPARATOR.$fn)) {
        continue;
    }
    copy($batch.DIRECTORY_SEPARATOR.$fn, $abs);
    $text = $ocr->extractTextFromPath($rel, $fn, null);
    @unlink($abs);
    $norm = OcrNormalize::normalizeDigits($text);
    $signals = [];
    foreach ([
        'mula_name' => '/मुलाचे\s+नां?व/u',
        'muli_name' => '/मुलीचे\s+नां?व/u',
        'mula_info' => '/मुलाची\s+माहिती/u',
        'muli_info' => '/मुलीची\s+माहिती/u',
        'vadhu' => '/वधू/u',
        'var' => '/वराचे\s+नां?व|\bवर\b/u',
        'ling' => '/लिंग|Gender/ui',
        'ms' => '/\bMs\.?\b|\bMiss\b|\bMrs\.?\b/ui',
        'mr' => '/\bMr\.?\b/ui',
        'purush' => '/पुरुष|\bmale\b/ui',
        'stri' => '/स्त्री|\bfemale\b/ui',
    ] as $k => $re) {
        if (preg_match($re, $norm) === 1) {
            $signals[] = $k;
        }
    }
    $mode = $signals !== [] ? 'B_in_raw' : 'A_weak_raw';
    $row = [
        'file' => $fn,
        'truth' => $truth,
        'pred' => $m['pred'] ?? null,
        'mode' => $mode,
        'signals' => $signals,
        'snip' => genderSnip($norm),
    ];
    $rows[] = $row;
    echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
}

function genderSnip(string $text): string
{
    if (preg_match('/.{0,20}(?:मुल[ाी][चची]|वधू|वर|लिंग|Gender|Ms\.|Mr\.|स्त्री|पुरुष).{0,40}/ui', $text, $m)) {
        return preg_replace('/\s+/u', ' ', $m[0]) ?? '';
    }

    return mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, 120);
}

$a = count(array_filter($rows, static fn ($r) => $r['mode'] === 'A_weak_raw'));
$b = count(array_filter($rows, static fn ($r) => $r['mode'] === 'B_in_raw'));
$path = storage_path('app/private/ocr-ensemble-benchmark/loop06_gender_forensic_'.date('Ymd_His').'.json');
file_put_contents($path, json_encode(['mode_A' => $a, 'mode_B' => $b, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "mode_A=$a mode_B=$b artifact=$path\n";
