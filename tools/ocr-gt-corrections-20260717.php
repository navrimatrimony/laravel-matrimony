<?php

/**
 * Product Owner GT corrections (2026-07-17).
 * Does not change OCR code paths except where title normalization is requested.
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$path = storage_path('app/private/ocr-ensemble-benchmark/sprint2_gt20_score_20260715_130342.json');
$j = json_decode(file_get_contents($path), true);
if (! is_array($j)) {
    fwrite(STDERR, "bad gt json\n");
    exit(1);
}

$items = &$j['tesseract']['items'];

// 1) PDF2 — remove religion from GT (biodata has no religion).
$pdf2 = 'testing 16 to 20 pdf and with photo (2).pdf';
if (isset($items[$pdf2]['fields']['religion'])) {
    $items[$pdf2]['fields']['religion']['truth'] = null;
    $items[$pdf2]['fields']['religion']['prediction'] = $items[$pdf2]['fields']['religion']['prediction'] ?? null;
    $items[$pdf2]['fields']['religion']['match'] = null;
}

// 2–4) Confirm name spellings (already correct in GT SSOT).
$snehal = $items['snehal.jpeg']['fields']['full_name']['truth'] ?? null;
$oneOne = $items['1.1.jpeg']['fields']['full_name']['truth'] ?? null;
if ($snehal !== 'स्नेहल शहाजी भोसले') {
    fwrite(STDERR, "unexpected snehal GT: ".json_encode($snehal, JSON_UNESCAPED_UNICODE)."\n");
    exit(1);
}
if ($oneOne !== 'अनिल जयवंत शिंदे') {
    fwrite(STDERR, "unexpected 1.1 GT: ".json_encode($oneOne, JSON_UNESCAPED_UNICODE)."\n");
    exit(1);
}

$j['gt_corrections'] = [
    'applied_at' => date('c'),
    'notes' => [
        'PDF2 religion removed — biodata has no religion field (PO labeling mistake)',
        'snehal full_name confirmed स्नेहल शहाजी भोसले (not शहानी)',
        '1.1 full_name confirmed अनिल जयवंत शिंदे (not जयबंत)',
        'Adv/Advocate/अॅड./ॲड. accepted as title normalization in matcher + name strip',
    ],
];

file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
echo "GT patched: PDF2 religion=null; snehal/1.1 spellings verified.\n";
