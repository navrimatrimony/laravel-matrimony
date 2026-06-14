<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = (int)($argv[1]??500);
$i = App\Models\BiodataIntake::findOrFail($id);
$core = $i->parsed_json['core'] ?? [];
echo json_encode([
    'full_name' => $core['full_name'] ?? null,
    'marital_status' => $core['marital_status'] ?? null,
    'marital_status_id' => $core['marital_status_id'] ?? null,
    'religion_id' => $core['religion_id'] ?? null,
    'caste_id' => $core['caste_id'] ?? null,
    'addresses' => $i->parsed_json['addresses'] ?? [],
], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
