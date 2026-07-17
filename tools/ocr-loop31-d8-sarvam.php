<?php

/**
 * Loop 31 — Run Sarvam Document Intelligence on D(8) ORIGINAL (+ optional crop).
 * Records RAW text, timing, confidence/meta. Invent forbidden.
 *
 * Usage: php tools/ocr-loop31-d8-sarvam.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\BiodataIntake;
use App\Services\AiVisionExtractionService;
use Illuminate\Support\Facades\File;

$outDir = storage_path('app/private/ocr-temp/d8-loop31');
if (! is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$key = trim((string) config('services.sarvam.subscription_key', ''));
$report = [
    'loop' => 31,
    'engine' => 'sarvam_document_intelligence',
    'available' => $key !== '',
    'unavailable_reason' => null,
    'runs' => [],
];

if ($key === '') {
    $report['unavailable_reason'] = 'services.sarvam.subscription_key empty (SARVAM_API_SUBSCRIPTION_KEY not set in env)';
    file_put_contents($outDir.'/loop31_sarvam_evidence.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "SARVAM_UNAVAILABLE: ".$report['unavailable_reason'].PHP_EOL;
    exit(2);
}

$src = storage_path('app/ocr-dev-batches/Batch-001/D (8).jpeg');
if (! is_file($src)) {
    fwrite(STDERR, "missing $src\n");
    exit(1);
}

// Stage a private-storage copy so AiVision path resolution matches production layout.
$rel = 'ocr-temp/d8-loop31/sarvam_input_original.jpeg';
$abs = storage_path('app/private/'.$rel);
File::ensureDirectoryExists(dirname($abs));
copy($src, $abs);

$targets = [
    ['label' => 'original_full', 'rel' => $rel, 'name' => 'D (8).jpeg'],
];

$crop = storage_path('app/private/ocr-temp/d8-loop31/crops2/original_dob_tight.png');
if (is_file($crop)) {
    $cropRel = 'ocr-temp/d8-loop31/sarvam_input_dob_tight.png';
    $cropAbs = storage_path('app/private/'.$cropRel);
    copy($crop, $cropAbs);
    $targets[] = ['label' => 'dob_tight', 'rel' => $cropRel, 'name' => 'dob_tight.png'];
}

// Prefer watermark-removed crop if present
$wmCrop = storage_path('app/private/ocr-temp/d8-loop31/crops2/hsv_left_inpaint_dob_tight.png');
if (is_file($wmCrop)) {
    $wmRel = 'ocr-temp/d8-loop31/sarvam_input_hsv_left_dob_tight.png';
    copy($wmCrop, storage_path('app/private/'.$wmRel));
    $targets[] = ['label' => 'hsv_left_inpaint_dob_tight', 'rel' => $wmRel, 'name' => 'hsv_left_dob_tight.png'];
}

$band = storage_path('app/private/ocr-temp/d8-loop31/crops2/original_dob_band.png');
if (is_file($band)) {
    $bandRel = 'ocr-temp/d8-loop31/sarvam_input_dob_band.png';
    copy($band, storage_path('app/private/'.$bandRel));
    $targets[] = ['label' => 'dob_band', 'rel' => $bandRel, 'name' => 'dob_band.png'];
}

$ai = app(AiVisionExtractionService::class);
$ref = new ReflectionClass($ai);
$method = $ref->getMethod('extractViaSarvamDocumentIntelligence');
$method->setAccessible(true);

foreach ($targets as $t) {
    $absPath = storage_path('app/private/'.$t['rel']);
    echo "SARVAM_RUN ".$t['label']." ...\n";
    $started = hrtime(true);
    try {
        $result = $method->invoke($ai, $absPath, $t['name'], [
            'loop' => 31,
            'label' => $t['label'],
        ]);
        $ms = (int) round((hrtime(true) - $started) / 1e6);
        $text = is_array($result) ? (string) ($result['text'] ?? '') : (string) $result;
        $meta = is_array($result) ? ($result['meta'] ?? []) : [];
        $row = [
            'label' => $t['label'],
            'path' => $absPath,
            'raw_text' => $text,
            'duration_ms' => $ms,
            'confidence' => $meta['confidence'] ?? $meta['avg_confidence'] ?? null,
            'meta' => $meta,
            'has_21' => (bool) preg_match('/(?:२१|21)/u', $text),
            'has_24' => (bool) preg_match('/(?:२४|24)/u', $text),
            'has_21_03_1999' => (bool) preg_match('/(?:२१\s*[\/.\-]?\s*०३\s*[\/.\-]?\s*१९९९|21\s*[\/.\-]?\s*03\s*[\/.\-]?\s*1999)/u', $text),
            'has_24_03_1999' => (bool) preg_match('/(?:२४\s*[\/.\-]?\s*०३\s*[\/.\-]?\s*१९९९|24\s*[\/.\-]?\s*03\s*[\/.\-]?\s*1999)/u', $text),
            'error' => empty($meta['ok']) ? ($meta['reason'] ?? $meta['error'] ?? null) : null,
        ];
        if (preg_match('/जन्म[^\n]{0,80}/u', $text, $m)) {
            $row['dobish'] = $m[0];
        }
        // Persist full raw for audit
        file_put_contents($outDir.'/sarvam_'.$t['label'].'_raw.txt', $text);
    } catch (Throwable $e) {
        $ms = (int) round((hrtime(true) - $started) / 1e6);
        $row = [
            'label' => $t['label'],
            'path' => $absPath,
            'raw_text' => null,
            'duration_ms' => $ms,
            'confidence' => null,
            'error' => $e->getMessage(),
        ];
    }
    $report['runs'][] = $row;
    echo '  ms='.$row['duration_ms']
        .' has21='.json_encode($row['has_21'] ?? null)
        .' has24='.json_encode($row['has_24'] ?? null)
        .' err='.json_encode($row['error'] ?? null)
        .' snip='.json_encode(mb_substr(preg_replace('/\s+/u', ' ', (string) ($row['raw_text'] ?? '')), 0, 120), JSON_UNESCAPED_UNICODE)
        .PHP_EOL;
}

file_put_contents($outDir.'/loop31_sarvam_evidence.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "WROTE ".$outDir."/loop31_sarvam_evidence.json\n";

$anyRepro = false;
foreach ($report['runs'] as $run) {
    if (! empty($run['has_21_03_1999']) && empty($run['has_24'])) {
        $anyRepro = true;
    }
}
echo $anyRepro ? "SARVAM_REPRO_21_03_1999\n" : "SARVAM_NO_CLEAN_21_03_1999\n";
exit(0);
