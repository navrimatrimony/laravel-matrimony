<?php

/**
 * Loop 13 — compare multipass scores vs presence of key tokens (snehal / hard images).
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Ocr\ImagePreprocessingService;
use App\Services\Ocr\TesseractMultiPassOcrService;

$file = $argv[1] ?? 'snehal.jpeg';
$token = $argv[2] ?? 'स्नेहल';
$batch = storage_path('app/ocr-dev-batches/Batch-001');
$src = $batch.DIRECTORY_SEPARATOR.$file;
$ext = pathinfo($file, PATHINFO_EXTENSION);
$rel = 'intakes/_dbg_'.md5($file).'.'.$ext;
$abs = storage_path('app/private/'.$rel);
if (! is_dir(dirname($abs))) {
    mkdir(dirname($abs), 0755, true);
}
copy($src, $abs);

$pre = app(ImagePreprocessingService::class);
$tess = app(TesseractMultiPassOcrService::class);
$ref = new ReflectionClass($tess);
$run = $ref->getMethod('runTesseractAttempt');
$run->setAccessible(true);

$presets = [null => 'original', 'marathi_printed' => 'marathi_printed', 'photo_capture' => 'photo_capture', 'high_contrast' => 'high_contrast'];
$rows = [];
foreach ($presets as $preset => $label) {
    $path = $abs;
    $cleanup = null;
    if ($preset !== null) {
        $result = $pre->preprocessForOcr($abs, $rel, $file, $preset);
        $outPath = is_string($result['output_absolute_path'] ?? null) ? $result['output_absolute_path'] : '';
        if (($result['used'] ?? false) && $outPath !== '' && is_file($outPath)) {
            $path = $outPath;
            $cleanup = $outPath;
        }
    }
    foreach ([6, 4, 11] as $psm) {
        try {
            $text = (string) $run->invoke($tess, $path, ['mar+eng'], $psm);
        } catch (Throwable $e) {
            continue;
        }
        $meta = $tess->scoreText($text);
        $rows[] = [
            'preset' => $label,
            'psm' => $psm,
            'score' => $meta['score'],
            'label_hits' => $meta['label_hits'],
            'dev' => $meta['devanagari_chars'],
            'chars' => $meta['char_count'],
            'mobiles' => $meta['mobile_like_count'],
            'has_token' => mb_stripos($text, $token) !== false,
            'penalties' => $meta['penalties'],
            'preview' => mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, 100),
        ];
    }
    if ($cleanup && is_file($cleanup)) {
        @unlink($cleanup);
    }
}
@unlink($abs);
usort($rows, static fn ($a, $b) => $b['score'] <=> $a['score']);
foreach ($rows as $r) {
    echo sprintf(
        "score=%5.1f token=%s preset=%-16s psm=%2d labels=%d chars=%d preview=%s\n",
        $r['score'],
        $r['has_token'] ? 'Y' : 'N',
        $r['preset'],
        $r['psm'],
        $r['label_hits'],
        $r['chars'],
        $r['preview']
    );
}
$path = storage_path('app/private/ocr-ensemble-benchmark/loop13_score_cmp_'.preg_replace('/\W+/', '_', $file).'_'.date('Ymd_His').'.json');
file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "artifact=$path\n";
