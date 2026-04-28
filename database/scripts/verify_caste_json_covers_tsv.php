<?php

declare(strict_types=1);

use App\Services\MasterData\ReligionCasteSubcasteImportService;
use App\Support\MasterData\ReligionCasteSubcasteSlugger;

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$slugger = new ReligionCasteSubcasteSlugger;
$import = $app->make(ReligionCasteSubcasteImportService::class);
$parsed = $import->parseAndDedupeTsv(base_path('database/data/religion_caste_subcaste_master.tsv'));

$expected = [];
foreach ($parsed['unique_rows'] as [$rel, $caste, $_]) {
    if ($rel === '' || $caste === '') {
        continue;
    }
    $expected[$slugger->makeKey($rel).'|'.$slugger->makeKey($caste)] = true;
}

$j = json_decode(file_get_contents(__DIR__.'/../seeders/data/religion_caste_subcaste_seed_castes.json'), true);
$have = [];
foreach ($j as $r) {
    if (! is_array($r)) {
        continue;
    }
    $rk = trim((string) ($r['religion_key'] ?? ''));
    $ck = trim((string) ($r['key'] ?? ''));
    if ($rk !== '' && $ck !== '') {
        $have[$rk.'|'.$ck] = true;
    }
}

$missing = array_diff(array_keys($expected), array_keys($have));
echo 'TSV caste pairs missing from JSON: '.count($missing)."\n";
foreach (array_slice($missing, 0, 40) as $m) {
    echo $m."\n";
}
if (count($missing) > 40) {
    echo "...\n";
}
