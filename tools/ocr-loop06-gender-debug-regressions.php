<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleGenderExtractor;
use App\Services\OcrService;
use App\Services\Parsing\MarathiOcrFieldRescueService;

$ocr = app(OcrService::class);
$g = app(OcrEnsembleGenderExtractor::class);
$rescue = app(MarathiOcrFieldRescueService::class);
$batch = storage_path('app/ocr-dev-batches/Batch-001');

foreach (['photo_2026-06-06_14-44-04.jpg', 'photo_2026-02-12_21-53-42.jpg', '1.1.jpeg'] as $fn) {
    $ext = pathinfo($fn, PATHINFO_EXTENSION);
    $rel = 'intakes/_gdbg_'.md5($fn).'.'.$ext;
    $abs = storage_path('app/private/'.$rel);
    copy($batch.DIRECTORY_SEPARATOR.$fn, $abs);
    $text = $ocr->extractTextFromPath($rel, $fn, null);
    @unlink($abs);
    $lines = preg_split("/\R/u", $text) ?: [];
    $core = $rescue->rescueCoreFields($lines, []);
    echo "=== $fn ===\n";
    echo 'rescue='.json_encode($core['gender'] ?? null)
        .' ensemble='.json_encode($g->extract($lines, $core['gender'] ?? null))."\n";
    if (preg_match('/.{0,40}(?:कु\.|मुली|मुला|Ms\.|नाव|Gender|लिंग).{0,50}/ui', $text, $m)) {
        echo 'snip='.preg_replace('/\s+/u', ' ', $m[0])."\n";
    }
}
