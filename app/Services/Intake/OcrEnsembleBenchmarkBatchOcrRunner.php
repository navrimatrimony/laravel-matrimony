<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Services\Ocr\ImagePreprocessingService;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Benchmark-only batch OCR runner for candidate engines.
 */
class OcrEnsembleBenchmarkBatchOcrRunner
{
    public function __construct(
        private readonly OcrEnsembleBenchmarkPaddleOcrClient $paddleClient,
        private readonly OcrEnsembleBenchmarkEasyOcrClient $easyOcrClient,
        private readonly ImagePreprocessingService $imagePreprocessing,
    ) {}

    public function assertEngineReady(string $engine): void
    {
        match ($engine) {
            OcrEnsembleBenchmarkPaddleOcrClient::ENGINE => $this->assertPaddleReady(),
            OcrEnsembleBenchmarkEasyOcrClient::ENGINE => $this->assertEasyOcrReady(),
            default => throw new RuntimeException("Unsupported benchmark engine: {$engine}"),
        };
    }

    /**
     * @return array{
     *     engine: string,
     *     batch_id: int,
     *     generated_at: string,
     *     preprocessing_preset: string,
     *     items: list<array<string, mixed>>
     * }
     */
    public function runBatch(int $batchId, string $engine): array
    {
        $this->assertEngineReady($engine);

        $batch = BulkIntakeBatch::query()->find($batchId);
        if (! $batch instanceof BulkIntakeBatch) {
            throw new RuntimeException("Bulk batch {$batchId} not found.");
        }

        $items = BulkIntakeBatchItem::query()
            ->where('bulk_intake_batch_id', $batchId)
            ->where('input_type', BulkIntakeBatchItem::INPUT_FILE)
            ->with('biodataIntake')
            ->orderBy('item_sequence')
            ->get();

        if ($items->isEmpty()) {
            throw new RuntimeException("Batch {$batchId} has no file items.");
        }

        $preset = trim((string) config('ocr.ensemble.phase1.preprocessing_preset', 'photo_capture'));
        $predictions = [];

        foreach ($items as $item) {
            $intake = $item->biodataIntake;
            if (! $intake instanceof BiodataIntake) {
                throw new RuntimeException("Batch item #{$item->id} is missing biodata intake.");
            }

            [$absolutePath, $relativePath, $originalFilename] = $this->resolveImagePaths($item, $intake);
            $ocrInputPath = $this->preprocessedImagePath($absolutePath, $relativePath, $originalFilename, $preset);
            $ocrResult = $this->extractFromImage($engine, $ocrInputPath);

            $predictions[] = [
                'batch_item_id' => (int) $item->id,
                'item_sequence' => (int) $item->item_sequence,
                'intake_id' => (int) $intake->id,
                'original_filename' => $originalFilename,
                'raw_ocr_text' => (string) ($ocrResult['text'] ?? ''),
                'raw_ocr_len' => mb_strlen(trim((string) ($ocrResult['text'] ?? '')), 'UTF-8'),
                'ocr_time_ms' => $ocrResult['duration_ms'] ?? null,
                'engine' => $engine,
                'preprocessing_preset' => $preset,
                'preprocessing_version' => IntakeOcrEnsemblePhase1Service::PREPROCESSING_VERSION,
                'engine_meta' => $ocrResult['engine_meta'] ?? null,
            ];
        }

        return [
            'engine' => $engine,
            'batch_id' => $batchId,
            'generated_at' => now()->toIso8601String(),
            'preprocessing_preset' => $preset,
            'items' => $predictions,
        ];
    }

    public function savePredictions(array $payload, int $batchId, string $engine): string
    {
        $dir = storage_path('app/private/ocr-ensemble-benchmark/predictions');
        File::ensureDirectoryExists($dir);
        $slug = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($engine)) ?? 'engine';
        $path = $dir.DIRECTORY_SEPARATOR."batch{$batchId}_{$slug}_".date('Ymd_His').'.json';
        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $path;
    }

    /**
     * @return array{text: string, duration_ms: int|null, engine: string, engine_meta: array<string, mixed>|null}
     */
    private function extractFromImage(string $engine, string $absoluteImagePath): array
    {
        return match ($engine) {
            OcrEnsembleBenchmarkPaddleOcrClient::ENGINE => $this->paddleClient->extractFromImagePath($absoluteImagePath),
            OcrEnsembleBenchmarkEasyOcrClient::ENGINE => $this->easyOcrClient->extractFromImagePath($absoluteImagePath),
            default => throw new RuntimeException("Unsupported benchmark engine: {$engine}"),
        };
    }

    private function assertPaddleReady(): void
    {
        if (! $this->paddleClient->isConfigured()) {
            throw new RuntimeException(
                'PaddleOCR benchmark engine is not configured. Set OCR_ENSEMBLE_PADDLE_SIDECAR_URL or install tools/ocr-ensemble-paddle-sidecar.'
            );
        }

        if (! $this->paddleClient->healthCheck()) {
            throw new RuntimeException(
                'PaddleOCR benchmark engine is not reachable. Start the sidecar or install the CLI runner venv.'
            );
        }
    }

    private function assertEasyOcrReady(): void
    {
        if (! $this->easyOcrClient->isConfigured()) {
            throw new RuntimeException(
                'EasyOCR benchmark engine is not configured. Set OCR_ENSEMBLE_EASYOCR_SIDECAR_URL or install tools/ocr-ensemble-easyocr-sidecar.'
            );
        }

        if (! $this->easyOcrClient->healthCheck()) {
            throw new RuntimeException(
                'EasyOCR benchmark engine is not reachable. Start the sidecar or install the CLI runner venv.'
            );
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveImagePaths(BulkIntakeBatchItem $item, BiodataIntake $intake): array
    {
        $relativePath = trim((string) ($intake->file_path ?? ''));
        if ($relativePath === '') {
            $relativePath = trim((string) ($item->source_file_path ?? ''));
        }

        if ($relativePath === '') {
            throw new RuntimeException("Intake #{$intake->id} has no stored image path for benchmark OCR.");
        }

        $absolutePath = storage_path('app/private/'.ltrim($relativePath, '/'));
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException("Intake #{$intake->id} image not readable at {$absolutePath}");
        }

        $originalFilename = trim((string) ($item->original_filename ?? $intake->original_filename ?? basename($relativePath)));

        return [$absolutePath, $relativePath, $originalFilename];
    }

    private function preprocessedImagePath(
        string $absolutePath,
        string $relativePath,
        string $originalFilename,
        string $preset,
    ): string {
        $result = $this->imagePreprocessing->preprocessForOcr(
            $absolutePath,
            $relativePath,
            $originalFilename,
            $preset,
        );

        $preprocessed = trim((string) ($result['output_absolute_path'] ?? ''));
        if (($result['used'] ?? false) && $preprocessed !== '' && is_file($preprocessed)) {
            return $preprocessed;
        }

        return $absolutePath;
    }
}
