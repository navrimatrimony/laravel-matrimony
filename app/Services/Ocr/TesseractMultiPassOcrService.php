<?php

namespace App\Services\Ocr;

use App\Models\AdminSetting;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Throwable;

class TesseractMultiPassOcrService
{
    public const SELECTION_POLICY_VERSION = 'tesseract_multipass_v1';

    /** @var list<string> */
    private const MARATHI_LABELS = [
        'नाव',
        'नांव',
        'जन्म',
        'जन्म तारीख',
        'जन्म दिनांक',
        'उंची',
        'ऊंची',
        'शिक्षण',
        'नोकरी',
        'व्यवसाय',
        'मोबाईल',
        'मोबाइल',
        'संपर्क',
        'पत्ता',
        'वडील',
        'आई',
    ];

    public function __construct(
        private readonly ImagePreprocessingService $imagePreprocessing,
    ) {}

    /**
     * @return array{text: string, debug: array<string, mixed>}
     */
    public function extractFromImage(
        string $absolutePath,
        string $relativeStoredPath,
        ?string $originalName = null,
        ?string $presetOverride = null
    ): array {
        $started = microtime(true);
        $provider = $this->adminSettingValue('intake_ocr_provider', 'tesseract');
        if ($provider === 'off') {
            return [
                'text' => '',
                'debug' => $this->baseDebug($absolutePath, $relativeStoredPath, $originalName, $presetOverride, [
                    'ocr_pipeline' => 'tesseract_disabled',
                    'skipped_reason' => 'provider_off',
                ]),
            ];
        }

        if (! (bool) config('ocr.tesseract_multipass.enabled', true)) {
            try {
                $text = $this->runTesseractAttempt($absolutePath, $this->languageArgs('mixed'), 6);
            } catch (Throwable) {
                $text = '';
            }

            return [
                'text' => trim($text),
                'debug' => $this->baseDebug($absolutePath, $relativeStoredPath, $originalName, $presetOverride, [
                    'ocr_pipeline' => 'single_tesseract_disabled_multipass',
                    'final_ocr_input_path' => $absolutePath,
                    'chosen_variant' => 'original',
                    'chosen_psm' => 6,
                    'chosen_language' => 'mar+eng',
                    'score' => $this->scoreText($text)['score'],
                ]),
            ];
        }

        $variants = $this->imageVariants($absolutePath, $relativeStoredPath, $originalName, $presetOverride);
        $attempts = [];
        $best = null;

        foreach ($this->buildAttemptPlan($variants, false) as $plan) {
            if ($this->timeBudgetExceeded($started)) {
                break;
            }

            $attempt = $this->executeAttempt($plan);
            $attempts[] = $attempt;
            $best = $this->betterAttempt($best, $attempt);
        }

        if ($this->shouldRunEnglishFallback($best)) {
            foreach ($this->buildAttemptPlan($variants, true) as $plan) {
                if ($this->timeBudgetExceeded($started)) {
                    break;
                }

                $attempt = $this->executeAttempt($plan);
                $attempts[] = $attempt;
                $best = $this->betterAttempt($best, $attempt);
            }
        }

        $chosen = $best ?? [
            'text' => '',
            'score' => 0.0,
            'variant_key' => 'original',
            'path' => $absolutePath,
            'psm' => null,
            'language' => null,
            'success' => false,
            'score_meta' => $this->scoreText(''),
            'variant' => $variants[0] ?? [],
        ];

        $debug = $this->debugFromResult(
            $absolutePath,
            $relativeStoredPath,
            $originalName,
            $presetOverride,
            $variants,
            $attempts,
            $chosen,
            (int) round((microtime(true) - $started) * 1000)
        );

        $this->cleanupVariants($variants);

        return [
            'text' => trim((string) ($chosen['text'] ?? '')),
            'debug' => $debug,
        ];
    }

