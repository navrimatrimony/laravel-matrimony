<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Services\Domain\OcrDomainIntelligenceService;
use App\Services\Ocr\ImagePreprocessingService;
use App\Services\Ocr\OcrNormalize;
use App\Services\Ocr\OcrPostProcessor;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;
use thiagoalessio\TesseractOCR\TesseractOCR;

class OcrService
{
    /** @var array<string, mixed>|null */
    private ?array $lastExtractTextFromPathDebug = null;

    public function __construct(
        private ImagePreprocessingService $imagePreprocessing,
        private OcrPostProcessor $ocrPostProcessor,
        private OcrDomainIntelligenceService $domainIntelligence,
    ) {}

    /**
     * Last-run diagnostics for extractTextFromPath (upload-time OCR). Null after other methods.
     * Intended for APP_DEBUG / local tooling only — not persisted.
     *
     * @return array<string, mixed>|null
     */
    public function getLastExtractTextFromPathDebug(): ?array
    {
        return $this->lastExtractTextFromPathDebug;
    }

    /**
     * Extract text from a stored file path (e.g. after Request::file()->store('intakes')).
     * Call this BEFORE creating BiodataIntake. Throws on failure.
     *
     * @throws \RuntimeException When file is missing, unreadable, or extraction fails.
     */
    public function extractTextFromPath(string $storagePath, ?string $originalFilename = null, ?string $presetOverride = null): string
    {
        $this->lastExtractTextFromPathDebug = null;

        if ($storagePath === '') {
            throw new \RuntimeException('OCR extraction failed: no file path.');
        }

        $fullPath = storage_path('app/private/'.$storagePath);

        if (! is_file($fullPath) || ! is_readable($fullPath)) {
            throw new \RuntimeException('OCR extraction failed: file not found or not readable.');
        }

        $ext = strtolower(pathinfo($originalFilename ?? $storagePath, PATHINFO_EXTENSION));
        $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
        $isPdf = $ext === 'pdf';

        if ($isImage || $isPdf) {
            if ($isPdf) {
                $this->lastExtractTextFromPathDebug = [
                    'kind' => 'pdf',
                    'original_absolute_path' => $fullPath,
                    'original_storage_relative' => $storagePath,
                    'final_ocr_input_path' => null,
                    'preset_request' => $presetOverride,
                    'skipped_preprocessing_reason' => 'pdf_text_extract',
                ];

                return $this->extractTextFromPdf($fullPath);
            }

            $ocrInputPath = $fullPath;
            $derivedTempPath = null;
            $prep = null;
            $skippedReason = null;
            $preprocessUsed = false;
            $fallbackUsed = false;
            $presetResolved = null;

            $preprocessingOff = ($presetOverride === 'off');
            if ($preprocessingOff) {
                $skippedReason = 'off';
            }

            if (! $preprocessingOff && $this->imagePreprocessing->shouldPreprocess($storagePath, $originalFilename)) {
                $t0 = microtime(true);
                $effectivePreset = $presetOverride ?? config('ocr.preprocessing.preset_override');
                if ($effectivePreset === '' || $effectivePreset === 'auto') {
                    $effectivePreset = null;
                }
                $prep = $this->imagePreprocessing->preprocessForOcr(
                    $fullPath,
                    $storagePath,
                    $originalFilename,
                    $effectivePreset
                );
                $durationMs = (int) round((microtime(true) - $t0) * 1000);

                $preprocessUsed = (bool) ($prep['used'] ?? false);
                $fallbackUsed = (bool) ($prep['fallback_used'] ?? false);
                $presetResolved = $prep['preset'] ?? null;

                $prepMeta = is_array($prep['meta'] ?? null) ? $prep['meta'] : [];
                Log::info('ocr_extraction: preprocessing summary', [
                    'ext' => $ext,
                    'preset' => $presetResolved,
                    'preprocess_used' => $preprocessUsed,
                    'preprocess_fallback' => $fallbackUsed,
                    'preprocess_skip_reason' => $prepMeta['skipped_reason'] ?? null,
                    'duration_ms' => $durationMs,
                    'driver' => $prepMeta['driver'] ?? null,
                    'steps' => $preprocessUsed ? ($prepMeta['applied_steps'] ?? $prepMeta['steps'] ?? []) : [],
                ]);

                if (! $preprocessUsed && $skippedReason === null) {
                    $skippedReason = $prepMeta['skipped_reason'] ?? null;
                }

                if ($preprocessUsed && is_string($prep['output_absolute_path']) && $prep['output_absolute_path'] !== '') {
                    $ocrInputPath = $prep['output_absolute_path'];
                    $derivedTempPath = $prep['output_absolute_path'];
                }
            } elseif (! $preprocessingOff && ! $this->imagePreprocessing->shouldPreprocess($storagePath, $originalFilename)) {
                $skippedReason = (bool) config('ocr.preprocessing.enabled', true) ? 'not_applicable' : 'preprocessing_disabled';
            }

            $originalSize = @filesize($fullPath);
            $derivedSize = ($derivedTempPath !== null && is_file($derivedTempPath)) ? @filesize($derivedTempPath) : null;

            $keepDerived = $derivedTempPath !== null
                && is_file($derivedTempPath)
                && config('app.debug')
                && (bool) config('ocr.preprocessing.debug_keep_derived_when_app_debug', true);

            $prepMetaForDebug = (is_array($prep) && is_array($prep['meta'] ?? null)) ? $prep['meta'] : [];

            $ocrPipeline = 'direct_from_original';
            if ($preprocessingOff) {
                $ocrPipeline = 'preprocessing_off';
            } elseif ($preprocessUsed) {
                $ocrPipeline = 'auto_preprocessed';
            }

            $this->lastExtractTextFromPathDebug = [
                'kind' => 'image',
                'ocr_pipeline' => $ocrPipeline,
                'original_absolute_path' => $fullPath,
                'original_storage_relative' => $storagePath,
                'derived_absolute_path' => $derivedTempPath,
                'derived_storage_relative' => $preprocessUsed && is_array($prep) ? ($prep['output_path'] ?? null) : null,
                'final_ocr_input_path' => $ocrInputPath,
                'preset_request' => $presetOverride,
                'preset_resolved' => $presetResolved,
                'preprocess_used' => $preprocessUsed,
                'fallback_used' => $fallbackUsed,
                'skipped_preprocessing_reason' => $skippedReason,
                'derived_kept_on_disk' => $keepDerived,
                'original_filesize' => $originalSize !== false ? $originalSize : null,
                'derived_filesize' => $derivedSize !== false ? $derivedSize : null,
                'original_width' => $prepMetaForDebug['original_width'] ?? null,
                'original_height' => $prepMetaForDebug['original_height'] ?? null,
                'derived_width' => $prepMetaForDebug['width'] ?? null,
                'derived_height' => $prepMetaForDebug['height'] ?? null,
                'driver' => $prepMetaForDebug['driver'] ?? null,
                'output_format' => $prepMetaForDebug['output_format'] ?? null,
                'applied_steps' => $prepMetaForDebug['applied_steps'] ?? $prepMetaForDebug['steps'] ?? [],
                'driver_resolution_diagnostics' => config('app.debug') ? ($prepMetaForDebug['resolution_diagnostics'] ?? null) : null,
            ];

            if ($this->lastExtractTextFromPathDebug['original_width'] === null) {
                $dim = @getimagesize($fullPath);
                if (is_array($dim)) {
                    $this->lastExtractTextFromPathDebug['original_width'] = $dim[0] ?? null;
                    $this->lastExtractTextFromPathDebug['original_height'] = $dim[1] ?? null;
                }
            }

            Log::info('ocr_extraction: tesseract input', [
                'original_absolute_path' => $fullPath,
                'derived_absolute_path' => $derivedTempPath,
                'final_ocr_input_path' => $ocrInputPath,
                'preset_request' => $presetOverride,
                'preset_resolved' => $presetResolved,
                'preprocess_used' => $preprocessUsed,
                'fallback_used' => $fallbackUsed,
                'original_filesize' => $this->lastExtractTextFromPathDebug['original_filesize'],
                'derived_filesize' => $this->lastExtractTextFromPathDebug['derived_filesize'],
                'original_width' => $this->lastExtractTextFromPathDebug['original_width'],
                'original_height' => $this->lastExtractTextFromPathDebug['original_height'],
                'derived_width' => $this->lastExtractTextFromPathDebug['derived_width'],
                'derived_height' => $this->lastExtractTextFromPathDebug['derived_height'],
            ]);

            try {
                return $this->runTesseract($ocrInputPath);
            } finally {
                $shouldDelete = $derivedTempPath !== null
                    && is_file($derivedTempPath)
                    && (bool) config('ocr.preprocessing.cleanup_enabled', true)
                    && ! $keepDerived;

                if ($shouldDelete) {
                    @unlink($derivedTempPath);
                }
            }
        }

        $this->lastExtractTextFromPathDebug = [
            'kind' => 'raw_file',
            'original_absolute_path' => $fullPath,
            'original_storage_relative' => $storagePath,
            'final_ocr_input_path' => null,
            'preset_request' => $presetOverride,
            'skipped_preprocessing_reason' => 'non_image_non_pdf_binary',
        ];

        $contents = @file_get_contents($fullPath);
        if ($contents === false) {
            throw new \RuntimeException('OCR extraction failed: could not read file.');
        }

        return $contents;
    }

