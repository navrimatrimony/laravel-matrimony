<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Intake\OcrEnsemble\OcrEnsembleFieldExtractor;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Ocr\TesseractMultiPassOcrService;

$file = 'snehal.jpeg';
$src = storage_path('app/ocr-dev-batches/Batch-001/'.$file);
$rel = 'intakes/_cmp_'.md5($file).'.jpeg';
$abs = storage_path('app/private/'.$rel);
copy($src, $abs);

$tess = app(TesseractMultiPassOcrService::class);
$ref = new ReflectionClass($tess);
$run = $ref->getMethod('runTesseractAttempt');
$run->setAccessible(true);
$ex = app(OcrEnsembleFieldExtractor::class);
$key = OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR;

foreach (['mar+eng', 'mar'] as $lang) {
    foreach ([6, 4, 11] as $psm) {
        $text = (string) $run->invoke($tess, $abs, explode('+', str_replace('mar+eng', 'mar+eng', $lang) === 'mar+eng' ? 'mar+eng' : 'mar'), $psm);
        // languageArgs
        $langs = $lang === 'mar+eng' ? ['mar', 'eng'] : ['mar'];
        $text = (string) $run->invoke($tess, $abs, $langs, $psm);
        $meta = $tess->scoreText($text);
        $dto = $ex->extractFromText($text, $key);
        echo sprintf(
            "lang=%-7s psm=%2d score=%5.1f labels=%2d chars=%4d snehal=%s name=%s\n",
            $lang,
            $psm,
            $meta['score'],
            $meta['label_hits'],
            $meta['char_count'],
            mb_stripos($text, 'स्नेहल') !== false ? 'Y' : 'N',
            json_encode($dto->field('full_name'), JSON_UNESCAPED_UNICODE)
        );
        if (preg_match('/नाव[^\n]{0,40}/u', $text, $m) || preg_match('/नांव[^\n]{0,40}/u', $text, $m2)) {
            echo '  line='.($m[0] ?? $m2[0] ?? '').PHP_EOL;
        }
    }
}
@unlink($abs);
