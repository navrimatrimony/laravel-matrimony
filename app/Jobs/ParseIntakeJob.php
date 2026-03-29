<?php

namespace App\Jobs;

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Services\AiVisionExtractionService;
use App\Services\Intake\IntakeExtractionReuseResolver;
use App\Services\Intake\IntakeParseInputSelectionTrace;
use App\Services\IntakeManualOcrPreparedService;
use App\Services\Ocr\OcrQualityEvaluator;
use App\Services\OcrService;
use App\Services\Parsing\IntakeParsedJsonUtf8Sanitizer;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use App\Services\Parsing\ParserStrategyResolver;
use App\Services\Parsing\ProviderResolver;
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
| Reparse entry points set a short-lived cache flag so paid vision extraction
| is skipped (parse-input-only); text comes from intake.parse_input_text cache,
| fingerprint reuse, or raw_ocr_text fallback. Manual crop saves still dispatch
| without that flag so file→text can run again when needed.
|
*/
class ParseIntakeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $intakeId,
        public bool $forceRecompute = false
    ) {}

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
        if (! $this->forceRecompute && ! $manualPreparedExists && ! $storedOcrBlank && $intake->content_hash && $canonicalVersion) {
            $cached = BiodataIntake::where('id', '!=', $intake->id)
                ->where('content_hash', $intake->content_hash)
                ->where('parser_version', $canonicalVersion)
                ->where('parse_status', 'parsed')
                ->first();
            if ($cached && ! empty($cached->parsed_json)) {
                $ensured = app(IntakeParsedSnapshotSkeleton::class)->ensure((array) $cached->parsed_json);
                $utf8Stats = [];
                $ensured = IntakeParsedJsonUtf8Sanitizer::sanitize($ensured, $utf8Stats);
                if (($utf8Stats['strings_fixed'] ?? 0) > 0) {
                    Log::warning('ParseIntakeJob: repaired malformed UTF-8 in cached parsed_json before save', [
                        'intake_id' => $intake->id,
                        'strings_fixed' => $utf8Stats['strings_fixed'],
                    ]);
                }
                $intake->update([
                    'parsed_json' => $ensured,
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

        $useAiVisionExtraction = app(ProviderResolver::class)->parseJobUsesAiVisionExtraction();

        if ($useAiVisionExtraction) {
            $ai = app(AiVisionExtractionService::class);
            $reuseResolver = app(IntakeExtractionReuseResolver::class);
            $parseInputOnly = $reuseResolver->consumeParseInputOnlyFlag((int) $intake->id);
            $forceFreshPaidExtraction = $reuseResolver->consumeForceFreshPaidExtractionFlag((int) $intake->id);
            $resolvedProv = $ai->resolveExtractionProvider();
            $provider = (string) ($resolvedProv['provider'] ?? 'openai');

            $aiRes = [
                'text' => '',
                'meta' => [
                    'ok' => true,
                    'provider' => $provider,
                    'provider_source' => $resolvedProv['provider_source'] ?? null,
                    'reason' => null,
                ],
            ];
            $raw = '';
            $extractionReused = false;
            $reusedFrom = null;
            $reusedSourceIntakeId = null;
            $textProvenance = null;
            $identityEvidenceScore = null;
            $candidatesSummary = [];
            $winnerQualityPre = null;
            $calledPaidExtract = false;

            $parseResolved = $reuseResolver->resolvePaidVisionInput(
                $intake,
                $provider,
                $ai,
                $parseInputOnly,
                $forceFreshPaidExtraction,
            );
            $candidatesSummary = $parseResolved['candidates_summary'] ?? [];
            $identityEvidenceScore = $parseResolved['identity_evidence_score'];
            $textProvenance = $parseResolved['text_provenance'];
            $winnerQualityPre = $parseResolved['winner_quality_score'];

            if (! empty($parseResolved['call_paid_api']) && ! $parseInputOnly) {
                $aiRes = $ai->extractTextForIntake($intake);
                $calledPaidExtract = true;
                $raw = (string) ($aiRes['text'] ?? '');
                $extractionReused = false;
                $reusedFrom = null;
                $reusedSourceIntakeId = null;
                $textProvenance = 'paid_vision_api_response';
            } else {
                $raw = (string) ($parseResolved['text'] ?? '');
                $extractionReused = trim($raw) !== '' && empty($parseResolved['call_paid_api']);
                $reusedFrom = $parseResolved['reused_from'];
                $reusedSourceIntakeId = $parseResolved['reused_source_intake_id'];
            }

            if ($parseInputOnly && trim($raw) === '') {
                $parseInputDebug = IntakeParseInputSelectionTrace::mergeLearningFields([
                    'parse_input_source' => 'ai_vision_extract_v1',
                    'parse_input_only_job' => true,
                    'extraction_reused' => false,
                    'force_fresh_paid_extraction_requested' => $forceFreshPaidExtraction,
                    'provider' => $provider,
                    'reason' => 'parse_only_no_extraction_text',
                ], null, null, null, null, $candidatesSummary, $forceFreshPaidExtraction);
                $intake->update([
                    'parse_status' => 'error',
                    'last_error' => 'parse_only_no_extraction_text',
                    'parse_duration_ms' => 0,
                    'ai_calls_used' => 0,
                ]);
                Cache::put('intake.parse_input_debug.'.$intake->id, $parseInputDebug, now()->addDays(7));

                return;
            }

            $qualityGate = $ai->evaluateExtractedTextQuality($raw);

            if (empty($qualityGate['ok']) && ! $parseInputOnly && $extractionReused && ! $calledPaidExtract) {
                Log::warning('ParseIntakeJob: reuse candidate failed quality gate; running paid vision extraction', [
                    'intake_id' => $intake->id,
                ]);
                $extractionReused = false;
                $reusedFrom = null;
                $reusedSourceIntakeId = null;
                $textProvenance = null;
                $identityEvidenceScore = null;
                $aiRes = $ai->extractTextForIntake($intake);
                $calledPaidExtract = true;
                $raw = (string) ($aiRes['text'] ?? '');
                $textProvenance = 'paid_vision_api_response';
                $qualityGate = $ai->evaluateExtractedTextQuality($raw);
            }

            $meta = $aiRes['meta'] ?? [];
            $winnerSourceKey = $calledPaidExtract ? 'paid_vision_api' : $reusedFrom;
            $winnerQuality = $calledPaidExtract
                ? $reuseResolver->scoreExtractedText($raw)
                : ($winnerQualityPre ?? $reuseResolver->scoreExtractedText($raw));

            $parseInputDebug = [
                'parse_input_source' => 'ai_vision_extract_v1',
                'parse_input_only_job' => $parseInputOnly,
                'force_fresh_paid_extraction_requested' => $forceFreshPaidExtraction,
                'extraction_reused' => $extractionReused,
                'extraction_reused_from' => $reusedFrom,
                'reused_source_intake_id' => $reusedSourceIntakeId,
                'text_provenance' => $textProvenance,
                'identity_evidence_score' => $identityEvidenceScore,
                'paid_extraction_api_called' => $calledPaidExtract,
                'extraction' => $meta['extraction'] ?? null,
                'provider' => $meta['provider'] ?? $provider,
                'provider_source' => $meta['provider_source'] ?? null,
                'model' => $meta['model'] ?? null,
                'source_field' => $meta['source_field'] ?? null,
                'relative_path' => $meta['relative_path'] ?? null,
                'absolute_path' => $meta['absolute_path'] ?? null,
                'ok' => (bool) ($meta['ok'] ?? false),
                'reason' => $meta['reason'] ?? null,
                'http_status' => $meta['status'] ?? null,
                'response_body_snippet' => $meta['response_body_snippet'] ?? null,
                'job_error_message' => $meta['job_error_message'] ?? null,
                'extraction_error' => $meta['error'] ?? null,
                'text_quality_ok' => (bool) ($qualityGate['ok'] ?? false),
                'text_quality_reason' => $qualityGate['reason'] ?? null,
                'text_chars' => $qualityGate['chars'] ?? null,
                'text_non_space_chars' => $qualityGate['non_space_chars'] ?? null,
                'text_lines' => $qualityGate['lines'] ?? null,
                'text_alpha_ratio' => $qualityGate['alpha_ratio'] ?? null,
                'sarvam_job_id' => $meta['job_id'] ?? null,
                'sarvam_job_state' => $meta['job_state'] ?? null,
                'original_image_width' => $meta['original_image_width'] ?? null,
                'original_image_height' => $meta['original_image_height'] ?? null,
                'ai_request_image_width' => $meta['ai_request_image_width'] ?? null,
                'ai_request_image_height' => $meta['ai_request_image_height'] ?? null,
                'ai_request_payload_enhanced' => $meta['ai_request_payload_enhanced'] ?? null,
                'ai_request_orientation_corrected' => $meta['ai_request_orientation_corrected'] ?? null,
                'vision_detail' => $meta['vision_detail'] ?? null,
                'extracted_text_line_count' => $meta['extracted_text_line_count'] ?? null,
                'failure_detail' => empty($meta['ok']) && $calledPaidExtract ? AiVisionExtractionService::failureDetailForUi($meta) : null,
                'quality_failure_detail' => empty($qualityGate['ok']) ? AiVisionExtractionService::qualityFailureDetailForUi($qualityGate) : null,
            ];
            $parseInputDebug = IntakeParseInputSelectionTrace::mergeLearningFields(
                $parseInputDebug,
                $winnerSourceKey,
                $winnerQuality,
                $identityEvidenceScore,
                $textProvenance,
                $candidatesSummary,
                $forceFreshPaidExtraction,
            );

            if (trim($raw) === '') {
                $intake->update([
                    'parse_status' => 'error',
                    'last_error' => $parseInputDebug['reason'] ? (string) $parseInputDebug['reason'] : 'ai_vision_extract_failed',
                    'parse_duration_ms' => 0,
                    'ai_calls_used' => $calledPaidExtract ? 1 : 0,
                ]);
                Cache::put('intake.parse_input_debug.'.$intake->id, $parseInputDebug, now()->addDays(7));

                return;
            }

            Cache::put('intake.parse_input_debug.'.$intake->id, $parseInputDebug, now()->addDays(7));
            $longLivedParseInputCache = $calledPaidExtract
                || $reusedFrom === 'identity_fingerprint_cache'
                || $reusedFrom === 'intake_parse_input_cache'
                || $reusedFrom === 'historical_intake_raw_ocr';
            $reuseResolver->putCachedParseInputText((int) $intake->id, $raw, $longLivedParseInputCache);

            if (empty($qualityGate['ok'])) {
                $intake->update([
                    'parse_status' => 'error',
                    'last_error' => (string) ($qualityGate['reason'] ?? 'ai_vision_text_unusable'),
                    'parse_duration_ms' => 0,
                    'ai_calls_used' => $calledPaidExtract ? 1 : 0,
                ]);
                Cache::put('intake.parse_input_debug.'.$intake->id, $parseInputDebug, now()->addDays(7));

                return;
            }

            if ($calledPaidExtract && trim($raw) !== '') {
                $reuseResolver->recordSuccessfulPaidExtraction(
                    $intake,
                    $provider,
                    $raw,
                    $reuseResolver->scoreExtractedText($raw),
                );
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

        if ($useAiVisionExtraction) {
            // parse_input_debug / parse_input_text cached immediately after non-empty AI extraction (before quality gate).
        } elseif (is_array($resolved['ocr_debug'] ?? null)) {
            Cache::put('intake.parse_ocr_debug.'.$intake->id, $resolved['ocr_debug'], now()->addDays(7));
        }

        if (config('app.debug')) {
            if ($useAiVisionExtraction && is_array($parseInputDebug)) {
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
        $ssot = app(IntakeParsedSnapshotSkeleton::class)->ensure($parsed);
        // JSON column encoding fails on invalid UTF-8 (OCR/AI); scrub strings only — keep structure.
        $utf8Stats = [];
        $ssot = IntakeParsedJsonUtf8Sanitizer::sanitize($ssot, $utf8Stats);
        if (($utf8Stats['strings_fixed'] ?? 0) > 0) {
            Log::warning('ParseIntakeJob: repaired malformed UTF-8 in parsed_json before save', [
                'intake_id' => $intake->id,
                'strings_fixed' => $utf8Stats['strings_fixed'],
            ]);
        }

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
