<?php

/**
 * DEV-ONLY CLI benchmark — not autoloaded, not routed, not part of production deploy flow.
 * Run manually from project root: php scripts/bench-intake-453-locations.php
 * Times IntakeLocationSuggestionLayerService for intake id 453 (adjust id in script if needed).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\BiodataIntake;
use App\Services\Intake\IntakeLocationSuggestionLayerService;

$intake = BiodataIntake::query()->findOrFail(453);
$data = $intake->parsed_json;
$snapshot = is_array($data) ? $data : [];

$t0 = microtime(true);
$rows = app(IntakeLocationSuggestionLayerService::class)
    ->unresolvedCandidatesFromSnapshot($snapshot, 7, $data);
$ms = (int) round((microtime(true) - $t0) * 1000);

echo "ms={$ms} count=".count($rows).PHP_EOL;
foreach ($rows as $row) {
    echo '  '.$row['field_key'].' options='.count($row['options'] ?? []).PHP_EOL;
}
