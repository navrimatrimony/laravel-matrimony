<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$k = config('services.sarvam.subscription_key');
echo 'sarvam_key_set='.(is_string($k) && strlen(trim($k)) > 0 ? 'yes' : 'no').PHP_EOL;
echo 'base_url='.config('services.sarvam.base_url').PHP_EOL;
echo 'chat_model='.config('services.sarvam.chat_model').PHP_EOL;
$p4 = config('ocr.ensemble.phase4.sarvam_api_key')
    ?? env('OCR_ENSEMBLE_PHASE4_SARVAM_API_KEY');
echo 'phase4_key_set='.(is_string($p4) && strlen(trim((string) $p4)) > 0 ? 'yes' : 'no').PHP_EOL;
