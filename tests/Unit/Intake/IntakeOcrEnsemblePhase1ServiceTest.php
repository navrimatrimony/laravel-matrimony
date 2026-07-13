<?php

use App\Services\Intake\IntakeOcrEnsemblePhase1Service;
use App\Services\OcrService;
use Tests\TestCase;

uses(TestCase::class);

test('phase1 service exposes frozen preprocessing version constant', function () {
    expect(IntakeOcrEnsemblePhase1Service::PREPROCESSING_VERSION)->toBe('opencv_minimal_v1')
        ->and(IntakeOcrEnsemblePhase1Service::PIPELINE_VERSION)->toBe('phase1_v1');
});

test('phase1 extract uses configured ensemble preset', function () {
    config(['ocr.ensemble.phase1.preprocessing_preset' => 'high_contrast']);

    $this->mock(OcrService::class, function ($mock): void {
        $mock->shouldReceive('extractTextFromPath')
            ->once()
            ->withArgs(function (string $path, ?string $name, ?string $preset): bool {
                return $path === 'intakes/sample.jpg'
                    && $name === 'sample.jpg'
                    && $preset === 'high_contrast';
            })
            ->andReturn('Preset OCR');
        $mock->shouldReceive('getLastExtractTextFromPathDebug')->once()->andReturn(['kind' => 'image']);
    });

    $result = app(IntakeOcrEnsemblePhase1Service::class)->extractFromStoredFile('intakes/sample.jpg', 'sample.jpg');

    expect($result['text'])->toBe('Preset OCR')
        ->and($result['debug']['ensemble_preprocessing_preset'])->toBe('high_contrast');
});
