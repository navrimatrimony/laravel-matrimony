<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\District;
use App\Models\Taluka;
use App\Models\Village;
use Illuminate\Support\Facades\DB;

$sangli = District::query()->where('name_mr', 'like', '%सांगली%')->orWhere('name', 'like', '%Sangli%')->get(['id', 'name', 'name_mr']);
echo 'Sangli districts: '.json_encode($sangli, JSON_UNESCAPED_UNICODE).PHP_EOL;

foreach ($sangli as $d) {
    $talukas = Taluka::query()->where('parent_id', $d->id)->where(function ($q) {
        $q->where('name_mr', 'like', '%तासगाव%')->orWhere('name', 'like', '%Tasgaon%');
    })->get(['id', 'name', 'name_mr']);
    echo "Talukas in {$d->id}: ".json_encode($talukas, JSON_UNESCAPED_UNICODE).PHP_EOL;

    foreach ($talukas as $t) {
        $all = DB::table('addresses')->where('parent_id', $t->id)->where(function ($q) {
            $q->where('name_mr', 'like', '%तासगाव%')->orWhere('name', 'like', '%Tasgaon%');
        })->limit(10)->get(['id', 'name', 'name_mr', 'type']);
        echo "  all under taluka: ".json_encode($all, JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
}
