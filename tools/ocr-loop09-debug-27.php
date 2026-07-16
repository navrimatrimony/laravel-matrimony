<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleMobileSelector;
use App\Services\Ocr\OcrNormalize;
use App\Services\OcrService;

$fn = '27.pdf';
$batch = storage_path('app/ocr-dev-batches/Batch-001');
$rel = 'intakes/_m27b.pdf';
$abs = storage_path('app/private/'.$rel);
copy($batch.DIRECTORY_SEPARATOR.$fn, $abs);
$text = app(OcrService::class)->extractTextFromPath($rel, $fn, null);
@unlink($abs);
$n = OcrNormalize::normalizeDigits($text);
echo (str_contains($n, '9940168213') ? 'truth_in_raw' : 'truth_missing').PHP_EOL;
echo (str_contains($n, '9604289289') ? 'wrong_in_raw' : 'wrong_missing').PHP_EOL;
if (preg_match('/.{0,50}9940168213.{0,50}/u', $n, $m)) {
    echo 'T:'.preg_replace('/\s+/u', ' ', $m[0]).PHP_EOL;
}
if (preg_match('/.{0,50}9604289289.{0,50}/u', $n, $m)) {
    echo 'W:'.preg_replace('/\s+/u', ' ', $m[0]).PHP_EOL;
}
$lines = preg_split("/\R/u", $text) ?: [];
echo 'pred='.app(OcrEnsembleMobileSelector::class)->selectPrimary($lines).PHP_EOL;
