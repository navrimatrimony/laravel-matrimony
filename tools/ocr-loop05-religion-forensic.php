<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleCommunityExtractor;
use App\Services\Ocr\OcrNormalize;
use App\Services\OcrService;

$metricsPath = storage_path('app/private/ocr-ensemble-benchmark/product_metrics_gt20_20260715_194518.json');
$metrics = json_decode(file_get_contents($metricsPath), true);
$batch = storage_path('app/ocr-dev-batches/Batch-001');
$ocr = app(OcrService::class);
$extractor = app(OcrEnsembleCommunityExtractor::class);
$rows = [];

foreach ($metrics['files'] as $file) {
    $fn = $file['file'];
    $m = $file['fields']['religion'] ?? null;
    if ($m === null || ! empty($m['ok']) || ($m['truth'] ?? '') === '') {
        continue;
    }
    $truth = (string) $m['truth'];
    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
    $rel = 'intakes/_relfz_'.md5($fn).'.'.$ext;
    $abs = storage_path('app/private/'.$rel);
    if (! is_file($batch.DIRECTORY_SEPARATOR.$fn)) {
        echo json_encode(['file' => $fn, 'error' => 'missing'], JSON_UNESCAPED_UNICODE)."\n";
        continue;
    }
    copy($batch.DIRECTORY_SEPARATOR.$fn, $abs);
    $text = $ocr->extractTextFromPath($rel, $fn, null);
    @unlink($abs);

    $norm = OcrNormalize::normalizeDigits($text);
    $signals = [];
    if (preg_match('/हिंद[ुू]|hindu/ui', $norm)) {
        $signals[] = 'hindu_token';
    }
    if (preg_match('/धर्म|religion/ui', $norm)) {
        $signals[] = 'religion_label';
    }
    if (preg_match('/जात[िी]?हंद|जात\s*[:：]?\s*हंद/ui', $norm)) {
        $signals[] = 'jati_hindu_glued';
    }
    if (preg_match('/मुस्लिम|muslim|जैन|jain|बौद्ध|buddhist|ख्रिश्चन|christian/ui', $norm)) {
        $signals[] = 'other_religion';
    }

    $mode = $signals !== [] ? 'B_in_raw' : 'A_weak_raw';
    $lines = preg_split("/\R/u", $text) ?: [];
    $predNow = $extractor->extract($lines)['religion'] ?? null;

    $row = [
        'file' => $fn,
        'truth' => $truth,
        'pred_metric' => $m['pred'] ?? null,
        'pred_now' => $predNow,
        'mode' => $mode,
        'signals' => $signals,
        'snip' => religionSnip($norm),
    ];
    $rows[] = $row;
    echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
}

function religionSnip(string $text): string
{
    if (preg_match('/.{0,25}(?:धर्म|Religion|जात|हिंद|Hindu|जाति).{0,45}/ui', $text, $m)) {
        return preg_replace('/\s+/u', ' ', $m[0]) ?? '';
    }

    return mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, 120);
}

$a = count(array_filter($rows, static fn ($r) => $r['mode'] === 'A_weak_raw'));
$b = count(array_filter($rows, static fn ($r) => $r['mode'] === 'B_in_raw'));
$path = storage_path('app/private/ocr-ensemble-benchmark/loop05_religion_forensic_'.date('Ymd_His').'.json');
file_put_contents($path, json_encode(['mode_A' => $a, 'mode_B' => $b, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "mode_A=$a mode_B=$b artifact=$path\n";
