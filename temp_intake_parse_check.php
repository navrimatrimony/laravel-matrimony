<?php
require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

foreach ([194, 195] as $id) {
    $intake = App\Models\BiodataIntake::find($id);

    if (! $intake) {
        var_export(['intake_id' => $id, 'missing' => true]);
        echo PHP_EOL . str_repeat('=', 80) . PHP_EOL;
        continue;
    }

    $parsed = app(App\Services\ParseService::class)->parse($intake);

    var_export([
        'intake_id' => $id,
        'core' => $parsed['core'] ?? [],
        'siblings' => $parsed['siblings'] ?? [],
        'relatives' => $parsed['relatives'] ?? [],
        'career_history' => $parsed['career_history'] ?? [],
    ]);

    echo PHP_EOL . str_repeat('=', 80) . PHP_EOL;
}
