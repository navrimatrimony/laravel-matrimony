<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$i = App\Models\BiodataIntake::query()->latest('id')->first();
if (!$i) { echo "no intake\n"; exit; }
echo "id={$i->id} status={$i->intake_status} parse={$i->parse_status} profile=".($i->matrimony_profile_id??'null')."\n";
