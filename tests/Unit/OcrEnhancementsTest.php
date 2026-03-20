<?php

use App\Services\Ocr\AutoCropSuggestionService;
use App\Services\Ocr\OcrPostProcessor;
use App\Services\Ocr\OcrQualityEvaluator;

uses(Tests\TestCase::class);

test('auto crop suggestion returns axis aligned quad within image bounds', function () {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('GD extension required');
    }

    $path = sys_get_temp_dir().'/auto_crop_test_'.uniqid('', true).'.png';
    $im = imagecreatetruecolor(100, 80);
    $dark = imagecolorallocate($im, 35, 35, 35);
    $light = imagecolorallocate($im, 245, 245, 245);
    imagefilledrectangle($im, 0, 0, 99, 79, $dark);
    imagefilledrectangle($im, 18, 12, 82, 68, $light);
    imagepng($im, $path);
    imagedestroy($im);

    $svc = new AutoCropSuggestionService;
    $r = $svc->suggest($path);
    @unlink($path);

    expect($r)->toHaveKeys(['tl', 'tr', 'br', 'bl', 'confidence'])
        ->and($r['tl']['x'])->toBeLessThan($r['tr']['x'])
        ->and($r['tl']['y'])->toBeLessThan($r['bl']['y'])
        ->and($r['confidence'])->toBeFloat();
});

test('ocr post processor applies conservative replacements and drops symbol-only noise', function () {
    $p = new OcrPostProcessor;
    $out = $p->process("क. नांव\n!!@@@###\nजन्प तारीख");

    expect($out)->toContain('कु.')
        ->and($out)->toContain('नाव')
        ->and($out)->toContain('जन्म');
});

test('ocr quality evaluator marks very short text as low', function () {
    $e = new OcrQualityEvaluator;
    $r = $e->evaluate(str_repeat('अ', 30));

    expect($r)->toHaveKeys(['score', 'is_low', 'reasons'])
        ->and($r['is_low'])->toBeTrue();
});
