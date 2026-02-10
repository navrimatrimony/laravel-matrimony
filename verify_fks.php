<?php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$fks = DB::select("
    SELECT 
        COLUMN_NAME, 
        REFERENCED_TABLE_NAME 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'matrimony_profiles' 
    AND REFERENCED_TABLE_NAME IS NOT NULL
    AND COLUMN_NAME IN ('country_id', 'state_id', 'district_id', 'taluka_id', 'city_id')
");

foreach ($fks as $fk) {
    echo $fk->COLUMN_NAME . ' -> ' . $fk->REFERENCED_TABLE_NAME . PHP_EOL;
}

echo PHP_EOL . 'Total FK constraints: ' . count($fks) . PHP_EOL;
