<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Location;
use App\Services\Location\AddressHierarchySearch;
use App\Services\LocationSearchService;

$v = Location::query()->find(6316);
if ($v) {
    echo 'Village 6316: '.json_encode($v->only(['id', 'name', 'name_mr', 'type', 'parent_id']), JSON_UNESCAPED_UNICODE).PHP_EOL;
    $p = $v->parent_id;
    for ($i = 0; $i < 5 && $p; $i++) {
        $node = Location::query()->find($p);
        if (! $node) {
            break;
        }
        echo '  ancestor: '.json_encode($node->only(['id', 'name', 'name_mr', 'type']), JSON_UNESCAPED_UNICODE).PHP_EOL;
        $p = $node->parent_id;
    }
}

app()->setLocale('mr');
$components = ['village' => 'वरकुटे मलवडी', 'taluka' => 'माण', 'district' => 'सातारा'];
$cities = app(AddressHierarchySearch::class)->findCities($components, 5);
echo 'findCities count: '.count($cities).PHP_EOL;
foreach ($cities as $c) {
    echo '  city id '.$c->id.' '.$c->name.' / '.$c->name_mr.PHP_EOL;
}

foreach (['मलवडी', 'वरकुटे', 'वरकूटे', 'वरकुटे मलवडी'] as $q) {
    $n = count(app(LocationSearchService::class)->search($q, [], [], true)['results'] ?? []);
    echo "search [{$q}] => {$n}\n";
}

$res = app(LocationSearchService::class)->search('वरकुटे-मलवडी, ता. माण, जि. सातारा', [], [], true);
echo 'search count: '.count($res['results'] ?? []).PHP_EOL;
foreach (($res['results'] ?? []) as $row) {
    echo '  '.json_encode($row, JSON_UNESCAPED_UNICODE).PHP_EOL;
}
