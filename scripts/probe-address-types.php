<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$types = DB::table('addresses')->selectRaw('type, count(*) as c')->groupBy('type')->pluck('c', 'type');
echo 'Types: '.json_encode($types, JSON_UNESCAPED_UNICODE).PHP_EOL;

foreach (['वरकुटे', 'तासगाव', 'varkute', 'tasgaon'] as $needle) {
    $v = DB::table('addresses')
        ->where('type', 'village')
        ->where(function ($q) use ($needle) {
            $q->where('name_mr', 'like', '%'.$needle.'%')
                ->orWhere('name', 'like', '%'.$needle.'%')
                ->orWhere('name_en', 'like', '%'.$needle.'%');
        })
        ->limit(3)
        ->get(['id', 'name', 'name_mr', 'name_en', 'parent_id']);
    echo "Village [{$needle}]: ".json_encode($v, JSON_UNESCAPED_UNICODE).PHP_EOL;
}

$col = DB::select("SHOW COLUMNS FROM addresses WHERE Field = 'type'");
echo 'type column: '.json_encode($col).PHP_EOL;
