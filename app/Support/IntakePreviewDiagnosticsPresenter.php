<?php

namespace App\Support;

use App\Models\BiodataIntake;

/**
 * Human-readable labels for intake preview APP_DEBUG diagnostics (presentation only).
 *
 * @phpstan-type DiagnosticsSummary array{
 *   parser_mode_label: string,
 *   autofill_source_label: string,
 *   ai_provider_label: string,
 *   transcript_used_label: string,
 *   fallback_used_label: string,
 *   fallback_reason_label: string,
 *   recommended_action_label: string,
 *   internal_parse_input_source: string|null,
 *   internal_active_parser_mode: string|null,
 * }
 */
final class IntakePreviewDiagnosticsPresenter
{
    /**
     * @param  array<string, mixed>  $ocrDebugMeta  Merged preview OCR debug payload from {@see \App\Http\Controllers\IntakeController::buildPreviewOcrDebugMeta()}.
     * @return array{summary: DiagnosticsSummary, technical_note: string}
     */
    public static function summarize(BiodataIntake $intake, array $ocrDebugMeta): array
    {
        $rawSource = isset($ocrDebugMeta['parse_input_source']) ? (string) $ocrDebugMeta['parse_input_source'] : '';
        $activeMode = isset($ocrDebugMeta['active_parser_mode']) ? (string) $ocrDebugMeta['active_parser_mode'] : '';
        $provider = strtolower(trim((string) ($ocrDebugMeta['parse_input_provider'] ?? '')));
        $providerSource = strtolower(trim((string) ($ocrDebugMeta['parse_input_provider_source'] ?? '')));
        $parseOk = ! empty($ocrDebugMeta['parse_input_ok']);
        $aiSkipped = ! empty($ocrDebugMeta['parse_input_ai_extraction_skipped']);
        $canonicalSrc = (string) ($ocrDebugMeta['parse_input_canonical_transcript_source'] ?? '');
        $fallbackReason = (string) ($ocrDebugMeta['parse_input_fallback_reason'] ?? '');
        $extractionReused = $ocrDebugMeta['parse_input_extraction_reused'] ?? null;
        $paidCalled = ! empty($ocrDebugMeta['parse_input_paid_extraction_api_called']);
        $manualPrepared = ! empty($ocrDebugMeta['parse_uses_manual_prepared']);
        $ocrEffective = (string) ($ocrDebugMeta['ocr_source_type_effective'] ?? '');

        $parserModeLabel = self::mapParserModeLabel($activeMode);
        $aiProviderLabel = self::mapAiProviderLabel($provider, $providerSource, $rawSource, $parseOk, $aiSkipped);

        $transcriptLabel = self::mapTranscriptUsed(
            $rawSource,
            $manualPrepared,
            $canonicalSrc,
            $paidCalled,
            $extractionReused,
            $fallbackReason,
            $ocrEffective,
        );

        $autofillLabel = self::mapAutofillSource(
            $rawSource,
            $provider,
            $manualPrepared,
            $parseOk,
            $aiSkipped,
        );

        [$fallbackYes, $fallbackReasonLabel] = self::mapFallback(
            $rawSource,
            $fallbackReason,
            $parseOk,
            $ocrDebugMeta,
        );

        $recommended = self::mapRecommendedAction(
            $rawSource,
            $ocrDebugMeta,
            $intake,
        );

        $summary = [
            'parser_mode_label' => $parserModeLabel,
            'autofill_source_label' => $autofillLabel,
            'ai_provider_label' => $aiProviderLabel,
            'transcript_used_label' => $transcriptLabel,
            'fallback_used_label' => $fallbackYes ? __('intake.diagnostics_yes') : __('intake.diagnostics_no'),
            'fallback_reason_label' => $fallbackReasonLabel,
            'recommended_action_label' => $recommended,
            'internal_parse_input_source' => $rawSource !== '' ? $rawSource : null,
            'internal_active_parser_mode' => $activeMode !== '' ? $activeMode : null,
        ];

        $technicalNote = __('intake.diagnostics_technical_note');

        return [
            'summary' => $summary,
            'technical_note' => $technicalNote,
        ];
    }

