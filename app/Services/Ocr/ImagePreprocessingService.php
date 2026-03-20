<?php

namespace App\Services\Ocr;

use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickException;
use Throwable;

/**
 * SSOT: derived OCR-only temp images; never overwrites the upload.
 * If preprocessing cannot run, callers must fall back to the original path.
 */
class ImagePreprocessingService
{
    /** @var list<string> */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /** @var bool|null Cached Imagick probe (same for all extensions). */
    private static ?bool $imagickReadyCache = null;

    /** @var array<string, mixed>|null Cached diagnostics from last Imagick probe. */
    private static ?array $imagickDiagCache = null;

    /** @var bool|null Cached GD core probe (create + png write). */
    private static ?bool $gdCoreReadyCache = null;

    /** @var array<string, mixed>|null Cached GD static diagnostics. */
    private static ?array $gdStaticDiagCache = null;

    /**
     * Reset cached driver probes (for automated tests only).
     */
    public static function resetDriverProbesForTests(): void
    {
        self::$imagickReadyCache = null;
        self::$imagickDiagCache = null;
        self::$gdCoreReadyCache = null;
        self::$gdStaticDiagCache = null;
    }

    /**
     * Runtime capability summary for APP_DEBUG UI / ops (no OCR text).
     *
     * @return array{
     *     resolved_driver: 'imagick'|'gd'|'none',
     *     skipped_reason_if_none: string|null,
     *     imagick_available: bool,
     *     gd_available: bool,
     *     diagnostics: array<string, mixed>
     * }
     */
    public function getDriverCapabilityReport(): array
    {
        $r = $this->resolveAvailableDriver(null);
        if (config('app.debug')) {
            Log::info('ocr_preprocessing: capability_report', [
                'resolved_driver' => $r['driver'],
                'skipped_reason_if_none' => $r['skipped_reason_if_none'],
                'diagnostics' => $r['diagnostics'],
            ]);
        }

        $d = $r['diagnostics'];
        $imagickAvailable = ($d['imagick_ping_ok'] ?? null) === true;
        $gdAvailable = ($d['gd_probe_write_ok'] ?? null) === true;

        return [
            'resolved_driver' => $r['driver'],
            'skipped_reason_if_none' => $r['skipped_reason_if_none'],
            'imagick_available' => $imagickAvailable,
            'gd_available' => $gdAvailable,
            'diagnostics' => $r['diagnostics'],
        ];
    }

    /**
     * Pick imagick > gd > none with real probes (not extension_loaded alone).
     *
     * @return array{
     *     driver: 'imagick'|'gd'|'none',
     *     skipped_reason_if_none: string|null,
     *     diagnostics: array<string, mixed>
     * }
     */
    private function resolveAvailableDriver(?string $sourceExtensionForGd): array
    {
        $diag = $this->buildImagickDiagnostics();
        $imagickOk = $this->probeImagickUsable($diag);

        if ($imagickOk) {
            $gdDiagEarly = $this->buildGdStaticDiagnostics();
            $diag = array_merge($diag, $gdDiagEarly);
            $this->probeGdCoreUsable($diag);
            $diag['gd_format_supported_for_source'] = null;

            return [
                'driver' => 'imagick',
                'skipped_reason_if_none' => null,
                'diagnostics' => $diag,
            ];
        }

        $gdDiag = $this->buildGdStaticDiagnostics();
        $diag = array_merge($diag, $gdDiag);
        $gdCoreOk = $this->probeGdCoreUsable($diag);

        $ext = $sourceExtensionForGd !== null ? strtolower($sourceExtensionForGd) : null;
        $gdFormatOk = $ext === null ? true : $this->gdCanDecodeExtension($ext);
        $diag['gd_format_supported_for_source'] = $ext === null ? null : $gdFormatOk;

        if ($gdCoreOk && $gdFormatOk) {
            return [
                'driver' => 'gd',
                'skipped_reason_if_none' => null,
                'diagnostics' => $diag,
            ];
        }

        $reason = $this->resolveNoneReason($diag, $gdCoreOk, $gdFormatOk, $ext);

        if (config('app.debug')) {
            Log::warning('ocr_preprocessing: no_usable_image_driver', [
                'skipped_reason' => $reason,
                'source_extension' => $ext,
                'diagnostics' => $diag,
            ]);
        }

        return [
            'driver' => 'none',
            'skipped_reason_if_none' => $reason,
            'diagnostics' => $diag,
        ];
    }

