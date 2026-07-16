<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Intake\OcrEnsemble\OcrEnsembleFieldExtractor;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\OcrService;

$file = $argv[1] ?? '28.pdf';
$src = storage_path('app/ocr-dev-batches/Batch-001/'.$file);
$rel = 'intakes/_quick_'.md5($file).'.'.pathinfo($file, PATHINFO_EXTENSION);
$abs = storage_path('app/private/'.$rel);
copy($src, $abs);
$text = app(OcrService::class)->extractTextFromPath($rel, $file, null);
@unlink($abs);
$dto = app(OcrEnsembleFieldExtractor::class)->extractFromText($text, OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR);
echo 'religion='.json_encode($dto->field('religion'), JSON_UNESCAPED_UNICODE).PHP_EOL;
