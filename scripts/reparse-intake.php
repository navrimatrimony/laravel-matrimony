<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = (int) ($argv[1] ?? 498);
$intake = App\Models\BiodataIntake::findOrFail($id);
$intake->update([
    'parse_status' => 'pending',
    'last_parse_input_text' => null,
    'parsed_json' => null,
    'last_error' => null,
]);
App\Jobs\ParseIntakeJob::dispatchSync($id);
$intake->refresh();
$core = $intake->parsed_json['core'] ?? [];
echo "full_name=".($core['full_name'] ?? 'null')."\n";
echo "gender=".($core['gender'] ?? 'null')."\n";
