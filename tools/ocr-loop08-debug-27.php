<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleMobileSelector;
use App\Services\OcrService;

$fn = '27.pdf';
$batch = storage_path('app/ocr-dev-batches/Batch-001');
$rel = 'intakes/_m27dbg.pdf';
$abs = storage_path('app/private/'.$rel);
copy($batch.DIRECTORY_SEPARATOR.$fn, $abs);
$text = app(OcrService::class)->extractTextFromPath($rel, $fn, null);
@unlink($abs);
$lines = preg_split("/\R/u", $text) ?: [];
$phone = app(OcrEnsembleMobileSelector::class)->selectPrimary($lines);
echo "pred=$phone\n";
foreach ($lines as $i => $line) {
    if (preg_match('/मोब|मो\.|संपक|mobile|phone|contact|[6-9]\d{9}/ui', $line)) {
        echo sprintf("[%02d] %s\n", $i, preg_replace('/\s+/u', ' ', $line));
    }
}
