<?php

/**
 * Aligns religion_key in religion_caste_subcaste_seed_castes.json with canonical TSV
 * (religion_caste_subcaste_master.tsv) so MultilanguageSeedSync can fill label_mr.
 *
 * Usage: php database/scripts/align_caste_seed_json_to_tsv.php
 */

declare(strict_types=1);

use App\Services\MasterData\ReligionCasteSubcasteImportService;
use App\Support\MasterData\ReligionCasteSubcasteSlugger;

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$slugger = new ReligionCasteSubcasteSlugger;
$tsvPath = base_path('database/data/religion_caste_subcaste_master.tsv');
$import = $app->make(ReligionCasteSubcasteImportService::class);
$parsed = $import->parseAndDedupeTsv($tsvPath);

/** @var array<string, true> */
$expectedPairs = [];
foreach ($parsed['unique_rows'] as [$rel, $caste, $_sub]) {
    if ($rel === '' || $caste === '') {
        continue;
    }
    $rk = $slugger->makeKey($rel);
    $ck = $slugger->makeKey($caste);
    $expectedPairs[$rk.'|'.$ck] = true;
}

$jsonPath = database_path('seeders/data/religion_caste_subcaste_seed_castes.json');
$raw = file_get_contents($jsonPath);
if ($raw === false) {
    fwrite(STDERR, "Cannot read {$jsonPath}\n");
    exit(1);
}
$rows = json_decode($raw, true);
if (! is_array($rows)) {
    fwrite(STDERR, "Invalid JSON\n");
    exit(1);
}

$relMr = [
    'hindu' => 'हिंदू',
    'muslim' => 'मुस्लिम',
    'christian' => 'ख्रिश्चन',
    'sikh' => 'शीख',
    'jain' => 'जैन',
    'buddhist' => 'बौद्ध',
    'parsi' => 'पारशी',
    'jewish' => 'ज्यू',
    'bahai' => 'बहाई',
    'no-religion' => 'कोणताही धर्म नाही',
    'spiritual-not-religious' => 'आध्यात्मिक (धार्मिक नाही)',
    'tribal' => 'आदिवासी',
    'zoroastrian' => 'झोरोस्ट्रियन',
];

$fixed = 0;
foreach ($rows as $i => &$row) {
    if (! is_array($row)) {
        continue;
    }
    $ck = isset($row['key']) ? trim((string) $row['key']) : '';
    $rj = isset($row['religion_key']) ? trim((string) $row['religion_key']) : '';
    if ($ck === '' || $rj === '') {
        continue;
    }
    // Normalize broken export keys (spaces)
    $rjNorm = str_replace([' ', '_'], ['-', '-'], mb_strtolower($rj, 'UTF-8'));
    if ($rjNorm !== $rj) {
        $row['religion_key'] = $rjNorm;
        $rj = $rjNorm;
        $fixed++;
    }

    $pair = $rj.'|'.$ck;
    if (isset($expectedPairs[$pair])) {
        if (isset($relMr[$rj])) {
            $row['religion_key Marathi'] = $relMr[$rj];
        }

        continue;
    }

    $candidates = [];
    foreach (array_keys($expectedPairs) as $p) {
        if (str_ends_with($p, '|'.$ck)) {
            $candidates[] = explode('|', $p, 2)[0];
        }
    }
    $candidates = array_values(array_unique($candidates));
    if (count($candidates) === 1) {
        $newR = $candidates[0];
        if ($newR !== $rj) {
            $row['religion_key'] = $newR;
            $row['religion_key Marathi'] = $relMr[$newR] ?? ($row['religion_key Marathi'] ?? '');
            $fixed++;
        }
    }
}
unset($row);

$enc = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($enc === false) {
    fwrite(STDERR, "json_encode failed\n");
    exit(1);
}
if (file_put_contents($jsonPath, $enc."\n") === false) {
    fwrite(STDERR, "Cannot write {$jsonPath}\n");
    exit(1);
}

echo "Wrote {$jsonPath}; religion_key alignment fixes: {$fixed}\n";
