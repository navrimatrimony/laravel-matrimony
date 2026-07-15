<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\OcrService;

$metrics = json_decode(file_get_contents(storage_path('app/private/ocr-ensemble-benchmark/product_metrics_gt20_20260715_181117.json')), true);
$batch = storage_path('app/ocr-dev-batches/Batch-001');
$ocr = app(OcrService::class);
$rows = [];

foreach ($metrics['files'] as $file) {
    $fn = $file['file'];
    $dob = $file['fields']['full_name'] ?? null;
    if ($dob === null || ! empty($dob['ok'])) {
        continue;
    }
    $truth = (string) ($dob['truth'] ?? '');
    $pred = $dob['pred'] ?? null;
    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
    $rel = 'intakes/_namefz_'.md5($fn).'.'.$ext;
    $abs = storage_path('app/private/'.$rel);
    copy($batch.DIRECTORY_SEPARATOR.$fn, $abs);
    $text = $ocr->extractTextFromPath($rel, $fn, null);
    @unlink($abs);

    $norm = static function (string $s): string {
        $s = mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)) ?? '');
        $s = str_replace(['कु.', 'चि.', 'श्री.', 'सौ.', 'ku.', 'chi.'], '', $s);

        return trim($s);
    };
    $tNorm = $norm($truth);
    $tokens = preg_split('/\s+/u', $tNorm) ?: [];
    $tokens = array_values(array_filter($tokens, static fn ($t) => mb_strlen($t) >= 3));
    $inRaw = 0;
    foreach ($tokens as $tok) {
        if ($tok !== '' && mb_stripos($text, $tok) !== false) {
            $inRaw++;
        }
    }
    $ratio = count($tokens) > 0 ? $inRaw / count($tokens) : 0;
    $mode = $ratio >= 0.5 ? 'B_in_raw' : 'A_weak_raw';
    $row = [
        'file' => $fn,
        'truth' => $truth,
        'pred' => $pred,
        'token_hits' => $inRaw.'/'.count($tokens),
        'mode' => $mode,
        'snip' => mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, 160),
    ];
    $rows[] = $row;
    echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
}

$a = count(array_filter($rows, static fn ($r) => $r['mode'] === 'A_weak_raw'));
$b = count(array_filter($rows, static fn ($r) => $r['mode'] === 'B_in_raw'));
$out = ['generated_at' => date('c'), 'mode_A' => $a, 'mode_B' => $b, 'rows' => $rows];
$path = storage_path('app/private/ocr-ensemble-benchmark/loop03_name_forensic_'.date('Ymd_His').'.json');
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "mode_A=$a mode_B=$b artifact=$path\n";
