<?php

namespace App\Jobs;

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Services\AiVisionExtractionService;
use App\Services\Intake\IntakeExtractionReuseResolver;
use App\Services\Intake\IntakeParseInputSelectionTrace;
use App\Services\Intake\IntakePipelineService;
use App\Services\IntakeManualOcrPreparedService;
use App\Services\Ocr\OcrQualityEvaluator;
use App\Services\OcrService;
use App\Services\Parsing\ParserStrategyResolver;
use App\Services\Parsing\ProviderResolver;
use App\Support\IntakeDobTrace;
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
| 1) Fetch intake. 2) If parse_status != pending return. 3) Build parse text ($raw), same resolution as review UI:
|    AI vision mode: extraction / reuse; else manual-crop OCR or upload OCR via OcrService (raw_ocr_text unchanged).
| 4) Parse via strategy parser. 5) Store parsed_json and last_parse_input_text (= exact $raw).
| Do NOT touch raw_ocr_text.
|
| Canonical transcript: last_parse_input_text (DB) + optional parse_input_text cache; re-parse uses these first
| in ai_vision_extract_v1 mode — never raw_ocr_text unless explicit_fallback_raw_ocr_text (metadata).
| Re-extract: force_fresh_paid_extraction → AiVisionExtractionService only (no raw_ocr shortcut).
| Manual crop: forceRecompute without parse_input_only → normal OCR/vision resolution.
|
| LOCKED: Cross-intake parsed_json reuse is forbidden. parsed_json is always the
| output of $parser->parse($raw, ...) for this intake id, where $raw is this
| intake's resolved parse input (OCR / vision extract / per-intake cache only).
| Transcript text may be reused for cost (see IntakeExtractionReuseResolver);
| structured JSON is never copied from another BiodataIntake row.
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

        // Never copy parsed_json from another intake (same content_hash / file): always run the parser
        // on this intake's resolved extract/OCR text so structured output matches reviewable input.
        $canonicalVersion = $resolver->normalizeMode($intake->parser_version ?: $mode);

        $retryLimit = (int) AdminSetting::getValue('intake_parse_retry_limit', '3');

        $parser = $resolver->makeParser($mode);
        $ocr = app(OcrService::class);
        $qualityEvaluator = app(OcrQualityEvaluator::class);

        $resolved = null;
        $raw = '';
        $parseInputDebug = null;
        $reparseEarlyResolved = false;

        $reuseResolver = app(IntakeExtractionReuseResolver::class);

        $pendingParseInputOnly = IntakeExtractionReuseResolver::peekParseInputOnlyFlag((int) $intake->id);
        $pendingForceFreshPaidExtraction = IntakeExtractionReuseResolver::peekForceFreshPaidExtractionFlag((int) $intake->id);

        $reparseParseInputOnly = $this->forceRecompute
            && $pendingParseInputOnly
            && ! $pendingForceFreshPaidExtraction;

        $useAiVisionExtraction = app(ProviderResolver::class)->parseJobUsesAiVisionExtraction();

        if ($reparseParseInputOnly) {
            if ($useAiVisionExtraction) {
                $canonicalDb = trim((string) ($intake->last_parse_input_text ?? ''));
                $cached = $reuseResolver->getCachedParseInputText((int) $intake->id);
                $canonicalCache = is_string($cached) ? trim($cached) : '';
                $canonical = $canonicalDb !== '' ? $canonicalDb : $canonicalCache;
                $canonicalSourceKey = $canonicalDb !== '' ? 'last_parse_input_text' : ($canonicalCache !== '' ? 'parse_input_text_cache' : null);

                if ($canonical !== '') {
                    $raw = $canonical;
                    $reparseEarlyResolved = true;
                    $reuseResolver->consumeParseInputOnlyFlag((int) $intake->id);
                    $parseInputDebug = [
                        'parse_input_source' => 'canonical_transcript_reparse',
                        'canonical_transcript_source' => $canonicalSourceKey,
                        'parse_input_only_job' => true,
                        'ok' => true,
                        'text_quality_ok' => true,
                        'ai_extraction_skipped' => true,
                        'reason' => 'reparse_uses_stored_canonical_transcript',
                        'provider' => null,
                        'provider_source' => 'not_applicable',
                    ];
                    Cache::put('intake.parse_input_debug.'.$intake->id, $parseInputDebug, now()->addDays(7));
                } elseif (trim((string) ($intake->raw_ocr_text ?? '')) !== '') {
                    $resolved = $ocr->buildParseInputFromDbRawOcr($intake);
                    $raw = $resolved['text'];
                    $reparseEarlyResolved = true;
                    $reuseResolver->consumeParseInputOnlyFlag((int) $intake->id);
                    $parseInputDebug = [
                        'parse_input_source' => 'explicit_fallback_raw_ocr_text',
                        'parse_input_only_job' => true,
                        'fallback_reason' => 'no_canonical_transcript_for_reparse',
                        'ok' => true,
                        'text_quality_ok' => true,
                        'ai_extraction_skipped' => true,
                        'reason' => 'explicit_raw_ocr_fallback_after_missing_canonical',
                        'provider' => null,
                        'provider_source' => 'explicit_fallback',
                    ];
                    Cache::put('intake.parse_input_debug.'.$intake->id, $parseInputDebug, now()->addDays(7));
                } else {
                    $reuseResolver->consumeParseInputOnlyFlag((int) $intake->id);
                    $intake->update([
                        'parse_status' => 'error',
                        'last_error' => 'reparse_no_canonical_or_raw_ocr',
                        'parse_duration_ms' => 0,
                    ]);
                    Cache::put('intake.parse_input_debug.'.$intake->id, [
                        'parse_input_source' => 'reparse_unavailable',
                        'parse_input_only_job' => true,
                        'ok' => false,
                        'reason' => 'no_canonical_transcript_no_raw_ocr',
                    ], now()->addDays(7));

                    return;
                }
            } else {
                if (trim((string) ($intake->raw_ocr_text ?? '')) === '') {
                    $reuseResolver->consumeParseInputOnlyFlag((int) $intake->id);
                    $intake->update([
                        'parse_status' => 'error',
                        'last_error' => 'reparse_no_raw_ocr',
                        'parse_duration_ms' => 0,
                    ]);
                    Cache::put('intake.parse_input_debug.'.$intake->id, [
                        'parse_input_source' => 'reparse_unavailable',
                        'parse_input_only_job' => true,
                        'ok' => false,
                        'reason' => 'no_raw_ocr_text_non_ai_mode',
                    ], now()->addDays(7));

                    return;
                }
                $resolved = $ocr->buildParseInputFromDbRawOcr($intake);
                $raw = $resolved['text'];
                $reparseEarlyResolved = true;
                $reuseResolver->consumeParseInputOnlyFlag((int) $intake->id);
                $parseInputDebug = [
                    'parse_input_source' => 'raw_ocr_text_column',
                    'force_recompute' => true,
                    'parse_input_only_job' => true,
                    'ok' => true,
                    'text_quality_ok' => true,
                ];
                Cache::put('intake.parse_input_debug.'.$intake->id, $parseInputDebug, now()->addDays(7));
            }
        } elseif ($useAiVisionExtraction) {
            $ai = app(AiVisionExtractionService::class);
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
                $parseInputDebug['parse_input_source'] = 'ai_vision_extract_failed';
                $parseInputDebug['ok'] = false;
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
                $parseInputDebug['parse_input_source'] = 'ai_vision_text_quality_failed';
                $parseInputDebug['ok'] = false;
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
        if (! $reparseEarlyResolved
            && $manualPreparedExists
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
                if (config('intake.dob_parse_debug') || IntakeDobTrace::enabled((int) $intake->id)) {
                    $janma = null;
                    if (preg_match('/जन्म\s*तारीख[^\n]*/u', $raw, $jm)) {
                        $janma = $jm[0];
                    }
                    Log::info('DOB_PARSE_INPUT', [
                        'intake_id' => $intake->id,
                        'raw_byte_length' => strlen($raw),
                        'janma_line_snippet' => $janma,
                        'raw_sha256' => hash('sha256', $raw),
                    ]);
                }
                $uploadedBy = $intake->uploaded_by;
                $suggestedByUserId = ($uploadedBy !== null && (int) $uploadedBy > 0) ? (int) $uploadedBy : null;
                $parsed = $parser->parse($raw, [
                    'intake_id' => $intake->id,
                    'parser_mode' => $mode,
                    'suggested_by_user_id' => $suggestedByUserId,
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
                'last_parse_input_text' => null,
            ]);

            Log::error('Intake parse failed', [
                'intake_id' => $intake->id,
                'parser_mode' => $mode,
                'error' => $lastException?->getMessage(),
            ]);

            return;
        }

        // At this point parsers are already required to return SSOT-compatible shape.
        $utf8Stats = [];
        $ssot = app(IntakePipelineService::class)->finalizeParsedSnapshotForStorage($parsed, $utf8Stats);
        if (($utf8Stats['strings_fixed'] ?? 0) > 0) {
            Log::warning('ParseIntakeJob: repaired malformed UTF-8 in parsed_json before save', [
                'intake_id' => $intake->id,
                'strings_fixed' => $utf8Stats['strings_fixed'],
            ]);
        }

        if (IntakeDobTrace::enabled((int) $intake->id)) {
            $core = is_array($ssot['core'] ?? null) ? $ssot['core'] : [];
            Log::info('DOB_TRACE_PRE_SAVE', [
                'intake_id' => $intake->id,
                'final_saved_dob' => $core['date_of_birth'] ?? null,
                'parse_raw_has_janma_taarikh' => preg_match('/जन्म\s*तारीख/u', $raw) === 1,
                'parser_mode' => $mode,
            ]);
        }

        $intake->update([
            'parsed_json' => $ssot,
            'last_parse_input_text' => $raw,
            'parse_status' => 'parsed',
            'parsed_at' => now(),
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

        if (IntakeDobTrace::enabled((int) $intake->id)) {
            $intake->refresh();
            $savedCore = is_array($intake->parsed_json['core'] ?? null) ? $intake->parsed_json['core'] : [];
            Log::info('DOB_TRACE_POST_SAVE', [
                'intake_id' => $intake->id,
                'parsed_json_core_date_of_birth' => $savedCore['date_of_birth'] ?? null,
            ]);
        }
    }
}
