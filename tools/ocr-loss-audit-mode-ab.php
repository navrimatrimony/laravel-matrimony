<?php

/**
 * Loop 11 — Remaining production information loss audit.
 * Classifies each GT-20 critical miss as Mode A (truth weak/absent in raw OCR)
 * vs Mode B (truth tokens present; structured extract/select failed).
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Intake\OcrEnsemble\OcrEnsembleFieldExtractor;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Ocr\OcrNormalize;
use App\Services\OcrService;

$metricsPath = storage_path('app/private/ocr-ensemble-benchmark/product_metrics_gt20_20260716_101758.json');
$metrics = json_decode(file_get_contents($metricsPath), true);
$batch = storage_path('app/ocr-dev-batches/Batch-001');
$ocr = app(OcrService::class);
$extractor = app(OcrEnsembleFieldExtractor::class);

$fields = [
    'full_name' => static function (string $truth, string $norm): array {
        $core = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $truth) ?? $truth;
        $tokens = preg_split('/\s+/u', trim($core), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter($tokens, static fn ($t) => mb_strlen($t, 'UTF-8') >= 2));
        $hits = 0;
        foreach ($tokens as $tok) {
            if (mb_stripos($norm, $tok) !== false) {
                $hits++;
            }
        }
        $need = max(1, (int) ceil(count($tokens) * 0.5));

        return [$hits >= $need, $hits.'/'.count($tokens)];
    },
    'date_of_birth' => static function (string $truth, string $norm): array {
        // ISO truth → look for year + month/day signals
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $truth, $m)) {
            return [false, '0/0'];
        }
        $y = $m[1];
        $in = str_contains($norm, $y)
            || str_contains($norm, $m[2].'/'.$m[3])
            || str_contains($norm, $m[3].'/'.$m[2]);

        return [$in, $in ? '1/1' : '0/1'];
    },
    'primary_contact_number' => static function (string $truth, string $norm): array {
        $t = preg_replace('/\D/', '', $truth);
        $digits = preg_replace('/\D+/u', '', $norm);
        $in = $t !== '' && str_contains($digits, $t);

        return [$in, $in ? '1/1' : '0/1'];
    },
    'religion' => static function (string $truth, string $norm): array {
        $signals = [];
        if (preg_match('/हिंद|hindu|ह[ह]?ंद/ui', $norm)) {
            $signals[] = 'hindu';
        }
        if (preg_match('/मुस्लिम|muslim|जैन|jain|बौद्ध|christian|ख्रिस्/ui', $norm)) {
            $signals[] = 'other';
        }
        $in = $signals !== [];

        return [$in, $in ? '1/1' : '0/1'];
    },
    'gender' => static function (string $truth, string $norm): array {
        $female = preg_match('/मुलीचे|मुलीची|वधू|कुमारी|\bMs\.|\bMiss|\bMrs\.|स्त्री|female/ui', $norm) === 1;
        $male = preg_match('/मुलाचे|मुलाची|वराचे|\bMr\.|पुरुष|male|चि\./ui', $norm) === 1;
        $in = ($truth === 'female' && $female) || ($truth === 'male' && $male)
            || ($truth === 'female' && preg_match('/कु\./u', $norm) === 1);

        return [$in, $in ? '1/1' : '0/1'];
    },
];

$rows = [];
$modeA = 0;
$modeB = 0;
$byField = [];

foreach ($metrics['files'] as $file) {
    $fn = $file['file'];
    $missFields = [];
    foreach (array_keys($fields) as $fk) {
        $m = $file['fields'][$fk] ?? null;
        if ($m === null || ! empty($m['ok']) || ($m['truth'] ?? '') === '' || $m['truth'] === null) {
            continue;
        }
        $missFields[$fk] = $m;
    }
    if ($missFields === []) {
        continue;
    }

    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
    $rel = 'intakes/_loss_'.md5($fn).'.'.$ext;
    $abs = storage_path('app/private/'.$rel);
    if (! is_file($batch.DIRECTORY_SEPARATOR.$fn)) {
        continue;
    }
    copy($batch.DIRECTORY_SEPARATOR.$fn, $abs);
    $text = $ocr->extractTextFromPath($rel, $fn, null);
    @unlink($abs);
    $norm = OcrNormalize::normalizeDigits($text);
    $predMap = $extractor->extractFromText($text, OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR)->toFieldMap();

    foreach ($missFields as $fk => $m) {
        $truth = (string) $m['truth'];
        [$inRaw, $tokenHit] = $fields[$fk]($truth, $norm);
        $mode = $inRaw ? 'B_extract' : 'A_raw_ocr';
        if ($mode === 'A_raw_ocr') {
            $modeA++;
        } else {
            $modeB++;
        }
        $byField[$fk][$mode] = ($byField[$fk][$mode] ?? 0) + 1;
        $row = [
            'file' => $fn,
            'field' => $fk,
            'truth' => $truth,
            'pred_metric' => $m['pred'] ?? null,
            'pred_now' => $predMap[$fk] ?? null,
            'mode' => $mode,
            'token_hit' => $tokenHit,
        ];
        $rows[] = $row;
        echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
    }
}

$out = [
    'generated_at' => date('c'),
    'source_artifact' => basename($metricsPath),
    'mode_A_raw_ocr' => $modeA,
    'mode_B_extract' => $modeB,
    'dominant' => $modeA >= $modeB ? 'RAW_OCR' : 'STRUCTURED_EXTRACT',
    'by_field' => $byField,
    'rows' => $rows,
];
$path = storage_path('app/private/ocr-ensemble-benchmark/loss_audit_mode_ab_'.date('Ymd_His').'.json');
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "mode_A=$modeA mode_B=$modeB dominant={$out['dominant']} artifact=$path\n";
