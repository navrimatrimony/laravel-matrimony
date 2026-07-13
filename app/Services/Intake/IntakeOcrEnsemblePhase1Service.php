<?php

namespace App\Services\Intake;

use App\Models\BulkIntakeBatchItem;
use App\Services\OcrService;

/**
 * OCR Ensemble Phase 1 — bulk file path only.
 * OpenCV minimal preprocess (via existing preset pipeline) + Tesseract multipass.
 */
class IntakeOcrEnsemblePhase1Service
{
    public const PREPROCESSING_VERSION = 'opencv_minimal_v1';

    public const PIPELINE_VERSION = 'phase1_v1';

    public function __construct(
        private readonly IntakeOcrEnsembleGate $ensembleGate,
        private readonly OcrService $ocrService,
    ) {}

    public function isEnabled(): bool
    {
        return $this->ensembleGate->isEnabled();
    }

    public function shouldRunForBulkItem(BulkIntakeBatchItem $item): bool
    {
        return $this->isEnabled()
            && (string) $item->input_type === BulkIntakeBatchItem::INPUT_FILE;
    }

    /**
     * @return array{text: string, debug: array<string, mixed>, preprocessing_version: string}
     */
    public function extractFromStoredFile(string $storagePath, ?string $originalFilename): array
    {
        $preset = trim((string) config('ocr.ensemble.phase1.preprocessing_preset', 'photo_capture'));
        if ($preset === '' || $preset === 'off') {
            $preset = 'photo_capture';
        }

        $text = $this->ocrService->extractTextFromPath($storagePath, $originalFilename, $preset);
        $debug = $this->ocrService->getLastExtractTextFromPathDebug() ?? [];
        $debug['ensemble_pipeline'] = self::PIPELINE_VERSION;
        $debug['ensemble_preprocessing_version'] = self::PREPROCESSING_VERSION;
        $debug['ensemble_preprocessing_preset'] = $preset;

        return [
            'text' => $text,
            'debug' => $debug,
            'preprocessing_version' => self::PREPROCESSING_VERSION,
        ];
    }
}