    /**
     * Extract text from intake: file (image/PDF) or existing raw_ocr_text.
     * Does NOT modify the intake or database. Used only when intake already exists (e.g. legacy).
     */
    public function extractText(BiodataIntake $intake): string
    {
        if ($intake->file_path === null || $intake->file_path === '') {
            return (string) ($intake->raw_ocr_text ?? '');
        }

        $fullPath = storage_path('app/private/'.$intake->file_path);

        if (! is_file($fullPath) || ! is_readable($fullPath)) {
            throw new \RuntimeException('OCR extraction failed: file not found or not readable.');
        }

        $ext = strtolower(pathinfo($intake->original_filename ?? $intake->file_path, PATHINFO_EXTENSION));
        $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
        $isPdf = $ext === 'pdf';

        if ($isImage || $isPdf) {
            if ($isPdf) {
                return $this->extractTextFromPdf($fullPath);
            }

            return $this->resolveParseInputText($intake)['text'];
        }

        $contents = @file_get_contents($fullPath);
        if ($contents === false) {
            throw new \RuntimeException('OCR extraction failed: could not read file.');
        }

        return $contents;
    }

    /**
     * Text used by ParseIntakeJob. When a manual crop exists, OCR runs on that derived PNG
     * (raw_ocr_text remains immutable). Otherwise uses normalized stored upload-time OCR text.
     *
     * Output is passed through {@see OcrPostProcessor} after normalization — parse-only; never persisted as raw_ocr_text.
     * Upload-time OCR via {@see extractTextFromPath} is unchanged (no post-processor on stored SSOT).
     *
     * @param  array{force_preset?: string}  $options  Parse-time only: {@see force_preset} overrides manual-crop OCR preset when re-OCRing manual.png.
     * @return array{text: string, ocr_debug: array<string, mixed>}
     */
    public function resolveParseInputText(BiodataIntake $intake, array $options = []): array
    {
        $manual = app(IntakeManualOcrPreparedService::class);

        if ($manual->exists($intake)) {
            $rel = $manual->relativePath($intake);
            $forcePreset = isset($options['force_preset']) ? trim((string) $options['force_preset']) : '';
            if ($forcePreset !== '') {
                $preset = $forcePreset;
            } else {
                $preset = (string) config('ocr.intake_manual_crop.ocr_preprocessing_preset', 'off');
                if ($preset === '' || $preset === 'auto') {
                    $preset = 'off';
                }
            }

            $validPresets = array_keys(config('ocr.preprocessing.presets', []));
            if ($preset !== 'off' && ! in_array($preset, $validPresets, true)) {
                $preset = (string) config('ocr.intake_manual_crop.ocr_preprocessing_preset', 'off');
                if ($preset === '' || $preset === 'auto') {
                    $preset = 'off';
                }
            }

            try {
                $text = $this->extractTextFromPath($rel, 'manual.png', $preset);
            } catch (\Throwable $e) {
                Log::warning('ocr_manual_prepared_extraction_failed', [
                    'intake_id' => $intake->id,
                    'error' => $e->getMessage(),
                ]);
                $text = (string) ($intake->raw_ocr_text ?? '');
            }

            $dbg = $this->getLastExtractTextFromPathDebug();
            if (! is_array($dbg)) {
                $dbg = [];
            }

            $dbg['ocr_source_type'] = 'manual_prepared';
            $dbg['ocr_pipeline'] = ($dbg['preprocess_used'] ?? false) ? 'manual_then_auto_preprocessed' : 'manual_then_direct_tesseract';
            $dbg['manual_prepared_storage_relative'] = $rel;
            $dbg['manual_prepared_absolute_path'] = $manual->absolutePath($intake);
            $dbg['original_upload_storage_relative'] = $intake->file_path;

            $normalized = OcrNormalize::normalizeRawTextForParsing($text);

            $processed = $this->ocrPostProcessor->process($normalized);
            $enhanced = $this->domainIntelligence->enhance($processed);

            if (config('app.debug')) {
                $dbg['domain_intelligence_applied'] = $enhanced !== $processed;
            }

            return [
                'text' => $enhanced,
                'ocr_debug' => $dbg,
            ];
        }

        $stored = (string) ($intake->raw_ocr_text ?? '');

        // If upload-time OCR text is missing/blank (common when Tesseract was misconfigured at upload time),
        // re-run OCR at parse-time using the best available stored artifact. SSOT preserved: raw_ocr_text remains immutable.
        if (trim($stored) === '') {
            $src = $this->resolveEffectiveOcrSource($intake);
            if ($src !== null) {
                $ext = strtolower(pathinfo($intake->original_filename ?? $src['relative_path'], PATHINFO_EXTENSION));
                $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
                $isPdf = $ext === 'pdf';

                if (($isImage || $isPdf) && is_file($src['absolute_path']) && is_readable($src['absolute_path'])) {
                    try {
                        // Parse-time re-OCR should not apply preprocessing presets unless explicitly asked.
                        $text = $this->extractTextFromPath($src['relative_path'], $intake->original_filename, 'off');
                    } catch (\Throwable $e) {
                        $text = '';
                    }

                    $dbg = $this->getLastExtractTextFromPathDebug();
                    if (! is_array($dbg)) {
                        $dbg = [
                            'kind' => $isPdf ? 'pdf' : 'image',
                            'final_ocr_input_path' => $src['absolute_path'],
                        ];
                    }

                    $dbg['ocr_source_type'] = $src['source_field'];
                    $dbg['ocr_source_relative_path'] = $src['relative_path'];
                    $dbg['ocr_pipeline'] = ($dbg['preprocess_used'] ?? false) ? 'rerun_auto_preprocessed' : 'rerun_direct_tesseract';
                    $dbg['fallback_ocr_used'] = true;
                    $dbg['note'] = 'Parse-time OCR re-run because stored raw_ocr_text was blank (SSOT preserved).';

                    $normalized = OcrNormalize::normalizeRawTextForParsing($text);
                    $processed = $this->ocrPostProcessor->process($normalized);
                    $enhanced = $this->domainIntelligence->enhance($processed);

                    if (config('app.debug')) {
                        $dbg['domain_intelligence_applied'] = $enhanced !== $processed;
                    }

                    return [
                        'text' => $enhanced,
                        'ocr_debug' => $dbg,
                    ];
                }
            }

            return [
                'text' => '',
                'ocr_debug' => [
                    'kind' => 'missing_source',
                    'ocr_source_type' => 'none',
                    'fallback_ocr_used' => false,
                    'skipped_reason' => 'raw_ocr_blank_and_no_source_file_found',
                ],
            ];
        }

        $processed = $this->ocrPostProcessor->process(
            OcrNormalize::normalizeRawTextForParsing($stored)
        );

        $enhanced = $this->domainIntelligence->enhance($processed);
        $domainApplied = $enhanced !== $processed;

        $ocrDebug = [
            'kind' => 'stored_text',
            'ocr_source_type' => 'original',
            'ocr_pipeline' => 'stored_raw_ocr_text',
            'original_storage_relative' => $intake->file_path,
            'manual_prepared_storage_relative' => null,
            'final_ocr_input_path' => null,
            'note' => 'Parse uses upload-time OCR in raw_ocr_text (immutable). Manual crop not present.',
        ];

        if (config('app.debug')) {
            $ocrDebug['domain_intelligence_applied'] = $domainApplied;
        }

        return [
            'text' => $enhanced,
            'ocr_debug' => $ocrDebug,
        ];
    }

