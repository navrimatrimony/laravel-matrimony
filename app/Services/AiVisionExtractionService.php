<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

/**
 * AiVisionExtractionService
 *
 * Extract plain text directly from an uploaded biodata file (image/PDF) using OpenAI Vision-capable chat model.
 *
 * SSOT constraints:
 * - Does NOT write to DB
 * - Does NOT touch raw_ocr_text
 * - Returns transient extracted text for parse-time only
 */
class AiVisionExtractionService
{
    /**
     * Prompt contract for OpenAI vision transcription.
     * Exposed for focused regression tests so critical constraints do not regress.
     *
     * @return array{system: string, user: string}
     */
    public static function openAiVisionTranscriptionPrompt(): array
    {
        $system = 'You are a PURE DOCUMENT TEXT TRANSCRIBER for Marathi + English matrimony biodata. '
            .'You are not an image describer, not a formatter, and not a parser. '
            .'Transcribe ONLY visible written document text. Preserve original wording, separators, punctuation, and line order. '
            .'Preserve separators exactly as printed, including forms like ":-", ":", "-", ".", "/", "(", ")" and spacing around them. '
            .'Do not normalize punctuation, do not rewrite, do not translate, do not expand abbreviations, do not correct spelling, and do not infer missing text. '
            .'Ignore all non-text visual elements: photos, portraits, faces, clothing, pose, logos, stamps, watermarks, borders, ornaments, decorative/background graphics. '
            .'For two-column or side-by-side sections: never collapse both columns into one ambiguous running line. '
            .'If exact side-by-side spacing is difficult, output columns as separate blocks one below the other while preserving each column content and separators faithfully. '
            .'Output plain text only. No markdown, no JSON, no base64, no commentary, no added headings, no preamble, no footer.';

        $user = 'Return only the faithful raw transcription text from the biodata document.';

        return ['system' => $system, 'user' => $user];
    }

    /**
     * Which backend performs AI vision transcription for `ai_vision_extract_v1`.
     * Resolution: AdminSetting `intake_ai_vision_provider` → config(`intake.ai_vision_extract.provider`) → `openai`.
     *
     * @return array{provider: string, provider_source: string}
     */
    public function resolveExtractionProvider(): array
    {
        $raw = AdminSetting::getValue('intake_ai_vision_provider', '');
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === 'openai' || $raw === 'sarvam') {
            return ['provider' => $raw, 'provider_source' => 'admin_setting'];
        }
        if ($raw !== '') {
            Log::warning('AiVisionExtractionService: invalid intake_ai_vision_provider; falling back to config', ['value' => $raw]);
        }

        $cfg = strtolower(trim((string) config('intake.ai_vision_extract.provider', 'openai')));
        if ($cfg === 'sarvam' || $cfg === 'openai') {
            return ['provider' => $cfg, 'provider_source' => 'config_env'];
        }