    private static function mapParserModeLabel(string $mode): string
    {
        $m = str_replace(' ', '_', strtolower(trim($mode)));

        return match ($m) {
            'ai_vision_extract_v1' => __('intake.diagnostics_parser_ai_vision'),
            'rules_only', 'rules_v1' => __('intake.diagnostics_parser_ocr_rules'),
            'ai_first_v1', 'ai_first_v2' => __('intake.diagnostics_parser_ai_first'),
            'hybrid_v1' => __('intake.diagnostics_parser_hybrid'),
            '' => __('intake.diagnostics_not_available'),
            default => $mode,
        };
    }

    private static function mapAiProviderLabel(string $provider, string $providerSource, string $rawSource, bool $parseOk, bool $aiSkipped): string
    {
        if ($rawSource === 'canonical_transcript_reparse' || ($aiSkipped && str_contains($rawSource, 'reparse'))) {
            return __('intake.diagnostics_ai_provider_not_used_reparse');
        }

        if ($rawSource === 'explicit_fallback_raw_ocr_text' || $rawSource === 'raw_ocr_text_column') {
            return __('intake.diagnostics_ai_provider_not_used_ocr');
        }

        if (in_array($rawSource, ['ai_vision_extract_failed', 'ai_vision_text_quality_failed', 'reparse_unavailable'], true)) {
            return __('intake.diagnostics_ai_provider_not_used_failed');
        }

        if ($provider === 'sarvam') {
            return __('intake.diagnostics_provider_sarvam');
        }
        if ($provider === 'openai') {
            return __('intake.diagnostics_provider_openai');
        }

        if ($provider === '' && ! $parseOk) {
            return __('intake.diagnostics_ai_provider_did_not_run');
        }

        if ($provider === '' || $provider === 'not_applicable' || $providerSource === 'not_applicable') {
            return __('intake.diagnostics_not_used');
        }

        return ucfirst($provider);
    }

    /**
     * High-level “who produced the autofill text” for the summary line.
     */
    private static function mapAutofillSource(
        string $rawSource,
        string $provider,
        bool $manualPrepared,
        bool $parseOk,
        bool $aiSkipped,
    ): string {
        if ($manualPrepared) {
            return __('intake.diagnostics_autofill_manual_crop_ocr');
        }

        if ($rawSource === 'canonical_transcript_reparse') {
            return __('intake.diagnostics_autofill_saved_transcript');
        }

        if ($rawSource === 'explicit_fallback_raw_ocr_text') {
            return __('intake.diagnostics_autofill_raw_ocr');
        }

        if ($rawSource === 'raw_ocr_text_column') {
            return __('intake.diagnostics_autofill_ocr_only');
        }

        if (in_array($rawSource, ['ai_vision_extract_v1'], true) && $parseOk) {
            if ($provider === 'sarvam') {
                return __('intake.diagnostics_autofill_sarvam_ai');
            }
            if ($provider === 'openai') {
                return __('intake.diagnostics_autofill_openai_ai');
            }

            return __('intake.diagnostics_autofill_ai_vision');
        }

        if ($rawSource === 'ai_vision_extract_failed' || $rawSource === 'ai_vision_text_quality_failed') {
            return __('intake.diagnostics_autofill_not_available_failed');
        }

        if ($rawSource === '' || $aiSkipped) {
            return __('intake.diagnostics_autofill_ocr_only');
        }

        return __('intake.diagnostics_autofill_see_technical');
    }

