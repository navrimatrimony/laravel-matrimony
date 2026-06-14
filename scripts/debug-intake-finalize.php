<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = (int) ($argv[1] ?? 498);
$intake = App\Models\BiodataIntake::findOrFail($id);
$raw = (string) $intake->raw_ocr_text;
$userId = (int) $intake->uploaded_by;

$builder = app(\App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder::class);
$draft = $builder->build($raw, ['intake_id' => $id]);
$mapped = app(\App\Services\Parsing\IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft);

echo "after mapper: ".($mapped['core']['full_name'] ?? 'null')."\n";

$utf8Stats = [];
$final = app(\App\Services\Intake\IntakePipelineService::class)->finalizeParsedSnapshotForStorage($mapped, $utf8Stats, $userId);
echo "after finalize: ".($final['core']['full_name'] ?? 'null')."\n";
