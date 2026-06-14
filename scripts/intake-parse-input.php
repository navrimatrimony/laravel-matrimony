<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$i = App\Models\BiodataIntake::findOrFail((int)($argv[1]??498));
echo 'raw_sha='.hash('sha256', (string)$i->raw_ocr_text)."\n";
echo 'last_parse_sha='.hash('sha256', (string)($i->last_parse_input_text ?? ''))."\n";
echo 'last_parse_len='.strlen((string)($i->last_parse_input_text ?? ''))."\n";
if ($i->last_parse_input_text) {
    echo substr($i->last_parse_input_text, 0, 300)."\n---\n";
    echo substr($i->last_parse_input_text, -200)."\n";
}