    /**
     * @param  bool|mixed  $extractionReused
     */
    private static function mapTranscriptUsed(
        string $rawSource,
        bool $manualPrepared,
        string $canonicalSrc,
        bool $paidCalled,
        $extractionReused,
        string $fallbackReason,
        string $ocrEffective,
    ): string {
        if ($manualPrepared && str_contains($ocrEffective, 'manual')) {
            return __('intake.diagnostics_transcript_manual_cropped_ocr');
        }

        if ($rawSource === 'canonical_transcript_reparse') {
            if ($canonicalSrc === 'last_parse_input_text') {
                return __('intake.diagnostics_transcript_saved_db');
            }
            if ($canonicalSrc === 'parse_input_text_cache') {
                return __('intake.diagnostics_transcript_saved_cache');
            }

            return __('intake.diagnostics_transcript_saved_reparse');
        }

        if ($rawSource === 'explicit_fallback_raw_ocr_text') {
            return __('intake.diagnostics_transcript_raw_ocr_fallback');
        }

        if ($rawSource === 'raw_ocr_text_column') {
            return __('intake.diagnostics_transcript_raw_ocr_text');
        }

        if ($rawSource === 'ai_vision_extract_v1') {
            if ($paidCalled) {
                return __('intake.diagnostics_transcript_fresh_ai');
            }
            if ($extractionReused === true) {
                return __('intake.diagnostics_transcript_saved_reused');
            }

            return __('intake.diagnostics_transcript_ai_vision');
        }

        if (in_array($rawSource, ['ai_vision_extract_failed', 'ai_vision_text_quality_failed'], true)) {
            return __('intake.diagnostics_transcript_did_not_run');
        }

        if ($fallbackReason !== '') {
            return __('intake.diagnostics_transcript_see_fallback');
        }

        return __('intake.diagnostics_transcript_unknown');
    }

    /**
     * @param  array<string, mixed>  $ocrDebugMeta
     * @return array{0: bool, 1: string}
     */
    private static function mapFallback(string $rawSource, string $fallbackReason, bool $parseOk, array $ocrDebugMeta): array
    {
        if ($fallbackReason === 'no_canonical_transcript_for_reparse') {
            return [true, __('intake.diagnostics_fallback_no_saved_transcript')];
        }

        if ($rawSource === 'explicit_fallback_raw_ocr_text') {
            return [true, __('intake.diagnostics_fallback_used_raw_ocr')];
        }

        if (in_array($rawSource, ['ai_vision_extract_failed', 'ai_vision_text_quality_failed'], true)) {
            return [true, __('intake.diagnostics_fallback_ai_failed')];
        }

        if (! $parseOk && $rawSource !== '') {
            return [true, __('intake.diagnostics_fallback_ai_extract_not_ok')];
        }

        $ocrFb = ! empty($ocrDebugMeta['fallback_used']);
        if ($ocrFb) {
            return [true, __('intake.diagnostics_fallback_image_preprocess')];
        }

        return [false, __('intake.diagnostics_fallback_none')];
    }

    /**
     * @param  array<string, mixed>  $ocrDebugMeta
     */
    private static function mapRecommendedAction(string $rawSource, array $ocrDebugMeta, BiodataIntake $intake): string
    {
        if ($rawSource === 'canonical_transcript_reparse') {
            return __('intake.diagnostics_action_reparse_if_fields_wrong');
        }

        if (in_array($rawSource, ['explicit_fallback_raw_ocr_text', 'ai_vision_extract_failed', 'ai_vision_text_quality_failed'], true)) {
            return __('intake.diagnostics_action_reextract');
        }

        if ($intake->parse_status === 'parsed' && $rawSource === 'ai_vision_extract_v1') {
            $low = ! empty($ocrDebugMeta['ocr_quality']['is_low']);

            return $low
                ? __('intake.diagnostics_action_manual_crop_or_reextract')
                : __('intake.diagnostics_action_approve_after_review');
        }

        $low = ! empty($ocrDebugMeta['ocr_quality']['is_low']);
        if ($low) {
            return __('intake.diagnostics_action_manual_crop_or_reparse');
        }

        if ($rawSource === 'raw_ocr_text_column') {
            return __('intake.diagnostics_action_manual_crop_or_reparse');
        }

        if ($intake->parse_status === 'error') {
            return __('intake.diagnostics_action_check_status_reextract');
        }

        return __('intake.diagnostics_action_review_preview');
    }
}
