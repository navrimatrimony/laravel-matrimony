<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Intake\OcrEnsemble\OcrEnsembleFieldExtractor;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\OcrService;

$file = $argv[1] ?? 'snehal.jpeg';
$src = storage_path('app/ocr-dev-batches/Batch-001/'.$file);
$rel = 'intakes/_x_'.md5($file).'.'.pathinfo($file, PATHINFO_EXTENSION);
$abs = storage_path('app/private/'.$rel);
if (! is_dir(dirname($abs))) {
    mkdir(dirname($abs), 0755, true);
}
copy($src, $abs);
$ocr = app(OcrService::class);
$text = $ocr->extractTextFromPath($rel, $file, null);
$dbg = $ocr->getLastExtractTextFromPathDebug() ?? [];
@unlink($abs);

echo 'has_snehal='.(mb_stripos($text, 'स्नेहल') !== false ? 'Y' : 'N').PHP_EOL;
echo 'chosen='.json_encode([
    'variant' => $dbg['chosen_variant'] ?? null,
    'psm' => $dbg['chosen_psm'] ?? null,
    'preset' => $dbg['preset_resolved'] ?? null,
    'score' => $dbg['score'] ?? null,
    'lang' => $dbg['chosen_language'] ?? null,
], JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'attempts='.json_encode($dbg['attempts_summary'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
echo 'preview='.mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, 300).PHP_EOL;
$ex = app(OcrEnsembleFieldExtractor::class);
$dto = $ex->extractFromText($text, OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR);
echo 'name='.json_encode($dto->field('full_name'), JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'gender='.json_encode($dto->field('gender'), JSON_UNESCAPED_UNICODE).PHP_EOL;
