<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleMobileSelector;
use App\Services\Ocr\OcrNormalize;
use App\Services\OcrService;

$fn = 'photo_2026-06-05_10-32-45.jpg';
$batch = storage_path('app/ocr-dev-batches/Batch-001');
$rel = 'intakes/_mphoto.jpg';
$abs = storage_path('app/private/'.$rel);
copy($batch.DIRECTORY_SEPARATOR.$fn, $abs);
$text = app(OcrService::class)->extractTextFromPath($rel, $fn, null);
@unlink($abs);
$n = OcrNormalize::normalizeDigits($text);
echo (str_contains($n, '8805526197') ? 'truth_in' : 'truth_out').PHP_EOL;
echo (str_contains($n, '9209905005') ? 'wrong_in' : 'wrong_out').PHP_EOL;
if (preg_match('/.{0,60}8805526197.{0,40}/u', $n, $m)) {
    echo 'T:'.preg_replace('/\s+/u', ' ', $m[0]).PHP_EOL;
}
if (preg_match('/.{0,60}9209905005.{0,40}/u', $n, $m)) {
    echo 'W:'.preg_replace('/\s+/u', ' ', $m[0]).PHP_EOL;
}
$lines = preg_split("/\R/u", $text) ?: [];
echo 'pred='.app(OcrEnsembleMobileSelector::class)->selectPrimary($lines).PHP_EOL;
