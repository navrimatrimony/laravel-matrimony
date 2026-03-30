<?php

declare(strict_types=1);

use App\Models\BiodataIntake;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$id = (int) ($argv[1] ?? 4);
$i = BiodataIntake::find($id);
print_r([
    'parse_status' => $i->parse_status,
    'last_error' => $i->last_error,
    'approved_by_user' => $i->approved_by_user,
    'intake_locked' => $i->intake_locked,
    'dob' => $i->parsed_json['core']['date_of_birth'] ?? null,
]);
