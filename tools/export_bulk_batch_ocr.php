<?php

declare(strict_types=1);

/**
 * Export bulk batch OCR text for benchmark / review.
 *
 * Usage:
 *   php tools/export_bulk_batch_ocr.php 43
 *   php tools/export_bulk_batch_ocr.php 43 --out=/tmp/batch43
 *   php tools/export_bulk_batch_ocr.php 43 --format=json
 *   php tools/export_bulk_batch_ocr.php 43 --format=txt
 */
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$batchId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($batchId <= 0) {
    fwrite(STDERR, "Usage: php tools/export_bulk_batch_ocr.php <batch_id> [--out=DIR] [--format=json|txt]\n");
    exit(2);
}

$outDir = null;
$format = 'json';
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outDir = substr($arg, 6);
    } elseif (str_starts_with($arg, '--format=')) {
        $format = strtolower(substr($arg, 9));
    }
}

if (! in_array($format, ['json', 'txt'], true)) {
    fwrite(STDERR, "Invalid --format. Use json or txt.\n");
    exit(2);
}

/** @var \App\Models\BulkIntakeBatch|null $batch */
$batch = \App\Models\BulkIntakeBatch::query()
    ->with([
        'items' => static fn ($q) => $q->orderBy('item_sequence'),
        'items.biodataIntake.ocrAttempts',
    ])
    ->find($batchId);

if (! $batch) {
    fwrite(STDERR, "Batch not found: {$batchId}\n");
    exit(1);
}

if ($outDir === null) {
    $outDir = storage_path('app/private/ocr-exports/batch_'.$batchId.'_'.date('Ymd_His'));
}

if (! is_dir($outDir) && ! mkdir($outDir, 0755, true) && ! is_dir($outDir)) {
    fwrite(STDERR, "Could not create output directory: {$outDir}\n");
    exit(1);
}

$summary = [];
$index = 0;

foreach ($batch->items as $item) {
    $index++;
    $intake = $item->biodataIntake;
    $raw = trim((string) ($intake?->raw_ocr_text ?? ''));
    $primaryAttempt = $intake?->ocrAttempts
        ?->first(static fn ($a) => (bool) $a->is_primary)
        ?? $intake?->ocrAttempts?->sortBy('id')->first();

    $record = [
        'batch_id' => $batchId,
        'item_sequence' => (int) $item->item_sequence,
        'batch_item_id' => (int) $item->id,
        'item_status' => (string) $item->item_status,
        'original_filename' => (string) ($item->original_filename ?? $intake?->original_filename ?? ''),
        'intake_id' => $intake?->id,
        'parse_status' => $intake?->parse_status,
        'intake_status' => $intake?->intake_status,
        'raw_ocr_len' => strlen($raw),
        'raw_ocr_text' => $raw,
        'ocr_engine' => $primaryAttempt?->engine,
        'preprocessing_version' => $primaryAttempt?->preprocessing_version,
        'engine_meta_json' => $primaryAttempt?->engine_meta_json,
    ];

    $summary[] = array_diff_key($record, ['raw_ocr_text' => true]);

    $safeName = sprintf(
        '%02d_item%d_intake%s',
        $index,
        (int) $item->id,
        $intake?->id !== null ? (string) $intake->id : 'none'
    );

    if ($format === 'json') {
        $path = $outDir.DIRECTORY_SEPARATOR.$safeName.'.json';
        file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } else {
        $path = $outDir.DIRECTORY_SEPARATOR.$safeName.'.txt';
        $header = implode("\n", [
            'batch_id='.$batchId,
            'batch_item_id='.$item->id,
            'item_sequence='.$item->item_sequence,
            'item_status='.$item->item_status,
            'intake_id='.($intake?->id ?? 'null'),
            'original_filename='.($record['original_filename'] ?: 'n/a'),
            'raw_ocr_len='.$record['raw_ocr_len'],
            '--- raw_ocr_text ---',
        ]);
        file_put_contents($path, $header."\n".$raw."\n");
    }
}

$summaryPath = $outDir.DIRECTORY_SEPARATOR.'_summary.json';
file_put_contents(
    $summaryPath,
    json_encode([
        'batch_id' => $batchId,
        'batch_status' => $batch->batch_status ?? null,
        'exported_at' => now()->toIso8601String(),
        'item_count' => count($summary),
        'items' => $summary,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo "batch_id={$batchId}\n";
echo "items_exported=".count($summary)."\n";
echo "output_dir={$outDir}\n";
echo "summary={$summaryPath}\n";
