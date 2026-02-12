<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rp = resource_path('views/matrimony/profile/show.blade.php');

echo "1. base_path(): " . base_path() . "\n";
echo "2. resource_path(...): " . $rp . "\n";
echo "4. realpath: " . (file_exists($rp) ? realpath($rp) : 'NOT FOUND') . "\n";
echo "---\n3. file_get_contents (first 600 chars):\n";
echo file_exists($rp) ? substr(file_get_contents($rp), 0, 600) . "\n...[truncated]\n" : "NOT FOUND\n";
