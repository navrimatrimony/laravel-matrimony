<?php

declare(strict_types=1);

use App\Models\BiodataIntake;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

foreach (BiodataIntake::orderBy('id')->get() as $i) {
    $p = $i->parsed_json['core']['date_of_birth'] ?? null;
    $a = null;
    if (is_array($i->approval_snapshot_json['core'] ?? null)) {
        $a = $i->approval_snapshot_json['core']['date_of_birth'] ?? 'KEY_MISSING';
    }
    if ($i->approval_snapshot_json === null) {
        continue;
    }
    if (! is_array($i->approval_snapshot_json['core'] ?? null)) {
        continue;
    }
    $ap = $i->approval_snapshot_json['core']['date_of_birth'] ?? null;
    $pStr = $p === null || $p === '' ? '' : (string) $p;
    $aStr = $ap === null || $ap === '' ? '' : (string) $ap;
    if ($pStr !== $aStr && ($pStr !== '' || $aStr !== '')) {
        echo "id={$i->id} parsed=".json_encode($p)." approval=".json_encode($ap)."\n";
    }
}
