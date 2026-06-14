<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\Ocr\OcrNormalize;
$i = App\Models\BiodataIntake::findOrFail(498);
$stored = (string)$i->raw_ocr_text;
$norm = OcrNormalize::normalizeRawTextForParsing($stored);
$post = app(\App\Services\Ocr\OcrPostProcessor::class)->process($norm);
$enh = app(\App\Services\Domain\OcrDomainIntelligenceService::class)->enhance($post);
$builder = app(\App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder::class);
echo 'stored: '.($builder->build($stored)['normalized']['core']['full_name']??'')."\n";
echo 'norm only: '.($builder->build($norm)['normalized']['core']['full_name']??'')."\n";
echo 'post: '.($builder->build($post)['normalized']['core']['full_name']??'')."\n";
echo 'enh: '.($builder->build($enh)['normalized']['core']['full_name']??'')."\n";
