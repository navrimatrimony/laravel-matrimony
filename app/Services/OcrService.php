<?php

namespace App\Services;

use App\Models\BiodataIntake;
use App\Services\Domain\OcrDomainIntelligenceService;
use App\Services\Ocr\OcrNormalize;
use App\Services\Ocr\OcrPostProcessor;
use App\Services\Ocr\TesseractMultiPassOcrService;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;

class OcrService
{
    /** @var array<string, mixed>|null */
    private ?array $lastExtractTextFromPathDebug = null;

    public function __construct(
        private TesseractMultiPassOcrService $tesseractMultiPassOcr,
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

            $result = $this->tesseractMultiPassOcr->extractFromImage(
                $fullPath,
                $storagePath,
                $originalFilename,
                $presetOverride
            );
            $this->lastExtractTextFromPathDebug = $result['debug'];

            Log::info('ocr_extraction: tesseract multipass result', [
                'original_absolute_path' => $fullPath,
                'final_ocr_input_path' => $this->lastExtractTextFromPathDebug['final_ocr_input_path'] ?? null,
                'preset_request' => $presetOverride,
                'chosen_variant' => $this->lastExtractTextFromPathDebug['chosen_variant'] ?? null,
                'chosen_psm' => $this->lastExtractTextFromPathDebug['chosen_psm'] ?? null,
                'chosen_language' => $this->lastExtractTextFromPathDebug['chosen_language'] ?? null,
                'score' => $this->lastExtractTextFromPathDebug['score'] ?? null,
                'attempt_count' => $this->lastExtractTextFromPathDebug['attempt_count'] ?? null,
            ]);

            return $result['text'];
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

        return $this->buildStoredTextParseInputResponse($intake, $stored, $processed, [
            'kind' => 'stored_text',
            'ocr_source_type' => 'original',
            'ocr_pipeline' => 'stored_raw_ocr_text',
            'original_storage_relative' => $intake->file_path,
            'manual_prepared_storage_relative' => null,
            'final_ocr_input_path' => null,
            'note' => 'Parse uses upload-time OCR in raw_ocr_text (immutable). Manual crop not present.',
        ]);
    }

    /**
     * Re-parse (forceRecompute): use only immutable {@see BiodataIntake::$raw_ocr_text} from the DB —
     * same normalize → post-process → domain pipeline as normal stored-text parsing.
     * Skips manual-crop OCR, vision reuse/cache, and parse-time file OCR.
     *
     * @return array{text: string, ocr_debug: array<string, mixed>}
     */
    public function buildParseInputFromDbRawOcr(BiodataIntake $intake): array
    {
        $stored = (string) ($intake->raw_ocr_text ?? '');
        $processed = $this->ocrPostProcessor->process(
            OcrNormalize::normalizeRawTextForParsing($stored)
        );

        return $this->buildStoredTextParseInputResponse($intake, $stored, $processed, [
            'kind' => 'stored_text',
            'ocr_source_type' => 'raw_ocr_text_column',
            'ocr_pipeline' => 'reparse_raw_ocr_text_only',
            'original_storage_relative' => $intake->file_path,
            'manual_prepared_storage_relative' => null,
            'final_ocr_input_path' => null,
            'note' => 'Re-parse uses DB raw_ocr_text only (no manual OCR, no vision cache).',
            'intake_id' => $intake->id,
        ]);
    }

    /**
     * Pasted biodata (no upload file) with explicit "label :-" rows is already structured.
     * OcrDomainIntelligenceService line rewriting strips separators and can mis-map full_name.
     *
     * @param  array<string, mixed>  $ocrDebugBase
     * @return array{text: string, ocr_debug: array<string, mixed>}
     */
    private function buildStoredTextParseInputResponse(
        BiodataIntake $intake,
        string $stored,
        string $processed,
        array $ocrDebugBase,
    ): array {
        if ($this->shouldSkipDomainEnhancementForStoredText($intake, $stored)) {
            $ocrDebug = $ocrDebugBase;
            $ocrDebug['domain_intelligence_applied'] = false;
            $ocrDebug['domain_intelligence_skipped'] = 'structured_paste_biodata';

            return [
                'text' => $processed,
                'ocr_debug' => $ocrDebug,
            ];
        }

        $enhanced = $this->domainIntelligence->enhance($processed);
        $domainApplied = $enhanced !== $processed;

        $ocrDebug = $ocrDebugBase;
        if (config('app.debug')) {
            $ocrDebug['domain_intelligence_applied'] = $domainApplied;
        }

        return [
            'text' => $enhanced,
            'ocr_debug' => $ocrDebug,
        ];
    }

    private function shouldSkipDomainEnhancementForStoredText(BiodataIntake $intake, string $stored): bool
    {
        if (trim((string) ($intake->file_path ?? '')) !== '') {
            return false;
        }

        return str_contains($stored, ':-');
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

}
