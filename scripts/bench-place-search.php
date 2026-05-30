<?php

/**
 * DEV-ONLY CLI benchmark — not autoloaded, not routed, not part of production deploy flow.
 * Run manually from project root: php scripts/bench-place-search.php
 * Measures PlaceIntakeSearchService latency and top MR labels for sample biodata place strings.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Location\PlaceIntakeSearchService;

app()->setLocale('mr');

foreach ([
    'तासगाव ता. - तासगाव, जि. - सांगली',
    'तासगाव, सांगली',
    'वरकुटे-मलवडी, ता. माण, जि. सातारा',
    'पुणे',
] as $q) {
    $t0 = microtime(true);
    $rows = app(PlaceIntakeSearchService::class)->search($q, 7);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    echo "\n[{$ms}ms] {$q}\n";
    foreach ($rows as $r) {
        echo '  '.$r['city_id'].' '.$r['display_label']."\n";
    }
}
