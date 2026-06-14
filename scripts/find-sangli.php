<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

foreach (['Sangli', 'सांगली', 'Sangali', 'Kolhapur', 'Satara'] as $term) {
    $rows = DB::table('addresses')->where('name', 'like', '%'.$term.'%')->limit(5)->pluck('name', 'id');
    echo $term.': '.$rows."\n";
}
