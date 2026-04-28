<?php

/**
 * Read-only audit: castes.label_mr quality (run: php database/scripts/audit_caste_label_mr.php).
 */

declare(strict_types=1);

use App\Models\Caste;

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$devanagariRe = '/\p{Devanagari}/u';

$nullOrEmpty = 0;
$sameAsEn = 0;
$hasDeva = 0;
$mojibakeCandidate = 0; // no Devanagari, not same as EN, non-empty
$other = 0;

$samplesMojibake = [];

foreach (Caste::query()->cursor() as $c) {
    $mr = $c->label_mr;
    if ($mr === null || $mr === '') {
        $nullOrEmpty++;

        continue;
    }
    $en = (string) ($c->label_en ?? $c->label ?? '');
    if ($mr === $en) {
        $sameAsEn++;

        continue;
    }
    if (preg_match($devanagariRe, $mr) === 1) {
        $hasDeva++;

        continue;
    }
    // Non-empty MR, different from EN, no Devanagari: Latin-only "MR" or garbled bytes
    if (preg_match('/à|Ã|Â|Ä|å|æ|ç|è|é|ê|ë|ì|í|î|ï|ð|ñ|ò|ó|ô|õ|ö/u', $mr) === 1) {
        $mojibakeCandidate++;
        if (count($samplesMojibake) < 8) {
            $samplesMojibake[] = [
                'id' => $c->id,
                'key' => $c->key,
                'en' => $en,
                'mr' => mb_substr($mr, 0, 60),
                'hex24' => bin2hex(substr($mr, 0, 24)),
            ];
        }

        continue;
    }
    $other++;
}

$total = $nullOrEmpty + $sameAsEn + $hasDeva + $mojibakeCandidate + $other;

echo "=== castes.label_mr audit (PHP) ===\n";
echo "total rows: {$total}\n";
echo "label_mr NULL or empty: {$nullOrEmpty}\n";
echo "label_mr equals label_en (English copy): {$sameAsEn}\n";
echo "label_mr contains real Devanagari: {$hasDeva}\n";
echo "label_mr looks like mojibake (Latin extended, no Devanagari): {$mojibakeCandidate}\n";
echo "other non-empty (Latin MR, no typical mojibake chars): {$other}\n\n";

if ($samplesMojibake !== []) {
    echo "Sample mojibake / garbled rows:\n";
    foreach ($samplesMojibake as $s) {
        echo "  id={$s['id']} key={$s['key']}\n";
        echo "    en={$s['en']}\n";
        echo "    mr={$s['mr']}\n";
        echo "    hex24={$s['hex24']}\n";
    }
}

$probe = Caste::query()->where('key', 'anglo-indian')->value('label_mr');
if (is_string($probe) && $probe !== '') {
    echo "\nProbe key=anglo-indian (one-layer iconv peel):\n";
    foreach (['ISO-8859-1', 'Windows-1252'] as $enc) {
        $once = @iconv('UTF-8', $enc.'//IGNORE', $probe);
        if ($once === false) {
            echo "  {$enc} x1: iconv failed\n";

            continue;
        }
        $twice = @iconv('UTF-8', $enc.'//IGNORE', $once);
        foreach ([1 => $once, 2 => $twice] as $n => $peeled) {
            if ($peeled === false) {
                continue;
            }
            $ok = preg_match($devanagariRe, $peeled) === 1;
            $utf8ok = mb_check_encoding($peeled, 'UTF-8');
            echo "  {$enc} x{$n}: utf8_ok=".($utf8ok ? 'yes' : 'no').' devanagari='.($ok ? 'yes' : 'no').' preview='.mb_substr($peeled, 0, 40)."\n";
        }
    }
}

echo "\nDone.\n";
