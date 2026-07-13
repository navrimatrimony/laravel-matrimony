<?php

use App\Services\Intake\OcrEnsembleBenchmarkBatchOcrRunner;
use App\Services\Intake\OcrEnsembleBenchmarkEasyOcrClient;
use App\Services\Intake\OcrEnsembleBenchmarkPaddleOcrClient;
use App\Services\Intake\OcrEnsembleBenchmarkScorer;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

test('paddle benchmark client calls sidecar and returns text', function () {
    config()->set('ocr.ensemble.phase2.benchmark.sidecar_url', 'http://127.0.0.1:18080');
    config()->set('ocr.ensemble.phase2.benchmark.cli_runner', '');

    Http::fake([
        'http://127.0.0.1:18080/health' => Http::response(['status' => 'ok', 'engine' => 'paddleocr_v1']),
        'http://127.0.0.1:18080/ocr' => Http::response([
            'text' => "मुलाचे नाव : Test Candidate\nमोबाईल : 9876543210",
            'duration_ms' => 1500,
            'engine' => 'paddleocr_v1',
            'engine_meta' => ['lang' => 'hi'],
        ]),
    ]);

    $client = app(OcrEnsembleBenchmarkPaddleOcrClient::class);

    expect($client->healthCheck())->toBeTrue();

    $result = $client->extractFromImagePath(__FILE__);

    expect($result['text'])->toContain('Test Candidate')
        ->and($result['duration_ms'])->toBe(1500)
        ->and($result['engine'])->toBe('paddleocr_v1');
});

test('easyocr benchmark client calls sidecar and returns text', function () {
    config()->set('ocr.ensemble.phase2.benchmark.easyocr.sidecar_url', 'http://127.0.0.1:18081');
    config()->set('ocr.ensemble.phase2.benchmark.easyocr.cli_runner', '');

    Http::fake([
        'http://127.0.0.1:18081/health' => Http::response(['status' => 'ok', 'engine' => 'easyocr_v1']),
        'http://127.0.0.1:18081/ocr' => Http::response([
            'text' => "मुलाचे नाव : Easy Candidate\nमोबाईल : 9876543210",
            'duration_ms' => 2100,
            'engine' => 'easyocr_v1',
            'engine_meta' => ['languages' => ['hi', 'en']],
        ]),
    ]);

    $client = app(OcrEnsembleBenchmarkEasyOcrClient::class);

    expect($client->healthCheck())->toBeTrue();

    $result = $client->extractFromImagePath(__FILE__);

    expect($result['text'])->toContain('Easy Candidate')
        ->and($result['duration_ms'])->toBe(2100)
        ->and($result['engine'])->toBe('easyocr_v1');
});

test('benchmark scorer uses external raw ocr text and timing from predictions file', function () {
    $predictionsPath = storage_path('app/private/ocr-ensemble-benchmark/tests/predictions_sample.json');
    if (! is_dir(dirname($predictionsPath))) {
        mkdir(dirname($predictionsPath), 0755, true);
    }

    file_put_contents($predictionsPath, json_encode([
        'items' => [
            [
                'intake_id' => 999001,
                'raw_ocr_text' => "मुलाचे नाव : Benchmark Candidate\nजन्म तारीख : 04/01/1992\nमोबाईल : 9876543210\nधर्म : Hindu\nजात : Maratha\nलिंग : male",
                'ocr_time_ms' => 2222,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE));

    $indexed = app(OcrEnsembleBenchmarkScorer::class)->loadExternalPredictions($predictionsPath);

    expect($indexed)->toHaveKey(999001)
        ->and($indexed[999001]['ocr_time_ms'])->toBe(2222);
});

test('batch runner rejects unsupported benchmark engine', function () {
    expect(fn () => app(OcrEnsembleBenchmarkBatchOcrRunner::class)->assertEngineReady('unknown_engine_v1'))
        ->toThrow(RuntimeException::class);
});

test('batch runner accepts easyocr benchmark engine when sidecar is healthy', function () {
    config()->set('ocr.ensemble.phase2.benchmark.easyocr.sidecar_url', 'http://127.0.0.1:18081');
    config()->set('ocr.ensemble.phase2.benchmark.easyocr.cli_runner', '');

    Http::fake([
        'http://127.0.0.1:18081/health' => Http::response(['status' => 'ok', 'engine' => 'easyocr_v1']),
    ]);

    app(OcrEnsembleBenchmarkBatchOcrRunner::class)->assertEngineReady(OcrEnsembleBenchmarkEasyOcrClient::ENGINE);

    expect(true)->toBeTrue();
});
