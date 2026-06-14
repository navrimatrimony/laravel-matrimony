<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$i = App\Models\BiodataIntake::findOrFail((int)($argv[1]??498));
$parseText = (string)($i->last_parse_input_text ?? '');
$raw = (string)($i->raw_ocr_text ?? '');
$builder = app(\App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder::class);
echo "from last_parse_input: ".($builder->build($parseText)['normalized']['core']['full_name'] ?? 'null')."\n";
echo "from raw_ocr: ".($builder->build($raw)['normalized']['core']['full_name'] ?? 'null')."\n";

$resolved = app(\App\Services\OcrService::class)->resolveParseInputText($i);
echo "from resolveParseInputText: ".($builder->build($resolved['text'])['normalized']['core']['full_name'] ?? 'null')."\n";
