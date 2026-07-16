<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleMobileSelector;
use App\Services\Ocr\OcrNormalize;
use App\Services\OcrService;

$metricsPath = storage_path('app/private/ocr-ensemble-benchmark/product_metrics_gt20_20260716_091807.json');
$metrics = json_decode(file_get_contents($metricsPath), true);
$batch = storage_path('app/ocr-dev-batches/Batch-001');
$ocr = app(OcrService::class);
$selector = app(OcrEnsembleMobileSelector::class);
$rows = [];

foreach ($metrics['files'] as $file) {
    $fn = $file['file'];
    $m = $file['fields']['primary_contact_number'] ?? null;
    if ($m === null || ! empty($m['ok']) || ($m['truth'] ?? '') === '') {
        continue;
    }
    $truth = preg_replace('/\D/', '', (string) $m['truth']);
    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
    $rel = 'intakes/_m08_'.md5($fn).'.'.$ext;
    $abs = storage_path('app/private/'.$rel);
    if (! is_file($batch.DIRECTORY_SEPARATOR.$fn)) {
        continue;
    }
    copy($batch.DIRECTORY_SEPARATOR.$fn, $abs);
    $text = $ocr->extractTextFromPath($rel, $fn, null);
    @unlink($abs);

    $digits = preg_replace('/\D+/u', '', OcrNormalize::normalizeDigits($text));
    $inRaw = $truth !== '' && str_contains($digits, $truth);
    $mode = $inRaw ? 'B_in_raw' : 'A_weak_raw';
    $lines = preg_split("/\R/u", $text) ?: [];
    $predNow = $selector->selectPrimary($lines);

    $phones = [];
    if (preg_match_all('/[6-9]\d{9}/u', $digits, $mm)) {
        $phones = array_values(array_unique($mm[0]));
    }

    $row = [
        'file' => $fn,
        'truth' => $truth,
        'pred_metric' => $m['pred'] ?? null,
        'pred_now' => $predNow,
        'mode' => $mode,
        'phones_in_raw' => $phones,
        'snip' => mobileSnip($text),
    ];
    $rows[] = $row;
    echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
}

function mobileSnip(string $text): string
{
    if (preg_match('/.{0,25}(?:मोब|मो\.|mobile|contact|संपर्क|[6-9]\d{4}).{0,40}/ui', $text, $m)) {
        return preg_replace('/\s+/u', ' ', $m[0]) ?? '';
    }

    return mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, 120);
}

$a = count(array_filter($rows, static fn ($r) => $r['mode'] === 'A_weak_raw'));
$b = count(array_filter($rows, static fn ($r) => $r['mode'] === 'B_in_raw'));
$path = storage_path('app/private/ocr-ensemble-benchmark/loop08_mobile_forensic_'.date('Ymd_His').'.json');
file_put_contents($path, json_encode(['mode_A' => $a, 'mode_B' => $b, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "mode_A=$a mode_B=$b artifact=$path\n";
