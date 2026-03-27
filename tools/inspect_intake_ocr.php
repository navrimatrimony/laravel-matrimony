<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$intakeId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($intakeId <= 0) {
    fwrite(STDERR, "Usage: php tools/inspect_intake_ocr.php <intake_id>\n");
    exit(2);
}

/** @var \App\Models\BiodataIntake|null $i */
$i = \App\Models\BiodataIntake::find($intakeId);
if (! $i) {
    fwrite(STDERR, "Intake not found: {$intakeId}\n");
    exit(1);
}

$provider = (string) \App\Models\AdminSetting::getValue('intake_ocr_provider', 'tesseract');
$langHint = (string) \App\Models\AdminSetting::getValue('intake_ocr_language_hint', 'mixed');
$tesseractPath = (string) config('services.tesseract.path');

echo "intake_id={$i->id}\n";
echo "file_path=".(string) ($i->file_path ?? '')."\n";
echo "original_filename=".(string) ($i->original_filename ?? '')."\n";
echo "raw_ocr_len=".strlen((string) ($i->raw_ocr_text ?? ''))."\n";
$raw = (string) ($i->raw_ocr_text ?? '');
$snippet = mb_substr($raw, 0, 400);
$visible = str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $snippet);
echo "raw_ocr_snippet={$visible}\n";
echo "ocr_provider={$provider}\n";
echo "ocr_lang_hint={$langHint}\n";
echo "services.tesseract.path={$tesseractPath}\n";

