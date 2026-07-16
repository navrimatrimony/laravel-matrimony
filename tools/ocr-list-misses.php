<?php

require __DIR__.'/../vendor/autoload.php';

$path = $argv[1] ?? __DIR__.'/../storage/app/private/ocr-ensemble-benchmark/product_metrics_gt20_20260716_111153.json';
$m = json_decode(file_get_contents($path), true);

foreach ($m['files'] as $f) {
    foreach ($f['fields'] as $field => $row) {
        if (empty($row['ok'])) {
            echo $f['file'].' | '.$field.' | truth='.json_encode($row['truth'], JSON_UNESCAPED_UNICODE).' | pred='.json_encode($row['pred'], JSON_UNESCAPED_UNICODE).PHP_EOL;
        }
    }
}
