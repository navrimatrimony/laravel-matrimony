<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = (int) ($argv[1] ?? 498);
$intake = App\Models\BiodataIntake::findOrFail($id);
$text = (string) ($intake->raw_ocr_text ?? '');

echo 'raw_len='.strlen($text)."\n";
echo "raw_tail:\n".substr($text, -120)."\n\n";

$builder = app(\App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder::class);
$draft = $builder->build($text);
echo 'builder full_name='.($draft['normalized']['core']['full_name'] ?? 'null')."\n";

$mapper = app(\App\Services\Parsing\IntakeNormalizedDraftToParsedJsonMapper::class);
$mapped = $mapper->map($draft);
echo 'mapper full_name='.($mapped['core']['full_name'] ?? 'null')."\n";

echo 'stored full_name='.($intake->parsed_json['core']['full_name'] ?? 'null')."\n";
