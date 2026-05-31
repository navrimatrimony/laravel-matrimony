<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::table('biodata_intakes')
    ->where('parsed_json', 'like', '%तासगाव%')
    ->limit(5)
    ->get(['id']);

foreach ($rows as $r) {
    echo $r->id.PHP_EOL;
}
