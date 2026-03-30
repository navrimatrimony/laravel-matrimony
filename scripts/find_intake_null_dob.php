<?php

declare(strict_types=1);

use App\Models\BiodataIntake;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$rows = BiodataIntake::orderBy('id')->get(['id', 'parse_status', 'parsed_json', 'raw_ocr_text', 'approval_snapshot_json']);
foreach ($rows as $i) {
    $dob = $i->parsed_json['core']['date_of_birth'] ?? 'NO_CORE';
    $raw = (string) ($i->raw_ocr_text ?? '');
    $hasJ = preg_match('/जन्म\s*तारीख/u', $raw) === 1;
    if (($dob === null || $dob === '') && $hasJ && $i->parse_status === 'parsed') {
        echo "id={$i->id} parsed_dob=".json_encode($dob)." has_janma=yes\n";
    }
}