    /**
     * Resolve which on-disk file should be used for OCR when we must read bytes.
     *
     * Precedence:
     * 1) manual prepared (handled earlier in resolveParseInputText)
     * 2) stored_file_path
     * 3) file_path
     *
     * @return array{source_field: string, relative_path: string, absolute_path: string}|null
     */
    public function resolveEffectiveOcrSource(BiodataIntake $intake): ?array
    {
        $storedRel = is_scalar($intake->stored_file_path ?? null) ? trim((string) $intake->stored_file_path) : '';
        $uploadRel = is_scalar($intake->file_path ?? null) ? trim((string) $intake->file_path) : '';

        foreach ([
            'stored_file_path' => $storedRel,
            'file_path' => $uploadRel,
        ] as $field => $rel) {
            if ($rel === '') {
                continue;
            }
            $abs = storage_path('app/private/'.$rel);
            if (is_file($abs) && is_readable($abs)) {
                return [
                    'source_field' => $field,
                    'relative_path' => $rel,
                    'absolute_path' => $abs,
                ];
            }
        }

        return null;
    }

    /**
     * Extract text from a PDF (text-based PDFs). Returns empty string for scanned/image-only PDFs or on failure.
     */
    private function extractTextFromPdf(string $fullPath): string
    {
        try {
            $parser = new PdfParser;
            $pdf = $parser->parseFile($fullPath);
            $text = $pdf->getText();

            return is_string($text) ? trim($text) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Run Tesseract OCR on an image file. Returns trimmed text or empty string on failure.
     */
    private function runTesseract(string $fullPath): string
    {
        try {
            $ocrProvider = AdminSetting::getValue('intake_ocr_provider', 'tesseract');
            if ($ocrProvider === 'off') {
                return '';
            }

            // For now we only support local Tesseract; future providers can be plugged in here.
            $ocr = new TesseractOCR($fullPath);
            $exe = trim((string) config('services.tesseract.path'));
            // If TESSERACT_PATH points to a missing/invalid binary (common on local Windows installs),
            // fall back to default executable resolution (PATH).
            if ($exe !== '' && is_file($exe)) {
                $ocr->executable($exe);
            }
            // OEM 1 = LSTM only; PSM 6 = uniform block of text (typical biodata layout).
            $ocr->oem(1);
            $ocr->psm(6);

            $langHint = AdminSetting::getValue('intake_ocr_language_hint', 'mixed');
            if ($langHint === 'mr') {
                $ocr->lang('mar');
            } elseif ($langHint === 'en') {
                $ocr->lang('eng');
            } else {
                // Mixed Marathi + English (labels, degrees, company names).
                $ocr->lang('mar', 'eng');
            }
            $text = $ocr->run();

            return trim($text);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
