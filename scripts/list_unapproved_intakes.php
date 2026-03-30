<?php

declare(strict_types=1);

use App\Models\BiodataIntake;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

foreach (BiodataIntake::orderBy('id')->where('approved_by_user', false)->get(['id', 'parse_status', 'uploaded_by']) as $i) {
    echo "id={$i->id} parse_status={$i->parse_status} uploaded_by={$i->uploaded_by}\n";
}
