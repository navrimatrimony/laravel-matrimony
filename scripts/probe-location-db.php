<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Location\LocationCompoundAddressParser;
use App\Services\LocationSearchService;
use Illuminate\Support\Facades\DB;

echo 'City count: '.DB::table('addresses')->where('type', 'city')->count().PHP_EOL;

foreach (['varkute', 'वरकुटे', 'tasgaon', 'तासगाव'] as $needle) {
    $rows = DB::table('addresses')
        ->where('type', 'city')
        ->where(function ($q) use ($needle) {
            $q->where('name', 'like', '%'.$needle.'%')
                ->orWhere('name_mr', 'like', '%'.$needle.'%')
                ->orWhere('name_en', 'like', '%'.$needle.'%');
        })
        ->limit(3)
        ->get(['id', 'name', 'name_mr', 'name_en', 'parent_id']);
    echo "Needle [{$needle}]: ".json_encode($rows, JSON_UNESCAPED_UNICODE).PHP_EOL;
}

$parser = app(LocationCompoundAddressParser::class);
$search = app(LocationSearchService::class);
app()->setLocale('mr');

foreach ([
    'वरकुटे-मलवडी, ता. माण, जि. सातारा',
    'तासगाव ता. - तासगाव, जि. - सांगली',
] as $raw) {
    echo "\nRAW: {$raw}\n";
    print_r($parser->parseComponents($raw));
    foreach ($parser->searchQueries($raw) as $q) {
        $n = count($search->search($q, [], [], true)['results'] ?? []);
        echo "  search [{$q}] => {$n}\n";
    }
}

$user = DB::table('users')->where('mobile', '1111111112')->orWhere('id', 1111111112)->first();
echo "\nUser: ".json_encode($user).PHP_EOL;
