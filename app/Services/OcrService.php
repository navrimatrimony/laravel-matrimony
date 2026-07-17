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
                return $this->extractTextFromPdf($fullPath, $storagePath, $presetOverride);
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
                return $this->extractTextFromPdf($fullPath, (string) $intake->file_path, 'off');
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
     * Extract text from a PDF.
     *
     * Prefer embedded text when usable (digital biodata PDFs).
     * For scanned/image-only PDFs (common in production), rasterize pages via Imagick
     * and run the same Tesseract multipass path used for photos — improves RAW OCR fidelity.
     * Never overwrites the uploaded PDF; page images are temporary only.
     */
    private function extractTextFromPdf(
        string $fullPath,
        ?string $storageRelative = null,
        ?string $presetOverride = null
    ): string {
        $embedded = $this->extractEmbeddedPdfText($fullPath);
        $embeddedUsable = $this->pdfEmbeddedTextIsUsable($embedded);

        $this->lastExtractTextFromPathDebug = [
            'kind' => 'pdf',
            'original_absolute_path' => $fullPath,
            'original_storage_relative' => $storageRelative,
            'final_ocr_input_path' => null,
            'preset_request' => $presetOverride,
            'pdf_embedded_chars' => mb_strlen($embedded, 'UTF-8'),
            'pdf_embedded_usable' => $embeddedUsable,
            'skipped_preprocessing_reason' => $embeddedUsable ? 'pdf_text_extract' : null,
            'pdf_pipeline' => $embeddedUsable ? 'embedded_text' : 'pending_raster',
        ];

        if ($embeddedUsable) {
            $enriched = $this->enrichEmbeddedPdfWithNameBand($fullPath, $embedded);
            if ($enriched !== $embedded) {
                $this->lastExtractTextFromPathDebug['pdf_pipeline'] = 'embedded_text_plus_name_band';
                $this->lastExtractTextFromPathDebug['name_band_merged'] = true;
            }

            return $enriched;
        }

        $raster = $this->extractTextFromPdfViaRasterOcr($fullPath, $storageRelative, $presetOverride);
        if (trim($raster) !== '') {
            $this->lastExtractTextFromPathDebug['pdf_pipeline'] = 'raster_ocr_fallback';
            $this->lastExtractTextFromPathDebug['skipped_preprocessing_reason'] = 'pdf_raster_ocr';

            return $raster;
        }

        $this->lastExtractTextFromPathDebug['pdf_pipeline'] = trim($embedded) !== ''
            ? 'embedded_text_weak_kept'
            : 'pdf_raster_failed_empty';

        return $embedded;
    }

    /**
     * Embedded PDF text can carry wrong चि/शि name glyphs while a top-band raster reads correctly.
     * Additive only: prepend मुलाचे/मुलीचे label lines from page-0 band OCR.
     */
    private function enrichEmbeddedPdfWithNameBand(string $fullPath, string $embedded): string
    {
        if (! class_exists(\Imagick::class) || trim($embedded) === '') {
            return $embedded;
        }

        $this->ensureGhostscriptAvailableForImagick();
        $tmp = null;
        try {
            $image = new \Imagick;
            $image->setResolution(200, 200);
            $image->readImage($fullPath.'[0]');
            $image->setImageBackgroundColor('white');
            try {
                $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            } catch (\Throwable) {
                // Some pages lack alpha.
            }
            $image->setImageFormat('png');
            $tmp = storage_path('app/private/ocr-temp/pdf-name-band-'.uniqid('', true).'.png');
            if (! is_dir(dirname($tmp))) {
                mkdir(dirname($tmp), 0755, true);
            }
            $image->writeImage($tmp);
            $image->clear();

            $bandLines = $this->tesseractMultiPassOcr->extractNameBandLabelLinesFromImage($tmp);
            if ($bandLines === '') {
                return $embedded;
            }

            return $bandLines."\n\n".$embedded;
        } catch (\Throwable $e) {
            Log::debug('ocr_pdf_name_band: skipped', ['path' => $fullPath, 'error' => $e->getMessage()]);

            return $embedded;
        } finally {
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function extractEmbeddedPdfText(string $fullPath): string
    {
        try {
            $parser = new PdfParser;
            $pdf = $parser->parseFile($fullPath);
            $text = $pdf->getText();

            return is_string($text) ? trim($text) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Digital biodata PDFs usually have substantial selectable text.
     * Empty / tiny / encoding-garbage layers (common WinAnsi/ITRANS scans) need raster OCR.
     */
    private function pdfEmbeddedTextIsUsable(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        $len = mb_strlen($text, 'UTF-8');
        if ($len < 50) {
            return false;
        }

        if (preg_match('/\p{Devanagari}/u', $text) === 1) {
            // Megapage / broken PDF text layers glue biodata into 1–2 huge lines
            // (e.g. नावनवनाथ…णे तारीख…जातिहंदू). Prefer page raster for raw fidelity.
            $nonEmptyLines = 0;
            foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                if (trim($line) !== '') {
                    $nonEmptyLines++;
                }
            }
            if ($nonEmptyLines <= 2 && $len >= 400
                && preg_match('/नावन|णे\s*तारीख|जातिहंदू/u', $text) === 1) {
                return false;
            }

            return true;
        }

        // Real English biodata layers carry recognizable field words.
        if (preg_match('/\b(name|dob|date of birth|mobile|education|caste|religion|height)\b/iu', $text) === 1) {
            return true;
        }

        // Long Latin without Devanagari/keywords is usually a broken text layer
        // (e.g. ITRANS garbage). Prefer page raster → Tesseract for raw fidelity.
        return false;
    }

    /**
     * Ensure Ghostscript is visible to Imagick on Windows (user PDF raster OCR).
     * Prefer already-configured PATH; otherwise use agent-installed user-local GS.
     */
    private function ensureGhostscriptAvailableForImagick(): void
    {
        $localApp = getenv('LOCALAPPDATA') ?: (getenv('USERPROFILE') ? getenv('USERPROFILE').'\\AppData\\Local' : null);
        $candidates = [
            getenv('MAGICK_GHOSTSCRIPT_PATH') ?: null,
            $localApp ? $localApp.'\\Ghostscript\\extracted\\bin\\gswin64c.exe' : null,
            $localApp ? $localApp.'\\Ghostscript\\extracted\\bin\\gs.exe' : null,
        ];

        // Common Chocolatey / Program Files layouts
        foreach ([
            'C:\\Program Files\\gs',
            'C:\\Program Files\\Ghostscript',
        ] as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $hits = glob($root.'\\*\\bin\\gswin64c.exe') ?: [];
            foreach ($hits as $hit) {
                $candidates[] = $hit;
            }
        }

        $gs = null;
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                $gs = $candidate;
                break;
            }
        }

        if ($gs === null) {
            return;
        }

        putenv('MAGICK_GHOSTSCRIPT_PATH='.$gs);
        $_ENV['MAGICK_GHOSTSCRIPT_PATH'] = $gs;
        $bin = dirname($gs);
        $path = getenv('PATH') ?: '';
        if (! str_contains($path, $bin)) {
            putenv('PATH='.$bin.PATH_SEPARATOR.$path);
            $_ENV['PATH'] = $bin.PATH_SEPARATOR.$path;
        }
    }

    /**
     * Rasterize PDF pages to temporary PNGs and OCR each page (production scanned biodata).
     */
    private function extractTextFromPdfViaRasterOcr(
        string $fullPath,
        ?string $storageRelative = null,
        ?string $presetOverride = null
    ): string {
        if (! class_exists(\Imagick::class)) {
            Log::warning('ocr_pdf_raster: Imagick unavailable', ['path' => $fullPath]);
            if (is_array($this->lastExtractTextFromPathDebug)) {
                $this->lastExtractTextFromPathDebug['pdf_raster_error'] = 'imagick_unavailable';
            }

            return '';
        }

        $this->ensureGhostscriptAvailableForImagick();

        $maxPages = 8;
        try {
            $maxPages = (int) \App\Models\AdminSetting::getValue('intake_max_pdf_pages', 8);
        } catch (\Throwable) {
            $maxPages = 8;
        }
        if ($maxPages < 1) {
            $maxPages = 1;
        }
        if ($maxPages > 50) {
            $maxPages = 50;
        }

        $tmpDir = storage_path('app/private/ocr-temp/pdf-raster/'.hash('sha256', $fullPath.microtime(true)));
        if (! is_dir($tmpDir) && ! mkdir($tmpDir, 0755, true) && ! is_dir($tmpDir)) {
            Log::warning('ocr_pdf_raster: cannot create temp dir', ['dir' => $tmpDir]);

            return '';
        }

        $pagePaths = [];
        $texts = [];
        $pageDebug = [];

        try {
            $image = new \Imagick;
            $image->setResolution(300, 300);
            $image->readImage($fullPath);
            $pageCount = $image->getNumberImages();
            $limit = min($pageCount, $maxPages);

            for ($i = 0; $i < $limit; $i++) {
                $image->setIteratorIndex($i);
                $page = $image->getImage();
                $page->setImageBackgroundColor('white');
                try {
                    $page->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                    $page->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                } catch (\Throwable) {
                    // Some PDF pages lack alpha; continue.
                }
                $page->setImageFormat('png');
                $pagePath = $tmpDir.DIRECTORY_SEPARATOR.'page-'.($i + 1).'.png';
                $page->writeImage($pagePath);
                $page->clear();
                $pagePaths[] = $pagePath;

                $rel = ($storageRelative ?: 'pdf-raster').'/page-'.($i + 1).'.png';
                $result = $this->tesseractMultiPassOcr->extractFromImage(
                    $pagePath,
                    $rel,
                    'pdf-page-'.($i + 1).'.png',
                    // PDF page rasters are already large; null/auto preprocessing can burn the
                    // multipass time budget before any Tesseract attempt (attempt_count=0).
                    $presetOverride ?? 'off'
                );
                $pageText = trim((string) ($result['text'] ?? ''));
                if ($pageText !== '') {
                    $texts[] = $pageText;
                }
                $pageDebug[] = [
                    'page' => $i + 1,
                    'chars' => mb_strlen($pageText, 'UTF-8'),
                    'chosen_variant' => $result['debug']['chosen_variant'] ?? null,
                ];
            }

            $image->clear();
        } catch (\Throwable $e) {
            Log::warning('ocr_pdf_raster: failed', [
                'path' => $fullPath,
                'error' => $e->getMessage(),
            ]);
            if (is_array($this->lastExtractTextFromPathDebug)) {
                $this->lastExtractTextFromPathDebug['pdf_raster_error'] = $e->getMessage();
            }
        } finally {
            foreach ($pagePaths as $pagePath) {
                if (is_file($pagePath)) {
                    @unlink($pagePath);
                }
            }
            if (is_dir($tmpDir)) {
                @rmdir($tmpDir);
            }
        }

        if (is_array($this->lastExtractTextFromPathDebug)) {
            $this->lastExtractTextFromPathDebug['pdf_raster_pages'] = $pageDebug;
            $this->lastExtractTextFromPathDebug['final_ocr_input_path'] = $pagePaths[0] ?? null;
        }

        return trim(implode("\n\n", $texts));
    }
}
