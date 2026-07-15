<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\OcrService;

$metricsPath = storage_path('app/private/ocr-ensemble-benchmark/product_metrics_gt20_20260715_191214.json');
$metrics = json_decode(file_get_contents($metricsPath), true);
$batch = storage_path('app/ocr-dev-batches/Batch-001');
$ocr = app(OcrService::class);
$rows = [];

foreach ($metrics['files'] as $file) {
    $fn = $file['file'];
    $m = $file['fields']['primary_contact_number'] ?? null;
    if ($m === null || ! empty($m['ok']) || ($m['truth'] ?? '') === '') {
        continue;
    }
    $truth = preg_replace('/\D/', '', (string) $m['truth']);
    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
    $rel = 'intakes/_mobfz_'.md5($fn).'.'.$ext;
    $abs = storage_path('app/private/'.$rel);
    copy($batch.DIRECTORY_SEPARATOR.$fn, $abs);
    $text = $ocr->extractTextFromPath($rel, $fn, null);
    @unlink($abs);

    $digits = preg_replace('/\D+/u', '', OcrNormalizeDigits($text));
    $inRaw = $truth !== '' && str_contains($digits, $truth);
    // also last 10
    $inRaw10 = strlen($truth) >= 10 && str_contains($digits, substr($truth, -10));
    $mode = ($inRaw || $inRaw10) ? 'B_in_raw' : 'A_weak_raw';
    $row = [
        'file' => $fn,
        'truth' => $truth,
        'pred' => $m['pred'] ?? null,
        'mode' => $mode,
        'snip' => mobileSnip($text),
    ];
    $rows[] = $row;
    echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
}

function OcrNormalizeDigits(string $t): string
{
    return \App\Services\Ocr\OcrNormalize::normalizeDigits($t);
}

function mobileSnip(string $text): string
{
    if (preg_match('/.{0,20}(?:मोब|mobile|contact|संपर्क|\d{5}).{0,40}/ui', $text, $m)) {
        return preg_replace('/\s+/u', ' ', $m[0]) ?? '';
    }

    return mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, 120);
}

$a = count(array_filter($rows, static fn ($r) => $r['mode'] === 'A_weak_raw'));
$b = count(array_filter($rows, static fn ($r) => $r['mode'] === 'B_in_raw'));
$path = storage_path('app/private/ocr-ensemble-benchmark/loop04_mobile_forensic_'.date('Ymd_His').'.json');
file_put_contents($path, json_encode(['mode_A' => $a, 'mode_B' => $b, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "mode_A=$a mode_B=$b artifact=$path\n";
