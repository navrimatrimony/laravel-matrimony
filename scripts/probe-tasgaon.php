<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\LocationSearchService;

app()->setLocale('mr');
$raw = 'तासगाव ता. - तासगाव, जि. - सांगली';
$res = app(LocationSearchService::class)->search($raw, [], [], true);
echo 'count: '.count($res['results'] ?? []).PHP_EOL;
foreach (array_slice($res['results'] ?? [], 0, 8) as $row) {
    echo '  '.$row['city_id'].' '.$row['display_label'].PHP_EOL;
}
