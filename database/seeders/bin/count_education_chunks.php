<?php

$files = glob(__DIR__.'/../data/education_ch_*.php');
sort($files);
$n = 0;
foreach ($files as $f) {
    $c = count(require $f);
    echo basename($f), ': ', $c, PHP_EOL;
    $n += $c;
}
echo 'TOTAL ', $n, PHP_EOL;
