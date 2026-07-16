<?php

$old = $argv[1] ?? '';
$new = $argv[2] ?? '';
if ($old === '' || $new === '' || ! is_file($old) || ! is_file($new)) {
    fwrite(STDERR, "Usage: php tools/ocr-compare-metrics.php <old.json> <new.json>\n");
    exit(1);
}

$o = json_decode(file_get_contents($old), true);
$n = json_decode(file_get_contents($new), true);

echo 'OLD crit='.$o['critical_accuracy']['pct'].'% name='.$o['fields']['full_name']['pct'].'%'.PHP_EOL;
echo 'NEW crit='.$n['critical_accuracy']['pct'].'% name='.$n['fields']['full_name']['pct'].'%'.PHP_EOL;

$byFileOld = [];
foreach ($o['files'] as $row) {
    $byFileOld[$row['file']] = $row;
}

foreach ($n['files'] as $nf) {
    $of = $byFileOld[$nf['file']] ?? null;
    if ($of === null) {
        continue;
    }
    foreach ($of['fields'] as $f => $row) {
        $was = (bool) $row['ok'];
        $now = (bool) ($nf['fields'][$f]['ok'] ?? false);
        if ($was && ! $now) {
            echo 'LOSS '.$nf['file'].' '.$f.PHP_EOL;
        } elseif (! $was && $now) {
            echo 'GAIN '.$nf['file'].' '.$f.' pred='.$nf['fields'][$f]['pred'].PHP_EOL;
        }
    }
}