    /**
     * @return array{score: float, label_hits: int, devanagari_chars: int, latin_chars: int, mobile_like_count: int, digit_count: int, line_count: int, char_count: int, penalties: list<string>}
     */
    public function scoreText(string $text): array
    {
        $text = trim(OcrNormalize::normalizeDigits($text));
        $charCount = mb_strlen($text, 'UTF-8');
        $lineCount = $text === '' ? 0 : count(preg_split('/\R/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        $devanagariChars = preg_match_all('/\p{Devanagari}/u', $text, $mDev);
        $devanagariChars = $devanagariChars === false ? 0 : $devanagariChars;
        $latinChars = preg_match_all('/[A-Za-z]/u', $text, $mLat);
        $latinChars = $latinChars === false ? 0 : $latinChars;
        $digitCount = preg_match_all('/\d/u', $text, $mDigits);
        $digitCount = $digitCount === false ? 0 : $digitCount;
        $mobileLikeCount = preg_match_all('/(?<!\d)(?:\+?91[\s\-]*)?[6-9]\d[\d\s\-]{8,14}(?!\d)/u', $text, $mMob);
        $mobileLikeCount = $mobileLikeCount === false ? 0 : $mobileLikeCount;

        $labelHits = 0;
        foreach (self::MARATHI_LABELS as $label) {
            if (str_contains($text, $label)) {
                $labelHits++;
            }
        }

        $score = 0.0;
        $score += min(25.0, $devanagariChars * 0.10);
        $score += min(50.0, $labelHits * 7.5);
        $score += min(16.0, $mobileLikeCount * 10.0 + $digitCount * 0.20);
        $score += min(12.0, $lineCount * 1.5);
        $score += min(12.0, $charCount / 35.0);

        $penalties = [];

        $validSlashDates = 0;
        $invalidSlashDates = 0;
        if (preg_match_all('/(?<!\d)(\d{1,2})\s*[\/.\-]\s*(\d{1,2})\s*[\/.\-]\s*(\d{4})(?!\d)/u', $text, $dateMatches, PREG_SET_ORDER) !== false) {
            foreach ($dateMatches as $dm) {
                $day = (int) $dm[1];
                $month = (int) $dm[2];
                $year = (int) $dm[3];
                if (checkdate($month, $day, $year) && $year >= 1940 && $year <= (int) date('Y')) {
                    $validSlashDates++;
                } else {
                    $invalidSlashDates++;
                }
            }
        }
        // Prefer variants that keep calendar-looking biodata dates intact (raw fidelity).
        $score += min(24.0, $validSlashDates * 14.0);
        if ($invalidSlashDates > 0 && $validSlashDates === 0) {
            $penalties[] = 'invalid_slash_dates_only';
            $score -= min(18.0, $invalidSlashDates * 8.0);
        }

        $englishBiodataHits = 0;
        foreach ([
            'date of birth', 'father', 'mother', 'resume', 'place of birth',
            'birth time', 'height', 'education', 'caste', 'religion',
        ] as $kw) {
            if (stripos($text, $kw) !== false) {
                $englishBiodataHits++;
            }
        }
        $score += min(28.0, $englishBiodataHits * 7.0);

        $englishMonthDate = preg_match(
            '/\b(\d{1,2})(?:st|nd|rd|th)?\s+(january|february|march|april|may|june|july|august|september|october|november|december)\s+\d{4}\b/iu',
            $text
        ) === 1
            || preg_match(
                '/\b(january|february|march|april|may|june|july|august|september|october|november|december)\s+\d{1,2},?\s+\d{4}\b/iu',
                $text
            ) === 1;
        if ($englishMonthDate) {
            $score += 16.0;
        }

        if ($charCount < 25) {
            $penalties[] = 'very_short_output';
            $score -= 30.0;
        } elseif ($charCount < 80) {
            $penalties[] = 'short_output';
            $score -= 12.0;
        }

        $letters = max(1, $devanagariChars + $latinChars);
        $latinRatio = $latinChars / $letters;
        if ($latinRatio > 0.75 && $labelHits < 2 && $englishBiodataHits < 2 && ! $englishMonthDate) {
            $penalties[] = 'latin_garbage_ratio';
            $score -= 22.0;
        }

        $symbolCount = preg_match_all('/[^\p{L}\p{M}\p{N}\s:\/\-.+()]/u', $text, $mSymbols);
        $symbolCount = $symbolCount === false ? 0 : $symbolCount;
        if ($symbolCount > max(25, (int) floor($charCount * 0.20))) {
            $penalties[] = 'symbol_noise_ratio';
            $score -= 12.0;
        }

        if ($labelHits === 0 && $mobileLikeCount === 0 && $devanagariChars < 20
            && $englishBiodataHits === 0 && ! $englishMonthDate) {
            $penalties[] = 'no_biodata_signals';
            $score -= 18.0;
        }

        return [
            'score' => round(max(0.0, $score), 3),
            'label_hits' => $labelHits,
            'devanagari_chars' => $devanagariChars,
            'latin_chars' => $latinChars,
            'mobile_like_count' => $mobileLikeCount,
            'digit_count' => $digitCount,
            'line_count' => $lineCount,
            'char_count' => $charCount,
            'penalties' => $penalties,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @return list<array{variant: array<string, mixed>, psm: int, language: string}>
     */
    protected function buildAttemptPlan(array $variants, bool $englishFallback): array
    {
        $psmModes = $this->intListConfig('ocr.tesseract_multipass.psm_modes', [6, 4, 11]);
        $languages = $englishFallback
            ? ['eng']
            : $this->languagePlan();

        $maxAttempts = max(1, (int) config('ocr.tesseract_multipass.max_attempts', 24));
        $plan = [];
        foreach ($variants as $variant) {
            foreach ($languages as $language) {
                foreach ($psmModes as $psm) {
                    $plan[] = [
                        'variant' => $variant,
                        'psm' => $psm,
                        'language' => $language,
                    ];
                    if (count($plan) >= $maxAttempts) {
                        return $plan;
                    }
                }
            }
        }

        return $plan;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function imageVariants(
        string $absolutePath,
        string $relativeStoredPath,
        ?string $originalName,
        ?string $presetOverride
    ): array {
        $variants = [[
            'key' => 'original',
            'path' => $absolutePath,
            'preset' => null,
            'preprocess_used' => false,
            'fallback_used' => false,
            'cleanup' => false,
            'meta' => [
                'driver' => null,
                'steps' => [],
                'skipped_reason' => $presetOverride === 'off' ? 'off' : null,
            ],
        ]];

        if ($presetOverride === 'off') {
            return $variants;
        }

        $presetNames = $this->variantPresetNames($relativeStoredPath, $originalName, $presetOverride);
        foreach ($presetNames as $presetName) {
            try {
                if (! $this->imagePreprocessing->shouldPreprocess($relativeStoredPath, $originalName)) {
                    continue;
                }

                $result = $this->imagePreprocessing->preprocessForOcr(
                    $absolutePath,
                    $relativeStoredPath,
                    $originalName,
                    $presetName
                );
            } catch (Throwable $e) {
                Log::warning('ocr_multipass: preprocessing variant failed', [
                    'preset' => $presetName,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $out = is_string($result['output_absolute_path'] ?? null) ? $result['output_absolute_path'] : '';
            if (! ($result['used'] ?? false) || $out === '' || ! is_file($out) || ! is_readable($out)) {
                continue;
            }

            $variants[] = [
                'key' => 'preset_'.$presetName,
                'path' => $out,
                'preset' => $presetName,
                'preprocess_used' => true,
                'fallback_used' => (bool) ($result['fallback_used'] ?? false),
                'cleanup' => true,
                'meta' => is_array($result['meta'] ?? null) ? $result['meta'] : [],
                'output_path' => $result['output_path'] ?? null,
            ];
        }

        if (count($variants) === 1) {
            $cli = $this->imageMagickCliVariant($absolutePath);
            if ($cli !== null) {
                $variants[] = $cli;
            }
        }

        return $variants;
    }

    /**
     * @return list<string>
     */
    private function variantPresetNames(string $relativeStoredPath, ?string $originalName, ?string $presetOverride): array
    {
        $resolved = $presetOverride;
        if ($resolved === null || $resolved === '' || $resolved === 'auto') {
            $resolved = $this->imagePreprocessing->resolvePreset($relativeStoredPath, $originalName, null);
        }

        $configured = config('ocr.tesseract_multipass.preprocessing_presets', ['resolved', 'photo_capture', 'high_contrast']);
        $configured = is_array($configured) ? $configured : ['resolved', 'photo_capture', 'high_contrast'];

        $out = [];
        foreach ($configured as $entry) {
            $name = (string) $entry;
            if ($name === 'resolved') {
                $name = (string) $resolved;
            }
            if ($name === '' || $name === 'off' || $name === 'auto') {
                continue;
            }
            $out[$name] = true;
        }

        return array_keys($out);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function imageMagickCliVariant(string $absolutePath): ?array
    {
        if (! (bool) config('ocr.tesseract_multipass.imagemagick_cli_enabled', true)) {
            return null;
        }

        $binary = $this->imageMagickBinary();
        if ($binary === null) {
            return null;
        }

        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ocr_multipass_'.uniqid('', true).'.png';
        $cmd = str_ends_with(strtolower(basename($binary)), 'magick.exe') || strtolower(basename($binary)) === 'magick'
            ? [$binary, $absolutePath, '-auto-orient', '-colorspace', 'Gray', '-resize', '200%', '-normalize', '-sharpen', '0x1', $tmp]
            : [$binary, $absolutePath, '-auto-orient', '-colorspace', 'Gray', '-resize', '200%', '-normalize', '-sharpen', '0x1', $tmp];

        try {
            $process = new Process($cmd);
            $process->setTimeout(max(5, (int) config('ocr.tesseract_multipass.preprocess_timeout_seconds', 15)));
            $process->run();
            if (! $process->isSuccessful() || ! is_file($tmp) || ! is_readable($tmp)) {
                @unlink($tmp);

                return null;
            }
        } catch (Throwable) {
            @unlink($tmp);

            return null;
        }

        return [
            'key' => 'imagemagick_cli_gray_resize',
            'path' => $tmp,
            'preset' => 'imagemagick_cli_gray_resize',
            'preprocess_used' => true,
            'fallback_used' => false,
            'cleanup' => true,
            'meta' => [
                'driver' => 'imagemagick_cli',
                'steps' => ['grayscale', 'resize_200', 'normalize', 'sharpen'],
                'binary' => basename($binary),
            ],
        ];
    }

    private function imageMagickBinary(): ?string
    {
        $finder = new ExecutableFinder;
        foreach (['magick', 'convert'] as $name) {
            $path = $finder->find($name);
            if (! is_string($path) || $path === '') {
                continue;
            }

            try {
                $process = new Process([$path, '-version']);
                $process->setTimeout(5);
                $process->run();
                $out = $process->getOutput().$process->getErrorOutput();
                if ($process->isSuccessful() && stripos($out, 'ImageMagick') !== false) {
                    return $path;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param  array{variant: array<string, mixed>, psm: int, language: string}  $plan
     * @return array<string, mixed>
     */
    private function executeAttempt(array $plan): array
    {
        $variant = $plan['variant'];
        $path = (string) ($variant['path'] ?? '');
        $psm = (int) $plan['psm'];
        $language = (string) $plan['language'];
        $started = microtime(true);

        try {
            $text = $this->runTesseractAttempt($path, $this->languageArgs($language), $psm);
            $scoreMeta = $this->scoreText($text);

            return [
                'success' => trim($text) !== '',
                'text' => trim($text),
                'score' => (float) $scoreMeta['score'],
                'score_meta' => $scoreMeta,
                'variant_key' => (string) ($variant['key'] ?? 'unknown'),
                'variant' => $variant,
                'path' => $path,
                'psm' => $psm,
                'language' => $language,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'text' => '',
                'score' => 0.0,
                'score_meta' => $this->scoreText(''),
                'variant_key' => (string) ($variant['key'] ?? 'unknown'),
                'variant' => $variant,
                'path' => $path,
                'psm' => $psm,
                'language' => $language,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  list<string>  $languages
     */
    protected function runTesseractAttempt(string $path, array $languages, int $psm): string
    {
        $ocr = new TesseractOCR($path);
        $exe = trim((string) config('services.tesseract.path'));
        if ($exe !== '' && is_file($exe)) {
            $ocr->executable($exe);
        }

        $ocr->oem(1);
        $ocr->psm($psm);
        $ocr->lang(...$languages);

        return trim($ocr->run(max(1, (int) config('ocr.tesseract_multipass.attempt_timeout_seconds', 20))));
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>|null
     */
    private function betterAttempt(?array $current, array $candidate): ?array
    {
        if (empty($candidate['success'])) {
            return $current;
        }

        if ($current === null || empty($current['success'])) {
            return $candidate;
        }

        $candidateScore = (float) ($candidate['score'] ?? 0.0);
        $currentScore = (float) ($current['score'] ?? 0.0);
        if (abs($candidateScore - $currentScore) > 0.001) {
            return $candidateScore > $currentScore ? $candidate : $current;
        }

        $candidateMeta = is_array($candidate['score_meta'] ?? null) ? $candidate['score_meta'] : [];
        $currentMeta = is_array($current['score_meta'] ?? null) ? $current['score_meta'] : [];
        if ((int) ($candidateMeta['label_hits'] ?? 0) !== (int) ($currentMeta['label_hits'] ?? 0)) {
            return (int) ($candidateMeta['label_hits'] ?? 0) > (int) ($currentMeta['label_hits'] ?? 0)
                ? $candidate
                : $current;
        }

        return (int) ($candidateMeta['char_count'] ?? 0) > (int) ($currentMeta['char_count'] ?? 0)
            ? $candidate
            : $current;
    }

    /**
     * @param  array<string, mixed>|null  $best
     */
    private function shouldRunEnglishFallback(?array $best): bool
    {
        if (! (bool) config('ocr.tesseract_multipass.english_fallback_enabled', true)) {
            return false;
        }

        if ($best === null || empty($best['success'])) {
            return true;
        }

        $meta = is_array($best['score_meta'] ?? null) ? $best['score_meta'] : [];

        return (float) ($best['score'] ?? 0.0) < 22.0
            || ((int) ($meta['devanagari_chars'] ?? 0) < 15 && (int) ($meta['label_hits'] ?? 0) === 0);
    }

    /**
     * @return list<string>
     */
    private function languagePlan(): array
    {
        $hint = $this->adminSettingValue('intake_ocr_language_hint', 'mixed');

        return match ($hint) {
            'mr' => ['mar'],
            'en' => ['eng'],
            default => ['mar+eng', 'mar', 'eng'],
        };
    }

    /**
     * @return list<string>
     */
    private function languageArgs(string $language): array
    {
        return match ($language) {
            'mar+eng' => ['mar', 'eng'],
            'mar' => ['mar'],
            'eng' => ['eng'],
            default => ['mar', 'eng'],
        };
    }

    private function timeBudgetExceeded(float $started): bool
    {
        $budget = (float) config('ocr.tesseract_multipass.max_runtime_seconds', 60);

        return $budget > 0 && (microtime(true) - $started) >= $budget;
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     */
    private function cleanupVariants(array $variants): void
    {
        $keepDerived = config('app.debug')
            && (bool) config('ocr.preprocessing.debug_keep_derived_when_app_debug', true);

        foreach ($variants as $variant) {
            if (empty($variant['cleanup']) || $keepDerived || ! (bool) config('ocr.preprocessing.cleanup_enabled', true)) {
                continue;
            }

            $path = (string) ($variant['path'] ?? '');
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @param  list<array<string, mixed>>  $attempts
     * @param  array<string, mixed>  $chosen
     * @return array<string, mixed>
     */
    private function debugFromResult(
        string $absolutePath,
        string $relativeStoredPath,
        ?string $originalName,
        ?string $presetOverride,
        array $variants,
        array $attempts,
        array $chosen,
        int $durationMs
    ): array {
        $variant = is_array($chosen['variant'] ?? null) ? $chosen['variant'] : [];
        $meta = is_array($variant['meta'] ?? null) ? $variant['meta'] : [];
        [$ow, $oh] = $this->imageDimensions($absolutePath);

        return $this->baseDebug($absolutePath, $relativeStoredPath, $originalName, $presetOverride, [
            'ocr_pipeline' => 'tesseract_multipass',
            'final_ocr_input_path' => (string) ($chosen['path'] ?? $absolutePath),
            'derived_absolute_path' => ! empty($variant['preprocess_used']) ? (string) ($variant['path'] ?? '') : null,
            'derived_storage_relative' => $variant['output_path'] ?? null,
            'preset_resolved' => $variant['preset'] ?? null,
            'preprocess_used' => (bool) ($variant['preprocess_used'] ?? false),
            'fallback_used' => (bool) ($variant['fallback_used'] ?? false),
            'skipped_preprocessing_reason' => $meta['skipped_reason'] ?? null,
            'derived_kept_on_disk' => config('app.debug') && (bool) config('ocr.preprocessing.debug_keep_derived_when_app_debug', true),
            'original_filesize' => $this->fileSize($absolutePath),
            'derived_filesize' => ! empty($variant['preprocess_used']) ? $this->fileSize((string) ($variant['path'] ?? '')) : null,
            'original_width' => $meta['original_width'] ?? $ow,
            'original_height' => $meta['original_height'] ?? $oh,
            'derived_width' => $meta['width'] ?? null,
            'derived_height' => $meta['height'] ?? null,
            'driver' => $meta['driver'] ?? null,
            'output_format' => $meta['output_format'] ?? null,
            'applied_steps' => $meta['applied_steps'] ?? $meta['steps'] ?? [],
            'driver_resolution_diagnostics' => config('app.debug') ? ($meta['resolution_diagnostics'] ?? null) : null,
            'chosen_variant' => (string) ($chosen['variant_key'] ?? 'original'),
            'chosen_psm' => $chosen['psm'] ?? null,
            'chosen_language' => $chosen['language'] ?? null,
            'score' => (float) ($chosen['score'] ?? 0.0),
            'score_meta' => $chosen['score_meta'] ?? null,
            'attempt_count' => count($attempts),
            'failed_attempt_count' => count(array_filter($attempts, static fn (array $a): bool => empty($a['success']))),
            'attempts_summary' => $this->attemptsSummary($attempts),
            'variants_summary' => $this->variantsSummary($variants),
            'selection_policy_version' => self::SELECTION_POLICY_VERSION,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function baseDebug(
        string $absolutePath,
        string $relativeStoredPath,
        ?string $originalName,
        ?string $presetOverride,
        array $extra
    ): array {
        return array_merge([
            'kind' => 'image',
            'original_absolute_path' => $absolutePath,
            'original_storage_relative' => $relativeStoredPath,
            'original_filename' => $originalName,
            'preset_request' => $presetOverride,
            'preprocess_used' => false,
            'fallback_used' => false,
            'final_ocr_input_path' => $absolutePath,
        ], $extra);
    }

    /**
     * @param  list<array<string, mixed>>  $attempts
     * @return list<array<string, mixed>>
     */
    private function attemptsSummary(array $attempts): array
    {
        return array_map(static function (array $attempt): array {
            $scoreMeta = is_array($attempt['score_meta'] ?? null) ? $attempt['score_meta'] : [];

            return array_filter([
                'variant' => $attempt['variant_key'] ?? null,
                'psm' => $attempt['psm'] ?? null,
                'language' => $attempt['language'] ?? null,
                'success' => (bool) ($attempt['success'] ?? false),
                'score' => $attempt['score'] ?? null,
                'label_hits' => $scoreMeta['label_hits'] ?? null,
                'devanagari_chars' => $scoreMeta['devanagari_chars'] ?? null,
                'mobile_like_count' => $scoreMeta['mobile_like_count'] ?? null,
                'char_count' => $scoreMeta['char_count'] ?? null,
                'duration_ms' => $attempt['duration_ms'] ?? null,
                'error' => $attempt['error'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }, $attempts);
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @return list<array<string, mixed>>
     */
    private function variantsSummary(array $variants): array
    {
        return array_map(static function (array $variant): array {
            $meta = is_array($variant['meta'] ?? null) ? $variant['meta'] : [];

            return array_filter([
                'key' => $variant['key'] ?? null,
                'preset' => $variant['preset'] ?? null,
                'preprocess_used' => (bool) ($variant['preprocess_used'] ?? false),
                'driver' => $meta['driver'] ?? null,
                'steps' => $meta['applied_steps'] ?? $meta['steps'] ?? [],
                'skipped_reason' => $meta['skipped_reason'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== []);
        }, $variants);
    }

    /**
     * @return list<int>
     */
    private function intListConfig(string $key, array $fallback): array
    {
        $value = config($key, $fallback);
        if (! is_array($value)) {
            return $fallback;
        }

        $out = [];
        foreach ($value as $item) {
            $n = (int) $item;
            if ($n > 0) {
                $out[] = $n;
            }
        }

        return $out !== [] ? array_values(array_unique($out)) : $fallback;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function imageDimensions(string $path): array
    {
        $dim = @getimagesize($path);
        if (! is_array($dim)) {
            return [null, null];
        }

        return [isset($dim[0]) ? (int) $dim[0] : null, isset($dim[1]) ? (int) $dim[1] : null];
    }

    private function fileSize(string $path): ?int
    {
        $size = $path !== '' && is_file($path) ? @filesize($path) : false;

        return $size !== false ? (int) $size : null;
    }

    private function adminSettingValue(string $key, mixed $default): mixed
    {
        try {
            return AdminSetting::getValue($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }
}
