<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

foreach (['416312', '4163'] as $pin) {
    $rows = DB::table('addresses')->where('pincode', 'like', $pin.'%')->limit(10)->get(['id', 'name', 'name_mr', 'type', 'tag', 'parent_id', 'pincode']);
    echo "pin {$pin}: ".json_encode($rows, JSON_UNESCAPED_UNICODE).PHP_EOL;
}

$rows = DB::table('addresses')
    ->where('type', 'suburban')
    ->where(function ($q) {
        $q->where('name', 'like', '%Tasgaon%')->orWhere('name_mr', 'like', '%तासगाव%');
    })
    ->limit(10)
    ->get(['id', 'name', 'name_mr', 'type', 'tag', 'parent_id', 'pincode']);
echo 'suburban tasgaon: '.json_encode($rows, JSON_UNESCAPED_UNICODE).PHP_EOL;

$taluka = DB::table('addresses')->where('id', 374)->first(['id', 'name', 'name_mr', 'type', 'tag', 'pincode']);
echo 'taluka 374: '.json_encode($taluka, JSON_UNESCAPED_UNICODE).PHP_EOL;