    /**
     * @param  array<string, mixed>  $diag
     */
    private function resolveNoneReason(array $diag, bool $gdCoreOk, bool $gdFormatOk, ?string $ext): string
    {
        if ($gdCoreOk && $ext !== null && ! $gdFormatOk) {
            return 'gd_unsupported_format_'.$ext;
        }
        if (! $diag['imagick_extension_loaded'] && ! $diag['gd_extension_loaded']) {
            return 'no_supported_image_driver';
        }
        if ($diag['imagick_extension_loaded'] && $diag['imagick_ping_ok'] === false && ! $diag['gd_extension_loaded']) {
            return 'imagick_unavailable';
        }
        if (! $diag['imagick_extension_loaded'] && $diag['gd_extension_loaded'] && ! $gdCoreOk) {
            return 'gd_unavailable';
        }
        if ($diag['imagick_extension_loaded'] && $diag['imagick_ping_ok'] === false && $diag['gd_extension_loaded'] && ! $gdCoreOk) {
            return 'no_supported_image_driver';
        }
        if ($diag['imagick_extension_loaded'] && $diag['imagick_ping_ok'] === false && $gdCoreOk && $ext !== null && ! $gdFormatOk) {
            return 'gd_unsupported_format_'.$ext;
        }

        return 'no_supported_image_driver';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildImagickDiagnostics(): array
    {
        return [
            'imagick_extension_loaded' => extension_loaded('imagick'),
            'imagick_class_exists' => class_exists(Imagick::class, false),
            'imagick_ping_ok' => null,
            'imagick_ping_error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $diag
     */
    private function probeImagickUsable(array &$diag): bool
    {
        if (self::$imagickReadyCache !== null) {
            $diag['imagick_ping_ok'] = self::$imagickDiagCache['imagick_ping_ok'] ?? false;
            $diag['imagick_ping_error'] = self::$imagickDiagCache['imagick_ping_error'] ?? null;

            return self::$imagickReadyCache;
        }

        $imagickOk = false;
        if ($diag['imagick_extension_loaded'] && $diag['imagick_class_exists']) {
            try {
                $probe = new Imagick;
                $probe->newImage(2, 2, new \ImagickPixel('white'));
                $probe->setImageFormat('png');
                $blob = $probe->getImageBlob();
                $probe->clear();
                $probe->destroy();
                $imagickOk = is_string($blob) && strlen($blob) > 8;
                $diag['imagick_ping_ok'] = $imagickOk;
                if (! $imagickOk) {
                    $diag['imagick_ping_error'] = 'empty_or_short_png_blob';
                }
            } catch (Throwable $e) {
                $diag['imagick_ping_ok'] = false;
                $diag['imagick_ping_error'] = $e->getMessage();
            }
        } else {
            $diag['imagick_ping_ok'] = false;
            $diag['imagick_ping_error'] = ! $diag['imagick_extension_loaded']
                ? 'imagick_extension_not_loaded'
                : 'imagick_class_missing';
        }

        self::$imagickReadyCache = $imagickOk;
        self::$imagickDiagCache = [
            'imagick_ping_ok' => $diag['imagick_ping_ok'],
            'imagick_ping_error' => $diag['imagick_ping_error'],
        ];

        return $imagickOk;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGdStaticDiagnostics(): array
    {
        if (self::$gdStaticDiagCache !== null) {
            return self::$gdStaticDiagCache;
        }

        self::$gdStaticDiagCache = [
            'gd_extension_loaded' => extension_loaded('gd'),
            'gd_imagecreatetruecolor' => function_exists('imagecreatetruecolor'),
            'gd_imagepng' => function_exists('imagepng'),
            'gd_imagedestroy' => function_exists('imagedestroy'),
            'gd_imagescale' => function_exists('imagescale'),
            'gd_imagefilter_grayscale' => defined('IMG_FILTER_GRAYSCALE'),
            'gd_imageconvolution' => function_exists('imageconvolution'),
        ];

        return self::$gdStaticDiagCache;
    }

    /**
     * @param  array<string, mixed>  $diag
     */
    private function probeGdCoreUsable(array &$diag): bool
    {
        if (self::$gdCoreReadyCache !== null) {
            $diag['gd_probe_write_ok'] = self::$gdCoreReadyCache;
            $diag['gd_probe_error'] = $diag['gd_probe_error'] ?? null;

            return self::$gdCoreReadyCache;
        }

        $gdCoreOk = false;
        $diag['gd_probe_write_ok'] = null;
        $diag['gd_probe_error'] = null;

        if ($diag['gd_extension_loaded']
            && $diag['gd_imagecreatetruecolor']
            && $diag['gd_imagepng']
            && $diag['gd_imagedestroy']) {
            $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ocr_gd_probe_'.uniqid('', true).'.png';
            try {
                $g = @imagecreatetruecolor(2, 2);
                if ($g instanceof GdImage) {
                    $white = imagecolorallocate($g, 255, 255, 255);
                    imagefill($g, 0, 0, $white);
                    $wrote = @imagepng($g, $tmp, 6);
                    imagedestroy($g);
                    $gdCoreOk = $wrote && is_file($tmp) && filesize($tmp) > 8;
                    if (is_file($tmp)) {
                        @unlink($tmp);
                    }
                }
                $diag['gd_probe_write_ok'] = $gdCoreOk;
                if (! $gdCoreOk) {
                    $diag['gd_probe_error'] = 'imagecreatetruecolor_or_imagepng_failed';
                }
            } catch (Throwable $e) {
                $diag['gd_probe_write_ok'] = false;
                $diag['gd_probe_error'] = $e->getMessage();
            }
        } else {
            $diag['gd_probe_write_ok'] = false;
            $diag['gd_probe_error'] = ! $diag['gd_extension_loaded']
                ? 'gd_extension_not_loaded'
                : 'required_gd_functions_missing';
        }

        self::$gdCoreReadyCache = $gdCoreOk;

        return $gdCoreOk;
    }

    /**
     * Decode support for GD path only (Imagick handles more formats).
     */
    private function gdCanDecodeExtension(string $ext): bool
    {
        return match ($ext) {
            'jpg', 'jpeg' => function_exists('imagecreatefromjpeg'),
            'png' => function_exists('imagecreatefrompng'),
            'gif' => function_exists('imagecreatefromgif'),
            'webp' => function_exists('imagecreatefromwebp'),
            'bmp' => function_exists('imagecreatefrombmp'),
            default => false,
        };
    }

    public function shouldPreprocess(string $path, ?string $originalName = null): bool
    {
        if (! (bool) config('ocr.preprocessing.enabled', true)) {
            return false;
        }

        $ext = strtolower(pathinfo($originalName ?? $path, PATHINFO_EXTENSION));

        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

    public function resolvePreset(string $path, ?string $originalName = null, ?string $presetOverride = null): string
    {
        $defined = array_keys(config('ocr.preprocessing.presets', []));
        $candidate = $presetOverride ?: config('ocr.preprocessing.preset_override');
        if (is_string($candidate) && $candidate !== '' && in_array($candidate, $defined, true)) {
            return $candidate;
        }

        $ext = strtolower(pathinfo($originalName ?? $path, PATHINFO_EXTENSION));
        $map = config('ocr.preprocessing.extension_presets', []);
        if ($ext === 'pdf' && isset($map['pdf']) && in_array($map['pdf'], $defined, true)) {
            return $map['pdf'];
        }
        if (isset($map[$ext]) && is_string($map[$ext]) && in_array($map[$ext], $defined, true)) {
            return $map[$ext];
        }

        $fallback = config('ocr.preprocessing.default_preset', 'marathi_printed');

        return in_array($fallback, $defined, true) ? $fallback : 'marathi_printed';
    }

    /**
     * @return array{
     *     used: bool,
     *     preset: string|null,
     *     output_path: string|null,
     *     output_absolute_path: string|null,
     *     source_path: string,
     *     fallback_used: bool,
     *     meta: array{
     *         driver: 'imagick'|'gd'|'none',
     *         steps: list<string>,
     *         width: int|null,
     *         height: int|null,
     *     }
     * }
     */
    public function preprocessForOcr(
        string $absoluteSourcePath,
        string $relativeStoredPath,
        ?string $originalName = null,
        ?string $presetOverride = null
    ): array {
        $emptyMeta = [
            'driver' => 'none',
            'steps' => [],
            'width' => null,
            'height' => null,
        ];

        if (! $this->shouldPreprocess($relativeStoredPath, $originalName ?? $relativeStoredPath)) {
            return [
                'used' => false,
                'preset' => null,
                'output_path' => null,
                'output_absolute_path' => null,
                'source_path' => $absoluteSourcePath,
                'fallback_used' => false,
                'meta' => array_replace($emptyMeta, [
                    'skipped_reason' => 'preprocessing_not_applicable',
                    'applied_steps' => [],
                ]),
            ];
        }

        if (! is_file($absoluteSourcePath) || ! is_readable($absoluteSourcePath)) {
            return [
                'used' => false,
                'preset' => null,
                'output_path' => null,
                'output_absolute_path' => null,
                'source_path' => $absoluteSourcePath,
                'fallback_used' => true,
                'meta' => array_replace($emptyMeta, [
                    'skipped_reason' => 'unreadable_source',
                    'applied_steps' => [],
                ]),
            ];
        }

        [$ow, $oh] = $this->probeImageDimensions($absoluteSourcePath);

        $srcExt = strtolower(pathinfo($originalName ?? $relativeStoredPath, PATHINFO_EXTENSION));

        $presetName = $this->resolvePreset($relativeStoredPath, $originalName, $presetOverride);
        $preset = config("ocr.preprocessing.presets.{$presetName}", []);
        if ($preset === [] || ! is_array($preset)) {
            Log::warning('ocr_preprocessing: unknown preset, skipping', ['preset' => $presetName]);
            $resolution = $this->resolveAvailableDriver($srcExt);

            return [
                'used' => false,
                'preset' => $presetName,
                'output_path' => null,
                'output_absolute_path' => null,
                'source_path' => $absoluteSourcePath,
                'fallback_used' => false,
                'meta' => $this->finalizeMeta(array_replace($emptyMeta, [
                    'driver' => $resolution['driver'],
                    'skipped_reason' => 'unknown_preset',
                    'resolution_diagnostics' => $resolution['diagnostics'],
                ]), $ow, $oh, null, $presetName),
            ];
        }

        $resolution = $this->resolveAvailableDriver($srcExt);
        $driver = $resolution['driver'];

        if ($driver === 'none') {
            $skip = $resolution['skipped_reason_if_none'] ?? 'no_supported_image_driver';

            return [
                'used' => false,
                'preset' => $presetName,
                'output_path' => null,
                'output_absolute_path' => null,
                'source_path' => $absoluteSourcePath,
                'fallback_used' => false,
                'meta' => $this->finalizeMeta(array_replace($emptyMeta, [
                    'skipped_reason' => $skip,
                    'resolution_diagnostics' => $resolution['diagnostics'],
                ]), $ow, $oh, null, $presetName),
            ];
        }

        $disk = (string) config('ocr.preprocessing.temp_disk', 'local');
        $dir = trim((string) config('ocr.preprocessing.temp_dir', 'ocr-preprocessed'), '/');
        $filename = uniqid('ocrprep_', true).'.png';
        $relativeOut = $dir !== '' ? "{$dir}/{$filename}" : $filename;

        try {
            Storage::disk($disk)->makeDirectory($dir);
            $absoluteOut = Storage::disk($disk)->path($relativeOut);
        } catch (Throwable $e) {
            Log::warning('ocr_preprocessing: could not prepare temp path', [
                'error' => $e->getMessage(),
                'preset' => $presetName,
            ]);

            return [
                'used' => false,
                'preset' => $presetName,
                'output_path' => null,
                'output_absolute_path' => null,
                'source_path' => $absoluteSourcePath,
                'fallback_used' => true,
                'meta' => $this->finalizeMeta(array_replace($emptyMeta, [
                    'driver' => $driver,
                    'skipped_reason' => 'write_failed',
                ]), $ow, $oh, null, $presetName),
            ];
        }

        $t0 = microtime(true);
        try {
            if ($driver === 'imagick') {
                $result = $this->processWithImagick($absoluteSourcePath, $absoluteOut, $preset, $presetName);
            } else {
                $result = $this->processWithGd($absoluteSourcePath, $absoluteOut, $preset, $presetName);
            }
        } catch (Throwable $e) {
            Log::warning('ocr_preprocessing: failed, falling back to original', [
                'preset' => $presetName,
                'driver' => $driver,
                'error' => $e->getMessage(),
            ]);
            if (is_file($absoluteOut)) {
                @unlink($absoluteOut);
            }

            return [
                'used' => false,
                'preset' => $presetName,
                'output_path' => null,
                'output_absolute_path' => null,
                'source_path' => $absoluteSourcePath,
                'fallback_used' => true,
                'meta' => $this->finalizeMeta(array_replace($emptyMeta, [
                    'driver' => $driver,
                    'skipped_reason' => 'preprocess_pipeline_failed',
                ]), $ow, $oh, null, $presetName),
            ];
        }

        $durationMs = (int) round((microtime(true) - $t0) * 1000);
        Log::debug('ocr_preprocessing: completed', [
            'preset' => $presetName,
            'driver' => $result['meta']['driver'],
            'duration_ms' => $durationMs,
            'steps' => $result['meta']['steps'],
            'width' => $result['meta']['width'],
            'height' => $result['meta']['height'],
        ]);

        return [
            'used' => true,
            'preset' => $presetName,
            'output_path' => $relativeOut,
            'output_absolute_path' => $absoluteOut,
            'source_path' => $absoluteSourcePath,
            'fallback_used' => false,
            'meta' => $this->finalizeMeta($result['meta'], $ow, $oh, $absoluteOut, $presetName),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function finalizeMeta(array $meta, ?int $originalW, ?int $originalH, ?string $derivedAbsolutePath, ?string $presetName = null): array
    {
        $meta['original_width'] = $originalW;
        $meta['original_height'] = $originalH;
        $meta['output_format'] = 'png';
        $meta['applied_steps'] = $meta['steps'] ?? [];
        // skipped_reason, resolution_diagnostics preserved when already set on $meta
        if ($presetName !== null) {
            $meta['preset_name'] = $presetName;
        }
        if ($derivedAbsolutePath !== null && is_file($derivedAbsolutePath)) {
            $sz = @filesize($derivedAbsolutePath);
            $meta['output_filesize_bytes'] = $sz !== false ? $sz : null;
        } else {
            $meta['output_filesize_bytes'] = null;
        }

        return $meta;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function probeImageDimensions(string $absolutePath): array
    {
        $info = @getimagesize($absolutePath);
        if (! is_array($info)) {
            return [null, null];
        }

        return [
            isset($info[0]) ? (int) $info[0] : null,
            isset($info[1]) ? (int) $info[1] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $preset
     * @return array{meta: array{driver: 'imagick', steps: list<string>, width: int|null, height: int|null}}
     */
    private function processWithImagick(string $source, string $dest, array $preset, string $presetName = ''): array
    {
        $steps = [];
        $width = null;
        $height = null;
        $isPhotoCapture = $presetName === 'photo_capture';
        $image = new Imagick;
        try {
            $image->readImage($source);
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            $image->setBackgroundColor('white');

            if (! empty($preset['orientation_detect'])) {
                try {
                    $image->autoOrient();
                    $steps[] = 'auto_orient';
                } catch (ImagickException) {
                    // skip
                }
            }

            // Deskew before trim: straighten page, then trim outer background (order matters for mobile photos).
            if ($isPhotoCapture) {
                $this->imagickDeskewAndTrimPhotoCapture($image, $preset, $steps);
            } else {
                if (! empty($preset['deskew'])) {
                    try {
                        $q = $image->getQuantumRange();
                        $threshold = (float) (($q['quantumRangeLong'] ?? 65535) * 0.10);
                        $image->deskewImage($threshold);
                        $steps[] = 'deskew';
                    } catch (Throwable) {
                        // skip
                    }
                }

                if (! empty($preset['crop_margins'])) {
                    try {
                        $qr = $image->getQuantumRange();
                        $fuzz = (float) (($qr['quantumRangeLong'] ?? 65535) * 0.02);
                        $image->trimImage($fuzz);
                        $steps[] = 'trim_margins';
                    } catch (Throwable) {
                        // skip
                    }
                }
            }

            if (! empty($preset['normalize_resolution'])) {
                $this->imagickNormalizeResolution($image, $steps, $presetName);
            }

            if (! empty($preset['grayscale'])) {
                $image->setImageType(Imagick::IMGTYPE_GRAYSCALE);
                $steps[] = 'grayscale';
            }

            $denoise = (string) ($preset['denoise'] ?? 'light');
            $this->imagickDenoise($image, $denoise, $steps);

            $contrast = (string) ($preset['contrast_boost'] ?? 'light');
            if ($isPhotoCapture) {
                $contrast = 'light';
            }
            $this->imagickContrast($image, $contrast, $steps);

            if (! empty($preset['photo_unsharp'])) {
                try {
                    if ($isPhotoCapture) {
                        // Milder than generic unsharp — less jagged Devanagari on gray-level output.
                        $image->unsharpMaskImage(0.85, 0.45, 4, 0.05);
                        $steps[] = 'unsharp_photo_capture';
                    } else {
                        $image->unsharpMaskImage(1.0, 0.5, 5, 0.05);
                        $steps[] = 'unsharp_photo';
                    }
                } catch (Throwable) {
                    // skip
                }
            }

            if (! empty($preset['adaptive_threshold'])) {
                try {
                    $div = max(8, (int) ($preset['adaptive_divisor'] ?? 48));
                    $off = (int) ($preset['adaptive_offset'] ?? 8);
                    $tw = max(3, (int) ($image->getImageWidth() / $div));
                    $th = max(3, (int) ($image->getImageHeight() / $div));
                    $image->adaptiveThresholdImage($tw | 1, $th | 1, $off);
                    $steps[] = 'adaptive_threshold_div_'.$div.'_off_'.$off;
                } catch (Throwable) {
                    // skip
                }
            }

            if (! empty($preset['binarize']) && empty($preset['adaptive_threshold'])) {
                try {
                    $qr = $image->getQuantumRange();
                    $image->thresholdImage(0.5 * ($qr['quantumRangeLong'] ?? 65535));
                    $steps[] = 'binarize';
                } catch (Throwable) {
                    // skip
                }
            } elseif (! empty($preset['binarize'])) {
                $steps[] = 'binarize_combined_with_adaptive';
            }

            $image->setImageFormat('png');
            $image->writeImage($dest);
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
        } finally {
            $image->clear();
            $image->destroy();
        }

        if (! is_file($dest) || ! is_readable($dest)) {
            throw new \RuntimeException('Imagick output missing after preprocessing');
        }

        return [
            'meta' => [
                'driver' => 'imagick',
                'steps' => $steps,
                'width' => $width,
                'height' => $height,
            ],
        ];
    }

    /**
     * Mobile document photos: deskew before trim; slightly lower deskew threshold and trim fuzz
     * than generic scans so border text is less likely to be clipped.
     *
     * @param  array<string, mixed>  $preset
     * @param  list<string>  $steps
     */
    private function imagickDeskewAndTrimPhotoCapture(Imagick $image, array $preset, array &$steps): void
    {
        if (! empty($preset['deskew'])) {
            try {
                $q = $image->getQuantumRange();
                $threshold = (float) (($q['quantumRangeLong'] ?? 65535) * 0.08);
                $image->deskewImage($threshold);
                $steps[] = 'deskew_photo_capture';
            } catch (Throwable) {
                // skip
            }
        }

        if (! empty($preset['crop_margins'])) {
            try {
                $qr = $image->getQuantumRange();
                $fuzz = (float) (($qr['quantumRangeLong'] ?? 65535) * 0.012);
                $image->trimImage($fuzz);
                $steps[] = 'trim_margins_photo_capture';
            } catch (Throwable) {
                // skip
            }
        }
    }

    /**
     * @param  list<string>  $steps
     */
    private function imagickNormalizeResolution(Imagick $image, array &$steps, string $presetName = ''): void
    {
        $maxW = max(400, (int) config('ocr.preprocessing.max_upscale_width', 2200));
        $w = (int) $image->getImageWidth();
        $h = (int) $image->getImageHeight();
        if ($w <= 0 || $h <= 0) {
            return;
        }

        if ($w > $maxW) {
            $image->resizeImage($maxW, 0, Imagick::FILTER_LANCZOS, 1);
            $steps[] = 'resize_down';
        } elseif ($presetName === 'photo_capture' && $w < 1200) {
            $targetW = min(1600, $maxW);
            if ($w < $targetW) {
                $nh = (int) max(1, round($h * ($targetW / $w)));
                $image->resizeImage($targetW, $nh, Imagick::FILTER_LANCZOS, 1);
                $steps[] = 'resize_up_photo_stronger';
            }
        } elseif ($presetName !== 'photo_capture' && $w < 720) {
            $target = min(1200, $maxW);
            $scale = $target / $w;
            $nw = (int) round($w * $scale);
            $nh = (int) round($h * $scale);
            if ($nw > $w && $nw <= $maxW) {
                $image->resizeImage($nw, $nh, Imagick::FILTER_LANCZOS, 1);
                $steps[] = 'resize_up_moderate';
            }
        }
    }

    /**
     * @param  list<string>  $steps
     */
    private function imagickDenoise(Imagick $image, string $level, array &$steps): void
    {
        try {
            match ($level) {
                'strong' => $image->gaussianBlurImage(1.6, 1.6),
                'medium' => $image->gaussianBlurImage(1.0, 1.0),
                default => $image->gaussianBlurImage(0.5, 0.5),
            };
            $steps[] = 'denoise_'.$level;
        } catch (Throwable) {
            // skip
        }
    }

    /**
     * @param  list<string>  $steps
     */
    private function imagickContrast(Imagick $image, string $level, array &$steps): void
    {
        try {
            $image->normalizeImage();
            match ($level) {
                'strong' => $image->gammaImage(1.18),
                'medium' => $image->gammaImage(1.08),
                default => null,
            };
            $steps[] = 'contrast_'.$level;
        } catch (Throwable) {
            // skip
        }
    }

    /**
     * @param  array<string, mixed>  $preset
     * @return array{meta: array{driver: 'gd', steps: list<string>, width: int|null, height: int|null}}
     */
    private function processWithGd(string $source, string $dest, array $preset, string $presetName = ''): array
    {
        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if (! $this->gdCanDecodeExtension($ext)) {
            throw new \RuntimeException('gd_unsupported_format_'.$ext);
        }
        $img = $this->gdLoad($source, $ext);
        if ($img === false) {
            throw new \RuntimeException('GD could not load source image');
        }

        $steps = [];
        $isPhotoPreset = $presetName === 'photo_capture';
        try {
            if (! imageistruecolor($img) && function_exists('imagepalettetotruecolor')) {
                imagepalettetotruecolor($img);
            }

            if (! empty($preset['normalize_resolution'])) {
                $img = $this->gdNormalizeResolution($img, $steps, $isPhotoPreset);
            }

            if (! empty($preset['grayscale']) && defined('IMG_FILTER_GRAYSCALE')) {
                imagefilter($img, IMG_FILTER_GRAYSCALE);
                $steps[] = 'grayscale';
            }

            // photo_capture: isolate document first, then mild grayscale cleanup (no default binarization).
            if ($isPhotoPreset) {
                $img = $this->gdEdgeCropToDocumentBounds($img, $steps);
            }

            if ($isPhotoPreset) {
                $this->gdPhotoNormalizeBrightness($img, $steps);
            }

            $denoise = (string) ($preset['denoise'] ?? 'light');
            $this->gdDenoise($img, $denoise, $steps);

            $contrast = (string) ($preset['contrast_boost'] ?? 'light');
            if ($isPhotoPreset) {
                $contrast = 'light';
            }
            $this->gdContrast($img, $contrast, $steps);

            if (! empty($preset['photo_unsharp']) && function_exists('imageconvolution')) {
                if ($isPhotoPreset) {
                    $matrix = [[0.0, -0.5, 0.0], [-0.5, 3.0, -0.5], [0.0, -0.5, 0.0]];
                    if (@imageconvolution($img, $matrix, 1.0, 0.0)) {
                        $steps[] = 'sharpen_photo_mild_gd';
                    }
                } else {
                    $matrix = [[0.0, -1.0, 0.0], [-1.0, 5.0, -1.0], [0.0, -1.0, 0.0]];
                    if (@imageconvolution($img, $matrix, 1.0, 0.0)) {
                        $steps[] = 'sharpen_photo_gd';
                    }
                }
            }

            if (! empty($preset['adaptive_threshold']) || (! empty($preset['binarize']) && empty($preset['adaptive_threshold']))) {
                if (! empty($preset['adaptive_threshold'])) {
                    $gdC = (int) ($preset['gd_threshold_contrast'] ?? -25);
                    $this->gdApproxAdaptiveThreshold($img, $gdC);
                    $steps[] = 'adaptive_threshold_approx_c_'.$gdC;
                } else {
                    $this->gdSimpleBinarize($img);
                    $steps[] = 'binarize';
                }
            }

            if ($isPhotoPreset
                && (bool) config('ocr.preprocessing.photo_capture_gd_threshold_fallback', false)
                && empty($preset['adaptive_threshold'])
                && empty($preset['binarize'])) {
                $this->gdApproxAdaptiveThreshold($img, -15);
                $steps[] = 'photo_threshold_gd_c_-15_fallback';
            }

            // deskew / perspective: not implemented on GD in this pass (Imagick path remains for scans).

            // deskew / orientation / trim skipped on GD (unreliable without Imagick)

            imagesavealpha($img, false);
            if (! imagepng($img, $dest, 6)) {
                throw new \RuntimeException('GD failed to write PNG');
            }

            $width = imagesx($img);
            $height = imagesy($img);
        } finally {
            imagedestroy($img);
        }

        if (! is_file($dest) || ! is_readable($dest)) {
            throw new \RuntimeException('GD output missing after preprocessing');
        }

        return [
            'meta' => [
                'driver' => 'gd',
                'steps' => $steps,
                'width' => $width,
                'height' => $height,
            ],
        ];
    }

    /**
     * @param  list<string>  $steps
     */
    private function gdNormalizeResolution(GdImage $img, array &$steps, bool $isPhotoPreset = false): GdImage
    {
        if (! function_exists('imagescale')) {
            $steps[] = 'resize_skipped_missing_imagescale';

            return $img;
        }

        $maxW = max(400, (int) config('ocr.preprocessing.max_upscale_width', 2200));
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= 0 || $h <= 0) {
            return $img;
        }

        if ($w > $maxW) {
            $nh = (int) max(1, round($h * ($maxW / $w)));
            $scaled = imagescale($img, $maxW, $nh, IMG_BILINEAR_FIXED);
            if ($scaled !== false) {
                imagedestroy($img);
                $steps[] = 'resize_down';

                return $scaled;
            }
        } elseif ($isPhotoPreset && $w < 1200) {
            // photo_capture: denser upscale for mobile photos (Tesseract / Marathi text).
            $targetW = min(1600, $maxW);
            if ($w < $targetW) {
                $nw = $targetW;
                $nh = (int) max(1, round($h * ($targetW / $w)));
                if ($nw <= $maxW) {
                    $scaled = imagescale($img, $nw, $nh, IMG_BILINEAR_FIXED);
                    if ($scaled !== false) {
                        imagedestroy($img);
                        $steps[] = 'resize_up_photo_stronger';

                        return $scaled;
                    }
                }
            }
        } elseif (! $isPhotoPreset && $w < 720) {
            $target = min(1200, $maxW);
            $nw = (int) round($w * ($target / $w));
            $nh = (int) round($h * ($target / $w));
            if ($nw > $w && $nw <= $maxW) {
                $scaled = imagescale($img, $nw, $nh, IMG_BILINEAR_FIXED);
                if ($scaled !== false) {
                    imagedestroy($img);
                    $steps[] = 'resize_up_moderate';

                    return $scaled;
                }
            }
        }

        return $img;
    }

    /**
     * @param  list<string>  $steps
     */
    private function gdDenoise(GdImage $img, string $level, array &$steps): void
    {
        $passes = match ($level) {
            'strong' => 2,
            'medium' => 1,
            default => 1,
        };
        for ($i = 0; $i < $passes; $i++) {
            if (defined('IMG_FILTER_SMOOTH') && defined('IMG_FILTER_CONTRAST')) {
                @imagefilter($img, IMG_FILTER_SMOOTH, $level === 'strong' ? 8 : 5);
            }
        }
        if ($passes > 0) {
            $steps[] = 'denoise_'.$level;
        }
    }

    /**
     * Gentle brightness/contrast normalization tuned for mobile photos.
     *
     * @param  list<string>  $steps
     */
    private function gdPhotoNormalizeBrightness(GdImage $img, array &$steps): void
    {
        if (! defined('IMG_FILTER_BRIGHTNESS') || ! defined('IMG_FILTER_CONTRAST')) {
            return;
        }

        // Very conservative: avoid blowing out light gray strokes (Marathi thin text).
        @imagefilter($img, IMG_FILTER_BRIGHTNESS, 3);
        @imagefilter($img, IMG_FILTER_CONTRAST, -3);
        $steps[] = 'photo_brightness_norm';
    }

    /**
     * Sample average luminance (0–255) at pixel (assumes truecolor / grayscale RGB).
     */
    private function gdGrayAt(GdImage $img, int $x, int $y): int
    {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return (int) (($r + $g + $b) / 3);
    }

    /**
     * Edge-focused crop to likely document bounds (bright page vs darker surroundings).
     * Uses row/column mean luminance with consecutive-run checks to avoid loose boxes.
     *
     * @param  list<string>  $steps
     */
    private function gdEdgeCropToDocumentBounds(GdImage $img, array &$steps): GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 64 || $h < 64) {
            $steps[] = 'document_edge_crop_skipped_not_found';

            return $img;
        }

        $xStride = max(1, (int) ($w / 400));
        $yStride = max(1, (int) ($h / 400));

        $rowMeans = [];
        for ($y = 0; $y < $h; $y++) {
            $sum = 0;
            $n = 0;
            for ($x = 0; $x < $w; $x += $xStride) {
                $sum += $this->gdGrayAt($img, $x, $y);
                $n++;
            }
            $rowMeans[$y] = $n > 0 ? $sum / $n : 0.0;
        }

        $colMeans = [];
        for ($x = 0; $x < $w; $x++) {
            $sum = 0;
            $n = 0;
            for ($y = 0; $y < $h; $y += $yStride) {
                $sum += $this->gdGrayAt($img, $x, $y);
                $n++;
            }
            $colMeans[$x] = $n > 0 ? $sum / $n : 0.0;
        }

        $peakRow = max($rowMeans);
        $peakCol = max($colMeans);
        if ($peakRow < 45.0 || $peakCol < 45.0) {
            $steps[] = 'document_edge_crop_skipped_not_found';

            return $img;
        }

        $threshRow = max(48.0, $peakRow * 0.60);
        $threshCol = max(48.0, $peakCol * 0.60);

        $minRun = 3;

        $top = null;
        $run = 0;
        for ($y = 0; $y < $h; $y++) {
            if ($rowMeans[$y] >= $threshRow) {
                $run++;
                if ($run >= $minRun) {
                    $top = $y - ($minRun - 1);
                    break;
                }
            } else {
                $run = 0;
            }
        }
        $bottom = null;
        $run = 0;
        for ($y = $h - 1; $y >= 0; $y--) {
            if ($rowMeans[$y] >= $threshRow) {
                $run++;
                if ($run >= $minRun) {
                    $bottom = $y + ($minRun - 1);
                    break;
                }
            } else {
                $run = 0;
            }
        }
        $left = null;
        $run = 0;
        for ($x = 0; $x < $w; $x++) {
            if ($colMeans[$x] >= $threshCol) {
                $run++;
                if ($run >= $minRun) {
                    $left = $x - ($minRun - 1);
                    break;
                }
            } else {
                $run = 0;
            }
        }
        $right = null;
        $run = 0;
        for ($x = $w - 1; $x >= 0; $x--) {
            if ($colMeans[$x] >= $threshCol) {
                $run++;
                if ($run >= $minRun) {
                    $right = $x + ($minRun - 1);
                    break;
                }
            } else {
                $run = 0;
            }
        }

        if ($top === null || $bottom === null || $left === null || $right === null || $bottom <= $top || $right <= $left) {
            $steps[] = 'document_edge_crop_skipped_not_found';

            return $img;
        }

        $marginX = (int) max(4, round($w * 0.025));
        $marginY = (int) max(4, round($h * 0.025));
        $x0 = max(0, $left - $marginX);
        $y0 = max(0, $top - $marginY);
        $x1 = min($w - 1, $right + $marginX);
        $y1 = min($h - 1, $bottom + $marginY);
        $cw = $x1 - $x0 + 1;
        $ch = $y1 - $y0 + 1;

        if ($cw < (int) floor($w * 0.72) || $ch < (int) floor($h * 0.72)) {
            $steps[] = 'document_edge_crop_skipped_low_confidence';

            return $img;
        }

        // No meaningful isolation (full frame): skip to avoid pointless destroy/recreate.
        if ($cw >= (int) floor($w * 0.97) && $ch >= (int) floor($h * 0.97)) {
            $steps[] = 'document_edge_crop_skipped_not_found';

            return $img;
        }

        if ($cw < 32 || $ch < 32) {
            $steps[] = 'document_edge_crop_skipped_not_found';

            return $img;
        }

        $cropped = @imagecrop($img, ['x' => $x0, 'y' => $y0, 'width' => $cw, 'height' => $ch]);
        if ($cropped === false) {
            $steps[] = 'document_edge_crop_skipped_not_found';

            return $img;
        }

        imagedestroy($img);
        $steps[] = 'document_edge_crop_gd';

        return $cropped;
    }

    /**
     * @param  list<string>  $steps
     */
    private function gdContrast(GdImage $img, string $level, array &$steps): void
    {
        if (! defined('IMG_FILTER_CONTRAST')) {
            return;
        }
        $amount = match ($level) {
            'strong' => -30,
            'medium' => -18,
            default => -10,
        };
        @imagefilter($img, IMG_FILTER_CONTRAST, $amount);
        $steps[] = 'contrast_'.$level;
    }

    private function gdApproxAdaptiveThreshold(GdImage $img, int $contrastPregamma = -25): void
    {
        if (defined('IMG_FILTER_CONTRAST')) {
            @imagefilter($img, IMG_FILTER_CONTRAST, $contrastPregamma);
        }
        $this->gdSimpleBinarize($img);
    }

    private function gdSimpleBinarize(GdImage $img): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray = (int) (($r + $g + $b) / 3);
                $c = $gray < 128 ? imagecolorallocate($img, 0, 0, 0) : imagecolorallocate($img, 255, 255, 255);
                imagesetpixel($img, $x, $y, $c);
            }
        }
    }

    /**
     * @return GdImage|false
     */
    private function gdLoad(string $path, string $ext)
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
}
