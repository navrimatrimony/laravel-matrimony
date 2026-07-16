<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleNameExtractor;
use App\Services\Ocr\OcrNormalize;
use App\Services\OcrService;

$metricsPath = storage_path('app/private/ocr-ensemble-benchmark/product_metrics_gt20_20260715_212444.json');
$metrics = json_decode(file_get_contents($metricsPath), true);
$batch = storage_path('app/ocr-dev-batches/Batch-001');
$ocr = app(OcrService::class);
$extractor = app(OcrEnsembleNameExtractor::class);
$rows = [];

foreach ($metrics['files'] as $file) {
    $fn = $file['file'];
    $m = $file['fields']['full_name'] ?? null;
    if ($m === null || ! empty($m['ok']) || ($m['truth'] ?? '') === '') {
        continue;
    }
    $truth = (string) $m['truth'];
    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
    $rel = 'intakes/_nmfz_'.md5($fn).'.'.$ext;
    $abs = storage_path('app/private/'.$rel);
    if (! is_file($batch.DIRECTORY_SEPARATOR.$fn)) {
        echo json_encode(['file' => $fn, 'error' => 'missing'], JSON_UNESCAPED_UNICODE)."\n";
        continue;
    }
    copy($batch.DIRECTORY_SEPARATOR.$fn, $abs);
    $text = $ocr->extractTextFromPath($rel, $fn, null);
    @unlink($abs);

    $norm = OcrNormalize::normalizeDigits($text);
    $truthCore = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $truth) ?? $truth;
    $truthTokens = preg_split('/\s+/u', trim($truthCore), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $tokensInRaw = 0;
    foreach ($truthTokens as $tok) {
        if (mb_strlen($tok, 'UTF-8') >= 2 && mb_stripos($norm, $tok) !== false) {
            $tokensInRaw++;
        }
    }
    $mode = ($truthTokens !== [] && $tokensInRaw >= max(1, (int) ceil(count($truthTokens) * 0.5)))
        ? 'B_in_raw'
        : 'A_weak_raw';

    $lines = preg_split("/\R/u", $text) ?: [];
    $predNow = $extractor->extract($lines);

    $row = [
        'file' => $fn,
        'truth' => $truth,
        'pred_metric' => $m['pred'] ?? null,
        'pred_now' => $predNow,
        'mode' => $mode,
        'tokens_in_raw' => $tokensInRaw.'/'.count($truthTokens),
        'snip' => nameSnip($norm, $truthTokens),
    ];
    $rows[] = $row;
    echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
}

function nameSnip(string $text, array $tokens): string
{
    if (preg_match('/.{0,25}(?:नाव|नांव|Name|मुलाचे|मुलीचे|कु\.|चि\.).{0,50}/ui', $text, $m)) {
        return preg_replace('/\s+/u', ' ', $m[0]) ?? '';
    }
    foreach ($tokens as $tok) {
        if (mb_strlen($tok, 'UTF-8') >= 3 && preg_match('/.{0,20}'.preg_quote($tok, '/').'.{0,30}/ui', $text, $m)) {
            return preg_replace('/\s+/u', ' ', $m[0]) ?? '';
        }
    }

    return mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, 140);
}

$a = count(array_filter($rows, static fn ($r) => $r['mode'] === 'A_weak_raw'));
$b = count(array_filter($rows, static fn ($r) => $r['mode'] === 'B_in_raw'));
$path = storage_path('app/private/ocr-ensemble-benchmark/loop07_name_forensic_'.date('Ymd_His').'.json');
file_put_contents($path, json_encode(['mode_A' => $a, 'mode_B' => $b, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "mode_A=$a mode_B=$b artifact=$path\n";
