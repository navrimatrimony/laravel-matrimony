<?php

namespace App\Jobs;

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Services\IntakeManualOcrPreparedService;
use App\Services\AiVisionExtractionService;
use App\Services\Ocr\OcrQualityEvaluator;
use App\Services\OcrService;
use App\Services\Parsing\ParserStrategyResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| ParseIntakeJob — Phase-5 SSOT: parse only, never modify raw_ocr_text
|--------------------------------------------------------------------------
|
| 1) Fetch intake. 2) If parse_status != pending return. 3) Build parse text:
|    if ocr-manual-prepared/{id}/manual.png exists, OCR that derived file (raw_ocr_text unchanged);
|    else use normalized raw_ocr_text. 4) Parse via BiodataParserService. 5) Store parsed_json.
| Do NOT touch raw_ocr_text.
|
| When forceRecompute is true (e.g. admin reparse), content_hash cache is
| bypassed so updated parser code runs and parsed_json is recomputed.
|
*/
class ParseIntakeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $intakeId,
        public bool $forceRecompute = false
    ) {
    }

    /**
     * Execute: parse via BiodataParserService, store SSOT-compliant parsed_json only.
     */
    public function handle(): void
    {
        $intake = BiodataIntake::find($this->intakeId);

        Log::info('ParseIntakeJob::handle() started', [
            'intake_id' => $this->intakeId,
            'forceRecompute' => $this->forceRecompute,
            'parse_status_before' => $intake?->parse_status,
            'intake_found' => $intake !== null,
        ]);

        if ($intake === null) {
            return;
        }

        // Do not reparse approved/locked intakes.
        if ($intake->approved_by_user || $intake->intake_locked) {
            Log::info('ParseIntakeJob::handle() early return: approved or locked', ['intake_id' => $this->intakeId]);
            return;
        }

        if ($intake->parse_status !== 'pending') {
            Log::info('ParseIntakeJob::handle() early return: parse_status not pending', [
                'intake_id' => $this->intakeId,
                'parse_status' => $intake->parse_status,
            ]);
            return;
        }

        $resolver = app(ParserStrategyResolver::class);
        $mode = $resolver->resolveActiveMode();

        $manualPreparedExists = app(IntakeManualOcrPreparedService::class)->exists($intake);
        $storedOcrBlank = trim((string) ($intake->raw_ocr_text ?? '')) === '';

        // Smart caching — avoid re-parsing identical content for same parser_version.
        // Bypass cache when forceRecompute is true (e.g. admin reparse) so updated parser runs.
        // Bypass when manual crop exists (parse input is not the same as upload-time content_hash).
        // Also bypass when stored upload-time OCR text is blank (we may re-run OCR at parse-time).
        $canonicalVersion = $resolver->normalizeMode($intake->parser_version ?: $mode);
        if (!$this->forceRecompute && !$manualPreparedExists && !$storedOcrBlank && $intake->content_hash && $canonicalVersion) {
            $cached = BiodataIntake::where('id', '!=', $intake->id)
                ->where('content_hash', $intake->content_hash)
                ->where('parser_version', $canonicalVersion)
                ->where('parse_status', 'parsed')
                ->first();
            if ($cached && ! empty($cached->parsed_json)) {
                $intake->update([
                    'parsed_json' => $cached->parsed_json,
                    'parse_status' => 'parsed',
                    'parser_version' => $canonicalVersion,
                ]);

                Log::info('Intake parse reused cached parsed_json', [
                    'intake_id' => $intake->id,
                    'parser_version' => $canonicalVersion,
                ]);

                return;
            }
        }

        $retryLimit = (int) AdminSetting::getValue('intake_parse_retry_limit', '3');

        $parser = $resolver->makeParser($mode);
        $ocr = app(OcrService::class);
        $qualityEvaluator = app(OcrQualityEvaluator::class);

        $resolved = null;
        $raw = '';
        $parseInputDebug = null;

        if ($mode === ParserStrategyResolver::MODE_AI_VISION_EXTRACT_V1) {
            $ai = app(AiVisionExtractionService::class);
            $aiRes = $ai->extractTextForIntake($intake);
            $raw = (string) ($aiRes['text'] ?? '');
            $qualityGate = $ai->evaluateExtractedTextQuality($raw);
            $parseInputDebug = [
                'parse_input_source' => 'ai_vision_extract_v1',
                'extraction' => $aiRes['meta']['extraction'] ?? null,
                'provider' => $aiRes['meta']['provider'] ?? null,
                'model' => $aiRes['meta']['model'] ?? null,
                'source_field' => $aiRes['meta']['source_field'] ?? null,
                'relative_path' => $aiRes['meta']['relative_path'] ?? null,
                'absolute_path' => $aiRes['meta']['absolute_path'] ?? null,
                'ok' => (bool) ($aiRes['meta']['ok'] ?? false),
                'reason' => $aiRes['meta']['reason'] ?? null,
                'text_quality_ok' => (bool) ($qualityGate['ok'] ?? false),
                'text_quality_reason' => $qualityGate['reason'] ?? null,
                'text_chars' => $qualityGate['chars'] ?? null,
                'text_non_space_chars' => $qualityGate['non_space_chars'] ?? null,
                'text_lines' => $qualityGate['lines'] ?? null,
                'text_alpha_ratio' => $qualityGate['alpha_ratio'] ?? null,
                'sarvam_job_id' => $aiRes['meta']['job_id'] ?? null,
                'sarvam_job_state' => $aiRes['meta']['job_state'] ?? null,
                'original_image_width' => $aiRes['meta']['original_image_width'] ?? null,
                'original_image_height' => $aiRes['meta']['original_image_height'] ?? null,
                'ai_request_image_width' => $aiRes['meta']['ai_request_image_width'] ?? null,
                'ai_request_image_height' => $aiRes['meta']['ai_request_image_height'] ?? null,
                'ai_request_payload_enhanced' => $aiRes['meta']['ai_request_payload_enhanced'] ?? null,
                'ai_request_orientation_corrected' => $aiRes['meta']['ai_request_orientation_corrected'] ?? null,
                'vision_detail' => $aiRes['meta']['vision_detail'] ?? null,
                'extracted_text_line_count' => $aiRes['meta']['extracted_text_line_count'] ?? null,
            ];

            if (trim($raw) === '') {
                $intake->update([
                    'parse_status' => 'error',
                    'last_error' => $parseInputDebug['reason'] ? (string) $parseInputDebug['reason'] : 'ai_vision_extract_failed',
                    'parse_duration_ms' => 0,
                    'ai_calls_used' => 1,
                ]);
                Cache::put('intake.parse_input_debug.'.$intake->id, $parseInputDebug, now()->addDays(7));

                return;
            }
            if (empty($qualityGate['ok'])) {
                $intake->update([
                    'parse_status' => 'error',
                    'last_error' => (string) ($qualityGate['reason'] ?? 'ai_vision_text_unusable'),
                    'parse_duration_ms' => 0,
                    'ai_calls_used' => 1,
                ]);
                Cache::put('intake.parse_input_debug.'.$intake->id, $parseInputDebug, now()->addDays(7));

                return;
            }
        } else {
            $resolved = $ocr->resolveParseInputText($intake);
            $raw = $resolved['text'];
        }

        $ocrQuality = $qualityEvaluator->evaluate($raw);

        $retryConfig = config('ocr.auto_retry', []);
        if ($manualPreparedExists
            && ($retryConfig['enabled'] ?? false)
            && ($ocrQuality['score'] ?? 0) < (float) ($retryConfig['quality_threshold'] ?? 0.6)) {
            $maxAttempts = max(0, (int) ($retryConfig['max_attempts'] ?? 0));
            $attempt = 0;
            foreach ($retryConfig['retry_presets'] ?? [] as $preset) {
                if ($attempt >= $maxAttempts) {
                    break;
                }
                $attempt++;
                $retryResolved = $ocr->resolveParseInputText($intake, [
                    'force_preset' => (string) $preset,
                ]);
                $retryText = $retryResolved['text'];
                $retryQuality = $qualityEvaluator->evaluate($retryText);
                if (($retryQuality['score'] ?? 0) > ($ocrQuality['score'] ?? 0)) {
                    $raw = $retryText;
                    $ocrQuality = $retryQuality;
                    $resolved = $retryResolved;
                }
            }
        }

        Cache::put('intake.parse_ocr_quality.'.$intake->id, $ocrQuality, now()->addDays(7));

        if ($mode === ParserStrategyResolver::MODE_AI_VISION_EXTRACT_V1) {
            Cache::put('intake.parse_input_debug.'.$intake->id, $parseInputDebug, now()->addDays(7));
            // Transient preview-only: text used as parser input (not persisted; not raw_ocr_text).
            Cache::put('intake.parse_input_text.'.$intake->id, $raw, now()->addDays(7));
        } elseif (is_array($resolved['ocr_debug'] ?? null)) {
            Cache::put('intake.parse_ocr_debug.'.$intake->id, $resolved['ocr_debug'], now()->addDays(7));
        }

        if (config('app.debug')) {
            if ($mode === ParserStrategyResolver::MODE_AI_VISION_EXTRACT_V1 && is_array($parseInputDebug)) {
                Log::info('ParseIntakeJob: parse input source (ai vision)', array_merge($parseInputDebug, [
                    'ocr_quality' => $ocrQuality,
                ]));
            } elseif (is_array($resolved['ocr_debug'] ?? null)) {
                Log::info('ParseIntakeJob: parse OCR source', array_merge($resolved['ocr_debug'], [
                    'ocr_quality' => $ocrQuality,
                ]));
            }
        }

        $parsed = null;
        $attempts = 0;
        $lastException = null;
        $aiCalls = 0; // Reserved for future detailed AI tracking
        $start = microtime(true);

        while ($attempts === 0 || ($attempts < $retryLimit && $parsed === null && $lastException !== null)) {
            $attempts++;
            try {
                $parsed = $parser->parse($raw, [
                    'intake_id' => $intake->id,
                    'parser_mode' => $mode,
                ]);
                $lastException = null;
                break;
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        if ($parsed === null) {
            $intake->update([
                'parse_status' => 'error',
                'last_error' => $lastException ? substr($lastException->getMessage(), 0, 255) : 'parse_failed',
                'parse_duration_ms' => $durationMs,
                'ai_calls_used' => $aiCalls,
            ]);

            Log::error('Intake parse failed', [
                'intake_id' => $intake->id,
                'parser_mode' => $mode,
                'error' => $lastException?->getMessage(),
            ]);

            return;
        }

        // At this point parsers are already required to return SSOT-compatible shape.
        $ssot = $parsed;

        $intake->update([
            'parsed_json' => $ssot,
            'parse_status' => 'parsed',
            'parser_version' => $canonicalVersion,
            'parse_duration_ms' => $durationMs,
            'ai_calls_used' => $aiCalls,
        ]);

        Log::info('Intake parsed successfully', [
            'intake_id' => $intake->id,
            'parser_mode' => $mode,
            'parser_version' => $canonicalVersion,
            'contacts_count' => is_countable($ssot['contacts'] ?? []) ? count($ssot['contacts']) : 0,
            'education_count' => is_countable($ssot['education_history'] ?? []) ? count($ssot['education_history']) : 0,
            'career_count' => is_countable($ssot['career_history'] ?? []) ? count($ssot['career_history']) : 0,
            'relatives_count' => is_countable($ssot['relatives'] ?? []) ? count($ssot['relatives']) : 0,
            'siblings_count' => is_countable($ssot['siblings'] ?? []) ? count($ssot['siblings']) : 0,
            'addresses_count' => is_countable($ssot['addresses'] ?? []) ? count($ssot['addresses']) : 0,
            'duration_ms' => $durationMs,
        ]);
    }

}