        return ['provider' => 'openai', 'provider_source' => 'default'];
    }

    /**
     * Human-readable single-line summary for UI/debug when AI extraction fails (not OCR).
     *
     * @param  array<string, mixed>  $meta
     */
    public static function failureDetailForUi(array $meta): string
    {
        $reason = (string) ($meta['reason'] ?? '');
        $provider = (string) ($meta['provider'] ?? 'unknown');
        $parts = [];

        $parts[] = __('intake.ai_extraction_failed_provider', ['provider' => $provider]);

        if ($reason !== '') {
            $rk = 'intake.ai_extraction_reason.'.$reason;
            $parts[] = Lang::has($rk) ? __($rk) : $reason;
        }

        if (! empty($meta['status'])) {
            $parts[] = 'HTTP '.(string) $meta['status'];
        }

        if (! empty($meta['job_error_message'])) {
            $parts[] = (string) $meta['job_error_message'];
        }

        if (! empty($meta['job_state'])) {
            $parts[] = 'job_state='.(string) $meta['job_state'];
        }

        if (! empty($meta['error'])) {
            $parts[] = (string) $meta['error'];
        }

        if (! empty($meta['response_body_snippet'])) {
            $parts[] = Str::limit((string) $meta['response_body_snippet'], 400, '…');
        }

        return implode(' — ', array_filter($parts));
    }

    /**
     * @param  array<string, mixed>  $qualityGate  evaluateExtractedTextQuality result
     */
    public static function qualityFailureDetailForUi(array $qualityGate): string
    {
        $code = (string) ($qualityGate['reason'] ?? '');

        return __('intake.ai_extraction_quality_failed', [
            'code' => $code,
            'chars' => (int) ($qualityGate['chars'] ?? 0),
            'lines' => (int) ($qualityGate['lines'] ?? 0),
        ]);
    }

    /**
     * Labels the raw parse-input panel when parser mode is ai_vision_extract_v1 (success path).
     *
     * @param  array<string, mixed>  $parseInputDebug  intake.parse_input_debug.* cache payload
     * @return array{heading_key: string, params: array<string, string>}
     */
    public static function provenanceForPreview(array $parseInputDebug): array
    {
        $extraction = $parseInputDebug['extraction'] ?? null;
        $provider = $parseInputDebug['provider'] ?? null;
        $providerSource = (string) ($parseInputDebug['provider_source'] ?? '');
        $psKey = 'intake.provider_source_'.$providerSource;
        $providerSourceLabel = $providerSource !== ''
            ? (Lang::has($psKey) ? (string) __($psKey) : $providerSource)
            : (string) __('intake.provider_source_default');

        if ($extraction === 'pdf_text_extract_local' || $provider === 'pdfparser') {
            return [
                'heading_key' => 'intake.preview_source_pdf_text_local',
                'params' => [
                    'provider_source' => $providerSourceLabel,
                ],
            ];
        }

        if ($provider === 'sarvam') {
            return [
                'heading_key' => 'intake.preview_source_sarvam',
                'params' => [
                    'provider_source' => $providerSourceLabel,
                ],
            ];
        }

        if ($provider === 'openai' && $extraction === 'ai_vision_transcribe') {
            $model = (string) ($parseInputDebug['model'] ?? '');
            if ($model === '') {
                $model = '—';
            }

            return [
                'heading_key' => 'intake.preview_source_openai_vision',
                'params' => [
                    'provider_source' => $providerSourceLabel,
                    'model' => $model,
                ],
            ];
        }

        return [
            'heading_key' => 'intake.preview_source_ai_generic',
            'params' => [
                'provider' => (string) ($provider ?? '—'),
                'provider_source' => $providerSourceLabel,
            ],
        ];
    }

    private static function httpBodySnippet(?string $body): ?string
    {
        if (! is_string($body) || trim($body) === '') {
            return null;
        }

        return Str::limit(trim($body), 500, '…');
    }

    /**
     * @return array{text: string, meta: array<string, mixed>}
     */
    public function extractTextForIntake(BiodataIntake $intake): array
    {
        $resolved = $this->resolveExtractionProvider();
        $provider = $resolved['provider'];
        $providerSource = $resolved['provider_source'];

        // Resolve effective source path precedence:
        // 1) manual prepared image (if exists)
        // 2) stored_file_path (if exists)
        // 3) file_path (if exists)
        $manual = app(IntakeManualOcrPreparedService::class);
        if ($manual->exists($intake)) {
            $rel = $manual->relativePath($intake);
            $abs = $manual->absolutePath($intake);
            $sourceField = 'manual_prepared_image_path';
        } else {
            $src = app(OcrService::class)->resolveEffectiveOcrSource($intake);
            $rel = $src['relative_path'] ?? '';
            $abs = $src['absolute_path'] ?? '';
            $sourceField = $src['source_field'] ?? 'none';
        }

        if ($rel === '' || $abs === '' || ! is_file($abs) || ! is_readable($abs)) {
            return [
                'text' => '',
                'meta' => [
                    'ok' => false,
                    'reason' => 'no_readable_source_file',
                    'source_field' => $sourceField,
                    'relative_path' => $rel,
                    'absolute_path' => $abs,
                    'provider' => $provider,
                    'provider_source' => $providerSource,
                ],
            ];
        }

        if ($provider === 'sarvam') {
            return $this->extractViaSarvamDocumentIntelligence($abs, $intake->original_filename ?? basename($rel), [
                'source_field' => $sourceField,
                'relative_path' => $rel,
                'absolute_path' => $abs,
                'provider' => $provider,
                'provider_source' => $providerSource,
            ]);
        }

        return $this->extractViaOpenAiVision($abs, $intake->original_filename ?? basename($rel), [
            'source_field' => $sourceField,
            'relative_path' => $rel,
            'absolute_path' => $abs,
            'provider' => $provider,
            'provider_source' => $providerSource,
        ]);
    }

    /**
     * Generic sanity gate for extracted text before parsing.
     *
     * @return array{ok: bool, reason: string|null, chars: int, non_space_chars: int, lines: int, alpha_ratio: float}
     */
    public function evaluateExtractedTextQuality(string $text): array
    {
        $t = trim($text);
        $chars = mb_strlen($t);
        $nonSpace = mb_strlen(preg_replace('/\s+/u', '', $t) ?? '');
        $lines = max(1, substr_count($t, "\n") + 1);

        // Count letter-like chars (Latin + Devanagari) as a proxy for "not garbage".
        // Use literal Unicode range chars (PCRE2 in some builds rejects \uXXXX escapes).
        preg_match_all('/[A-Za-zऀ-ॿ]/u', $t, $m);
        $alpha = is_array($m[0] ?? null) ? count($m[0]) : 0;
        $alphaRatio = $nonSpace > 0 ? ($alpha / $nonSpace) : 0.0;

        $minChars = (int) config('intake.ai_vision_extract.min_extracted_chars', 180);
        $minNonSpace = (int) config('intake.ai_vision_extract.min_extracted_non_space', 120);
        $minLines = (int) config('intake.ai_vision_extract.min_extracted_lines', 2);

        if ($t === '') {
            return ['ok' => false, 'reason' => 'ai_vision_text_blank', 'chars' => 0, 'non_space_chars' => 0, 'lines' => 0, 'alpha_ratio' => 0.0];
        }
        if ($chars < $minChars || $nonSpace < $minNonSpace || $lines < $minLines) {
            return ['ok' => false, 'reason' => 'ai_vision_text_too_short', 'chars' => $chars, 'non_space_chars' => $nonSpace, 'lines' => $lines, 'alpha_ratio' => $alphaRatio];
        }
        if ($alphaRatio < 0.12) {
            return ['ok' => false, 'reason' => 'ai_vision_text_unusable', 'chars' => $chars, 'non_space_chars' => $nonSpace, 'lines' => $lines, 'alpha_ratio' => $alphaRatio];
        }

        return ['ok' => true, 'reason' => null, 'chars' => $chars, 'non_space_chars' => $nonSpace, 'lines' => $lines, 'alpha_ratio' => $alphaRatio];
    }

    /**
     * Strip accidental formatting from the model; do not rewrite document wording.
     */
    public function sanitizeTranscriptionResponse(string $raw): string
    {
        $t = str_replace(["\r\n", "\r"], "\n", $raw);
        $t = trim($t);
        // Remove wrapping markdown fences (model sometimes adds them despite instructions).
        if (preg_match('/^```(?:plaintext|text)?\s*\R/s', $t) || preg_match('/\R```\s*$/', $t)) {
            $t = preg_replace('/^```(?:plaintext|text)?\s*\R?/u', '', $t) ?? $t;
            $t = preg_replace('/\R```\s*$/u', '', $t) ?? $t;
            $t = trim($t);
        }
        // Single-line preamble like "Transcription:" only when the rest looks like biodata (heuristic: keep if multi-line).
        if (substr_count($t, "\n") === 0 && preg_match('/^(transcription|here\s+is|below\s+is)\s*[:：]/iu', $t)) {
            $t = preg_replace('/^[^:]+:\s*/iu', '', $t) ?? $t;
            $t = trim($t);
        }

        return $t;
    }

    /**
     * Build a transient raster (same folder semantics as OCR tmp) for outbound AI only: EXIF orientation + bounded upscale.
     *
     * @return array{path: string, cleanup: ?string, mime: ?string, meta: array<string, mixed>}
     */
    private function prepareRasterForAiPayload(string $absolutePath, string $ext): array
    {
        $meta = [
            'original_image_width' => null,
            'original_image_height' => null,
            'ai_request_image_width' => null,
            'ai_request_image_height' => null,
            'ai_request_payload_enhanced' => false,
            'ai_request_orientation_corrected' => false,
        ];

        $sz = @getimagesize($absolutePath);
        if (is_array($sz)) {
            $meta['original_image_width'] = $sz[0];
            $meta['original_image_height'] = $sz[1];
            $meta['ai_request_image_width'] = $sz[0];
            $meta['ai_request_image_height'] = $sz[1];
        }

        if (! (bool) config('intake.ai_vision_extract.ai_request_enhance_enabled', true)) {
            return ['path' => $absolutePath, 'cleanup' => null, 'mime' => null, 'meta' => $meta];
        }

        $maxEdge = max(512, (int) config('intake.ai_vision_extract.ai_request_max_edge', 2048));
        $minEdgeUpscale = max(400, (int) config('intake.ai_vision_extract.ai_request_min_edge_to_upscale', 1280));

        if (class_exists(\Imagick::class)) {
            try {
                $im = new \Imagick($absolutePath);
                $ow = $im->getImageWidth();
                $oh = $im->getImageHeight();
                $im->autoOrient();
                $w = $im->getImageWidth();
                $h = $im->getImageHeight();
                if ($w !== $ow || $h !== $oh) {
                    $meta['ai_request_orientation_corrected'] = true;
                }
                $long = max($w, $h);
                if ($long > 0 && $long < $minEdgeUpscale) {
                    $scale = min(3.0, $maxEdge / $long);
                    $nw = max(1, (int) round($w * $scale));
                    $nh = max(1, (int) round($h * $scale));
                    $im->resizeImage($nw, $nh, \Imagick::FILTER_LANCZOS, 1);
                    $meta['ai_request_payload_enhanced'] = true;
                }
                $meta['ai_request_image_width'] = $im->getImageWidth();
                $meta['ai_request_image_height'] = $im->getImageHeight();

                if (! $meta['ai_request_payload_enhanced'] && ! $meta['ai_request_orientation_corrected']) {
                    $im->destroy();

                    return ['path' => $absolutePath, 'cleanup' => null, 'mime' => null, 'meta' => $meta];
                }

                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(92);
                $tmpDir = storage_path('app/private/tmp/aivision_ai_payload');
                if (! is_dir($tmpDir)) {
                    @mkdir($tmpDir, 0777, true);
                }
                $tmp = $tmpDir.'/'.uniqid('ai_req_', true).'.jpg';
                $im->writeImage($tmp);
                $im->destroy();

                return ['path' => $tmp, 'cleanup' => $tmp, 'mime' => 'image/jpeg', 'meta' => $meta];
            } catch (\Throwable $e) {
                Log::debug('AiVisionExtractionService: Imagick payload prep failed', ['error' => $e->getMessage()]);
            }
        }

        if (extension_loaded('gd') && is_array($sz) && $sz[0] > 0 && $sz[1] > 0) {
            $img = $this->gdCreateFromFile($absolutePath, strtolower($ext));
            if (is_resource($img) || (is_object($img) && $img instanceof \GdImage)) {
                $w = imagesx($img);
                $h = imagesy($img);
                $this->gdApplyExifOrientation($absolutePath, $img);
                $w = imagesx($img);
                $h = imagesy($img);
                $long = max($w, $h);
                if ($long > 0 && $long < $minEdgeUpscale) {
                    $scale = min(3.0, $maxEdge / $long);
                    $nw = max(1, (int) round($w * $scale));
                    $nh = max(1, (int) round($h * $scale));
                    $resized = imagecreatetruecolor($nw, $nh);
                    if ($resized !== false) {
                        imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                        imagedestroy($img);
                        $img = $resized;
                        $meta['ai_request_payload_enhanced'] = true;
                    }
                }
                $meta['ai_request_image_width'] = imagesx($img);
                $meta['ai_request_image_height'] = imagesy($img);
                $tmpDir = storage_path('app/private/tmp/aivision_ai_payload');
                if (! is_dir($tmpDir)) {
                    @mkdir($tmpDir, 0777, true);
                }
                $tmp = $tmpDir.'/'.uniqid('ai_req_gd_', true).'.jpg';
                imagejpeg($img, $tmp, 92);
                imagedestroy($img);

                return ['path' => $tmp, 'cleanup' => $tmp, 'mime' => 'image/jpeg', 'meta' => $meta];
            }
        }

        return ['path' => $absolutePath, 'cleanup' => null, 'mime' => null, 'meta' => $meta];
    }

    /**
     * @return resource|\GdImage|false
     */
    private function gdCreateFromFile(string $path, string $ext)
    {
        return match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($path),
            'png' => @imagecreatefrompng($path),
            'gif' => @imagecreatefromgif($path),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'bmp' => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : false,
            default => false,
        };
    }

    /**
     * @param  resource|\GdImage  $img
     */
    private function gdApplyExifOrientation(string $path, &$img): void
    {
        if (! function_exists('exif_read_data')) {
            return;
        }
        $pl = strtolower($path);
        if (! str_ends_with($pl, '.jpg') && ! str_ends_with($pl, '.jpeg')) {
            return;
        }
        $exif = @exif_read_data($path);
        if (! is_array($exif) || empty($exif['Orientation'])) {
            return;
        }
        $o = (int) $exif['Orientation'];
        $rot = match ($o) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => null,
        };
        if ($rot === null) {
            return;
        }
        $rotated = imagerotate($img, $rot, 0);
        if ($rotated !== false) {
            imagedestroy($img);
            $img = $rotated;
        }
    }

    /**
     * @param  array{source_field: string, relative_path: string, absolute_path: string}  $sourceMeta
     * @return array{text: string, meta: array<string, mixed>}
     */
    private function extractViaSarvamDocumentIntelligence(string $absolutePath, string $originalName, array $sourceMeta): array
    {
        try {
            $key = (string) config('services.sarvam.subscription_key');
            $baseUrl = rtrim((string) config('services.sarvam.base_url', 'https://api.sarvam.ai'), '/');
            if ($key === '') {
                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, [
                        'ok' => false,
                        'reason' => 'sarvam_key_missing',
                        'provider' => 'sarvam',
                    ]),
                ];
            }

            // Sarvam flow packages non-PDF inputs as ZIP and reads API output as ZIP; requires ext-zip.
            if (! class_exists('ZipArchive', false)) {
                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, [
                        'ok' => false,
                        'reason' => 'sarvam_zip_extension_missing',
                        'provider' => 'sarvam',
                    ]),
                ];
            }

            $lang = (string) config('intake.ai_vision_extract.sarvam_language', 'mr-IN');
            $format = (string) config('intake.ai_vision_extract.sarvam_output_format', 'md');
            if (! in_array($format, ['md', 'html', 'json'], true)) {
                $format = 'md';
            }

            $pollSeconds = (int) config('intake.ai_vision_extract.sarvam_poll_seconds', 25);
            $pollSeconds = max(5, min($pollSeconds, 60));

            // 1) create job
            $jobResp = Http::withHeaders([
                'api-subscription-key' => $key,
                'Content-Type' => 'application/json',
            ])->timeout(20)->post($baseUrl.'/doc-digitization/job/v1', [
                'job_parameters' => [
                    'language' => $lang,
                    'output_format' => $format,
                ],
            ]);

            if (! $jobResp->successful()) {
                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, [
                        'ok' => false,
                        'reason' => 'sarvam_initialise_non_2xx',
                        'status' => $jobResp->status(),
                        'response_body_snippet' => self::httpBodySnippet($jobResp->body()),
                        'provider' => 'sarvam',
                    ]),
                ];
            }

            $jobBody = $jobResp->json();
            $jobId = (string) ($jobBody['job_id'] ?? '');
            if ($jobId === '') {
                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, [
                        'ok' => false,
                        'reason' => 'sarvam_initialise_missing_job_id',
                        'provider' => 'sarvam',
                    ]),
                ];
            }

            // 2) get upload link (exactly 1 file, PDF or ZIP; for a single image we can upload a ZIP)
            $filename = $this->safeSarvamFilename($originalName);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $uploadPath = $absolutePath;
            $cleanupZip = null;
            $cleanupPreparedPayload = null;
            $payloadPrepMeta = [];
            if (! in_array($ext, ['pdf', 'zip'], true)) {
                $prepared = $this->prepareRasterForAiPayload($absolutePath, $ext);
                $payloadPrepMeta = $prepared['meta'] ?? [];
                $cleanupPreparedPayload = $prepared['cleanup'] ?? null;
                $pathForZip = $prepared['path'] ?? $absolutePath;
                // Wrap single image into a ZIP (Sarvam expects PDF or ZIP).
                $zipOut = storage_path('app/private/tmp/sarvam/'.$jobId);
                if (! is_dir($zipOut)) {
                    @mkdir($zipOut, 0777, true);
                }
                $zipFile = $zipOut.'/input.zip';
                $z = new ZipArchive;
                if ($z->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    if ($cleanupPreparedPayload) {
                        @unlink($cleanupPreparedPayload);
                    }

                    return [
                        'text' => '',
                        'meta' => array_merge($sourceMeta, [
                            'ok' => false,
                            'reason' => 'sarvam_zip_create_failed',
                            'provider' => 'sarvam',
                            'job_id' => $jobId,
                        ]),
                    ];
                }
                $innerExt = strtolower((string) pathinfo($pathForZip, PATHINFO_EXTENSION));
                $imgName = 'page1.'.($innerExt !== '' ? $innerExt : 'png');
                $z->addFile($pathForZip, $imgName);
                $z->close();

                $uploadPath = $zipFile;
                $filename = 'input.zip';
                $cleanupZip = $zipFile;
            }

            $uplResp = Http::withHeaders([
                'api-subscription-key' => $key,
                'Content-Type' => 'application/json',
            ])->timeout(20)->post($baseUrl.'/doc-digitization/job/v1/upload-files', [
                'job_id' => $jobId,
                'files' => [$filename],
            ]);

            if (! $uplResp->successful()) {
                if ($cleanupZip) {
                    @unlink($cleanupZip);
                }
                if (! empty($cleanupPreparedPayload)) {
                    @unlink($cleanupPreparedPayload);
                }
                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, [
                        'ok' => false,
                        'reason' => 'sarvam_get_upload_links_non_2xx',
                        'status' => $uplResp->status(),
                        'response_body_snippet' => self::httpBodySnippet($uplResp->body()),
                        'provider' => 'sarvam',
                        'job_id' => $jobId,
                    ]),
                ];
            }

            $uplBody = $uplResp->json();
            $uploadUrl = $uplBody['upload_urls'][$filename]['file_url'] ?? null;
            if (! is_string($uploadUrl) || $uploadUrl === '') {
                if ($cleanupZip) {
                    @unlink($cleanupZip);
                }
                if (! empty($cleanupPreparedPayload)) {
                    @unlink($cleanupPreparedPayload);
                }
                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, [
                        'ok' => false,
                        'reason' => 'sarvam_missing_upload_url',
                        'provider' => 'sarvam',
                        'job_id' => $jobId,
                    ]),
                ];
            }

            // 3) PUT bytes to presigned URL
            $bytes = @file_get_contents($uploadPath);
            if (! is_string($bytes) || $bytes === '') {
                if ($cleanupZip) {
                    @unlink($cleanupZip);
                }
                if (! empty($cleanupPreparedPayload)) {
                    @unlink($cleanupPreparedPayload);
                }
                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, [
                        'ok' => false,
                        'reason' => 'sarvam_read_upload_file_failed',
                        'provider' => 'sarvam',
                        'job_id' => $jobId,
                    ]),
                ];
            }

            // Azure Blob Storage Put Blob requires this header for block uploads (presigned URLs omit it from signature scope).
            $put = Http::withHeaders([
                'x-ms-blob-type' => 'BlockBlob',
            ])->withBody($bytes, 'application/octet-stream')->timeout(60)->put($uploadUrl);
            if (! $put->successful()) {
                if ($cleanupZip) {
                    @unlink($cleanupZip);
                }
                if (! empty($cleanupPreparedPayload)) {
                    @unlink($cleanupPreparedPayload);
                }
                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, [
                        'ok' => false,
                        'reason' => 'sarvam_upload_put_failed',
                        'status' => $put->status(),
                        'response_body_snippet' => self::httpBodySnippet($put->body()),
                        'provider' => 'sarvam',
                        'job_id' => $jobId,
                    ]),
                ];
            }

            if ($cleanupZip) {
                @unlink($cleanupZip);
            }
            if (! empty($cleanupPreparedPayload)) {
                @unlink($cleanupPreparedPayload);
            }

            // 4) start job
            $startResp = Http::withHeaders([
                'api-subscription-key' => $key,
                'Content-Type' => 'application/json',
            ])->timeout(20)->post($baseUrl.'/doc-digitization/job/v1/'.$jobId.'/start', []);

            if (! $startResp->successful()) {
                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, [
                        'ok' => false,
                        'reason' => 'sarvam_start_non_2xx',
                        'status' => $startResp->status(),
                        'response_body_snippet' => self::httpBodySnippet($startResp->body()),
                        'provider' => 'sarvam',
                        'job_id' => $jobId,
                    ]),
                ];
            }

            // 5) poll status
            $deadline = microtime(true) + $pollSeconds;
            $state = null;
            $lastStatus = null;
            do {
                usleep(900000); // 0.9s
                $st = Http::withHeaders([
                    'api-subscription-key' => $key,
                ])->timeout(20)->get($baseUrl.'/doc-digitization/job/v1/'.$jobId.'/status');
                $lastStatus = $st->json();
                $state = is_array($lastStatus) ? ($lastStatus['job_state'] ?? null) : null;
                if (in_array($state, ['Completed', 'PartiallyCompleted', 'Failed'], true)) {
                    break;
                }
            } while (microtime(true) < $deadline);

            if (! in_array($state, ['Completed', 'PartiallyCompleted'], true)) {
                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, [
                        'ok' => false,
                        'reason' => $state === 'Failed' ? 'sarvam_job_failed' : 'sarvam_job_timeout',
                        'provider' => 'sarvam',
                        'job_id' => $jobId,
                        'job_state' => $state,
                        'job_error_message' => is_array($lastStatus) ? ($lastStatus['error_message'] ?? null) : null,
                    ]),
                ];
            }

            // 6) download links
            $dl = Http::withHeaders([
                'api-subscription-key' => $key,
                'Content-Type' => 'application/json',
            ])->timeout(20)->post($baseUrl.'/doc-digitization/job/v1/'.$jobId.'/download-files', []);

            if (! $dl->successful()) {
                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, [
                        'ok' => false,
                        'reason' => 'sarvam_download_links_non_2xx',
                        'status' => $dl->status(),
                        'response_body_snippet' => self::httpBodySnippet($dl->body()),
                        'provider' => 'sarvam',
                        'job_id' => $jobId,
                        'job_state' => $state,
                    ]),
                ];
            }

            $dlBody = $dl->json();
            $urls = is_array($dlBody['download_urls'] ?? null) ? $dlBody['download_urls'] : [];
            $first = null;
            foreach ($urls as $name => $details) {
                $u = is_array($details) ? ($details['file_url'] ?? null) : null;
                if (is_string($u) && $u !== '') {
                    $first = ['name' => (string) $name, 'url' => $u];
                    break;
                }
            }
            if ($first === null) {
                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, [
                        'ok' => false,
                        'reason' => 'sarvam_no_download_urls',
                        'provider' => 'sarvam',
                        'job_id' => $jobId,
                        'job_state' => $state,
                    ]),
                ];
            }

            // 7) download ZIP output and extract the first md/html/json file content
            $zipBytes = Http::timeout(60)->get($first['url'])->body();
            if (! is_string($zipBytes) || $zipBytes === '') {
                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, [
                        'ok' => false,
                        'reason' => 'sarvam_download_zip_failed',
                        'provider' => 'sarvam',
                        'job_id' => $jobId,
                        'job_state' => $state,
                    ]),
                ];
            }

            $tmpDir = storage_path('app/private/tmp/sarvam/'.$jobId);
            if (! is_dir($tmpDir)) {
                @mkdir($tmpDir, 0777, true);
            }
            $zipPath = $tmpDir.'/out.zip';
            @file_put_contents($zipPath, $zipBytes);

            $z = new ZipArchive;
            if ($z->open($zipPath) !== true) {
                @unlink($zipPath);
                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, [
                        'ok' => false,
                        'reason' => 'sarvam_zip_open_failed',
                        'provider' => 'sarvam',
                        'job_id' => $jobId,
                        'job_state' => $state,
                    ]),
                ];
            }

            $text = '';
            for ($i = 0; $i < $z->numFiles; $i++) {
                $name = (string) $z->getNameIndex($i);
                if ($name === '') {
                    continue;
                }
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (! in_array($ext, [$format], true)) {
                    // If Sarvam returns multiple formats, accept md as best-effort.
                    if ($format !== 'md' && $ext !== 'md') {
                        continue;
                    }
                }
                $content = $z->getFromIndex($i);
                if (is_string($content) && trim($content) !== '') {
                    $text = trim($content);
                    break;
                }
            }
            $z->close();
            @unlink($zipPath);

            if (trim($text) === '') {
                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, [
                        'ok' => false,
                        'reason' => 'sarvam_output_empty',
                        'provider' => 'sarvam',
                        'job_id' => $jobId,
                        'job_state' => $state,
                    ]),
                ];
            }

            return [
                'text' => $text,
                'meta' => array_merge($sourceMeta, $payloadPrepMeta, [
                    'ok' => true,
                    'reason' => null,
                    'provider' => 'sarvam',
                    'extraction' => 'sarvam_document_intelligence',
                    'job_id' => $jobId,
                    'job_state' => $state,
                    'language' => $lang,
                    'output_format' => $format,
                ]),
            ];
        } catch (\Throwable $e) {
            return [
                'text' => '',
                'meta' => array_merge($sourceMeta, [
                    'ok' => false,
                    'reason' => 'sarvam_request_failed',
                    'error' => $e->getMessage(),
                    'provider' => 'sarvam',
                ]),
            ];
        }
    }

    private function safeSarvamFilename(string $originalName): string
    {
        $name = trim($originalName) !== '' ? trim($originalName) : 'document.pdf';
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'document.pdf';
        if (mb_strlen($name) > 80) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = 'document.'.($ext !== '' ? $ext : 'pdf');
        }
        return $name;
    }

    /**
     * @param  array{source_field: string, relative_path: string, absolute_path: string}  $sourceMeta
     * @return array{text: string, meta: array<string, mixed>}
     */
    private function extractViaOpenAiVision(string $absolutePath, string $originalName, array $sourceMeta): array
    {
        $key = (string) config('services.openai.key');
        if ($key === '') {
            return [
                'text' => '',
                'meta' => array_merge($sourceMeta, [
                    'ok' => false,
                    'reason' => 'openai_key_missing',
                    'provider' => 'openai',
                ]),
            ];
        }

        $ext = strtolower(pathinfo($originalName ?: $absolutePath, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            // Keep existing local PDF text extraction.
            $pdfText = $this->extractTextFromPdf($absolutePath);
            if (trim($pdfText) !== '') {
                return [
                    'text' => $pdfText,
                    'meta' => array_merge($sourceMeta, [
                        'ok' => true,
                        'reason' => null,
                        'provider' => 'pdfparser',
                        'extraction' => 'pdf_text_extract_local',
                    ]),
                ];
            }

            return [
                'text' => '',
                'meta' => array_merge($sourceMeta, [
                    'ok' => false,
                    'reason' => 'openai_pdf_scanned_unsupported_in_fallback',
                    'provider' => 'openai',
                ]),
            ];
        }

        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'], true)) {
            return [
                'text' => '',
                'meta' => array_merge($sourceMeta, [
                    'ok' => false,
                    'reason' => 'unsupported_file_extension',
                    'provider' => 'openai',
                    'ext' => $ext,
                ]),
            ];
        }

        $prepared = $this->prepareRasterForAiPayload($absolutePath, $ext);
        $payloadPath = $prepared['path'];
        $cleanupPayload = $prepared['cleanup'] ?? null;
        $prepMeta = $prepared['meta'] ?? [];
        $payloadMime = $prepared['mime'] ?? null;

        $mime = $payloadMime ?? $this->mimeForExt($ext);
        $b64 = base64_encode((string) file_get_contents($payloadPath));
        $dataUrl = 'data:'.$mime.';base64,'.$b64;

        $url = (string) config('services.openai.url', 'https://api.openai.com/v1/chat/completions');
        $model = (string) config('intake.ai_vision_extract.model', '');
        if ($model === '') {
            $model = (string) config('services.openai.model', 'gpt-4o-mini');
        }

        $detail = (string) config('intake.ai_vision_extract.vision_detail', 'high');
        if (! in_array($detail, ['auto', 'low', 'high'], true)) {
            $detail = 'high';
        }

        $prompt = self::openAiVisionTranscriptionPrompt();
        $system = $prompt['system'];
        $user = $prompt['user'];

        $imageUrlPayload = ['url' => $dataUrl, 'detail' => $detail];
        $userContent = [
            ['type' => 'text', 'text' => $user],
            ['type' => 'image_url', 'image_url' => $imageUrlPayload],
        ];

        $maxTokens = max(500, (int) config('intake.ai_vision_extract.vision_max_tokens', 4096));

        try {
            $resp = Http::withHeaders([
                'Authorization' => 'Bearer '.$key,
                'Content-Type' => 'application/json',
            ])->timeout(90)->post($url, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $userContent],
                ],
                'temperature' => 0.0,
                'max_tokens' => $maxTokens,
            ]);

            if ($cleanupPayload) {
                @unlink($cleanupPayload);
            }

            if (! $resp->successful()) {
                Log::warning('AiVisionExtractionService: non-2xx from OpenAI', ['status' => $resp->status()]);

                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, $prepMeta, [
                        'ok' => false,
                        'reason' => 'openai_non_2xx',
                        'status' => $resp->status(),
                        'response_body_snippet' => self::httpBodySnippet($resp->body()),
                        'provider' => 'openai',
                        'model' => $model,
                        'vision_detail' => $detail,
                    ]),
                ];
            }

            $body = $resp->json();
            $content = $body['choices'][0]['message']['content'] ?? null;
            if (! is_string($content) || trim($content) === '') {
                return [
                    'text' => '',
                    'meta' => array_merge($sourceMeta, $prepMeta, [
                        'ok' => false,
                        'reason' => 'openai_empty_content',
                        'provider' => 'openai',
                        'model' => $model,
                        'vision_detail' => $detail,
                    ]),
                ];
            }

            $text = $this->sanitizeTranscriptionResponse($content);
            $lines = max(1, substr_count($text, "\n") + 1);

            return [
                'text' => $text,
                'meta' => array_merge($sourceMeta, $prepMeta, [
                    'ok' => true,
                    'reason' => null,
                    'provider' => 'openai',
                    'model' => $model,
                    'extraction' => 'ai_vision_transcribe',
                    'vision_detail' => $detail,
                    'extracted_text_line_count' => $lines,
                ]),
            ];
        } catch (\Throwable $e) {
            if (! empty($cleanupPayload)) {
                @unlink($cleanupPayload);
            }
            Log::warning('AiVisionExtractionService: request failed', ['error' => $e->getMessage()]);

            return [
                'text' => '',
                'meta' => array_merge($sourceMeta, $prepMeta, [
                    'ok' => false,
                    'reason' => 'openai_request_failed',
                    'error' => $e->getMessage(),
                    'provider' => 'openai',
                    'model' => $model,
                ]),
            ];
        }
    }

    private function mimeForExt(string $ext): string
    {
        return match (strtolower($ext)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            default => 'application/octet-stream',
        };
    }

    private function extractTextFromPdf(string $absolutePath): string
    {
        try {
            $parser = new PdfParser;
            $pdf = $parser->parseFile($absolutePath);
            $text = $pdf->getText();
            return is_string($text) ? trim($text) : '';
        } catch (\Throwable) {
            return '';
        }
    }
}

