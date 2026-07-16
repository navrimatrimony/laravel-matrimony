<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleNameExtractor;
use App\Services\OcrService;

$fn = 'photo_2026-06-05_10-33-15.jpg';
$batch = storage_path('app/ocr-dev-batches/Batch-001');
$rel = 'intakes/_nmreka.jpg';
$abs = storage_path('app/private/'.$rel);
copy($batch.DIRECTORY_SEPARATOR.$fn, $abs);
$text = app(OcrService::class)->extractTextFromPath($rel, $fn, null);
@unlink($abs);
$lines = preg_split("/\R/u", $text) ?: [];
echo "pred=".app(OcrEnsembleNameExtractor::class)->extract($lines).PHP_EOL;
foreach ($lines as $i => $line) {
    if (preg_match('/रेखा|बायो|नाव|शिवदास/u', $line)) {
        echo sprintf("[%02d] %s\n", $i, preg_replace('/\s+/u', ' ', $line));
    }
}
