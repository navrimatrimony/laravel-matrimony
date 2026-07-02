<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use Illuminate\Support\Collection;

class IntakeSmartRoutingAdvisor
{
    private const LOW_CONFIDENCE_THRESHOLD = 0.65;

    private const HIGH_QUALITY_THRESHOLD = 0.75;

    public function __construct(
        private readonly IntakeRoutingTelemetryService $telemetry,
        private readonly IntakeBiodataIdentityFingerprint $identityFingerprint,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function recommend(BiodataIntake $intake): array
    {
        $telemetry = $this->telemetry->forIntake($intake);
        $qualitySummary = is_array($intake->quality_summary_json) ? $intake->quality_summary_json : [];
        $failureCodes = $this->stringList($intake->failure_codes_json);
        $fieldConfidence = is_array($intake->field_confidence_json) ? $intake->field_confidence_json : [];
        $lowConfidenceKeys = $this->lowConfidenceKeys($fieldConfidence);
        $qualityScore = $this->nullableFloat($qualitySummary['score'] ?? $telemetry['last_quality_score'] ?? null);
        $layoutScore = $this->nullableFloat($qualitySummary['layout_score'] ?? $telemetry['last_layout_score'] ?? null);
        $hasFile = trim((string) ($intake->file_path ?? '')) !== '';
        $hasCheapOcr = (int) ($telemetry['cheap_ocr_attempt_count'] ?? 0) > 0;
        $providerFailed = (int) ($telemetry['failed_provider_count'] ?? 0) > 0;
        $providerFailureSignaled = $providerFailed || $this->containsAny($failureCodes, [
            BiodataIntakeOcrAttempt::FAILURE_PROVIDER_ERROR,
            BiodataIntakeOcrAttempt::FAILURE_PROVIDER_TIMEOUT,
        ]);

        $reasonCodes = [];
        if (! empty($telemetry['reuse_candidate_found'])) {
            $reasonCodes[] = 'duplicate_detected';

            return $this->payload('reuse_previous', $reasonCodes, 0.9, true, false, $this->signals(
                $intake,
                $telemetry,
                $qualityScore,
                $layoutScore,
                $failureCodes,
                $lowConfidenceKeys,
            ));
        }

        if ($providerFailureSignaled) {
            $reasonCodes[] = 'provider_error';
        }

        if ($this->containsAny($failureCodes, [BiodataIntakeOcrAttempt::FAILURE_EMPTY_TEXT])) {
            $reasonCodes[] = 'empty_text';
        }

        if ($this->containsAny($failureCodes, [
            BiodataIntakeOcrAttempt::FAILURE_PARSER_NO_FIELDS,
            BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED,
        ])) {
            $reasonCodes[] = 'parser_no_fields';
        }

        if ($this->containsAny($failureCodes, [
            BiodataIntakeOcrAttempt::FAILURE_TWO_COLUMN_ORDER_ISSUE,
            BiodataIntakeOcrAttempt::FAILURE_LABEL_VALUE_SPLIT,
        ]) || ($layoutScore !== null && $layoutScore < self::LOW_CONFIDENCE_THRESHOLD)) {
            $reasonCodes[] = 'two_column_layout_suspected';
        }

        if ($lowConfidenceKeys !== []) {
            $reasonCodes[] = 'field_confidence_low';
        }

        $qualityIsHigh = $qualityScore !== null && $qualityScore >= self::HIGH_QUALITY_THRESHOLD;
        $qualityIsLow = $qualityScore !== null && $qualityScore < self::LOW_CONFIDENCE_THRESHOLD;

        if ($hasCheapOcr && $qualityIsHigh && $failureCodes === [] && $lowConfidenceKeys === []) {
            $reasonCodes[] = 'high_quality_cheap_ocr';

            return $this->payload('cheap_ocr_only', array_values(array_unique($reasonCodes)), min(0.95, $qualityScore), true, false, $this->signals(
                $intake,
                $telemetry,
                $qualityScore,
                $layoutScore,
                $failureCodes,
                $lowConfidenceKeys,
            ));
        }

        if ($qualityIsLow) {
            $reasonCodes[] = 'low_quality_cheap_ocr';
        }

        if (in_array('empty_text', $reasonCodes, true)) {
            $action = $hasFile && ! $providerFailureSignaled ? 'call_sarvam' : 'manual_review';

            return $this->payload($action, array_values(array_unique($reasonCodes)), 0.72, false, $action === 'call_sarvam', $this->signals(
                $intake,
                $telemetry,
                $qualityScore,
                $layoutScore,
                $failureCodes,
                $lowConfidenceKeys,
            ));
        }

        if ($providerFailureSignaled) {
            return $this->payload('manual_review', array_values(array_unique($reasonCodes)), 0.68, false, false, $this->signals(
                $intake,
                $telemetry,
                $qualityScore,
                $layoutScore,
                $failureCodes,
                $lowConfidenceKeys,
            ));
        }

        if (array_intersect($reasonCodes, [
            'parser_no_fields',
            'two_column_layout_suspected',
            'field_confidence_low',
            'low_quality_cheap_ocr',
        ]) !== []) {
            return $this->payload('call_sarvam', array_values(array_unique($reasonCodes)), 0.7, false, true, $this->signals(
                $intake,
                $telemetry,
                $qualityScore,
                $layoutScore,
                $failureCodes,
                $lowConfidenceKeys,
            ));
        }

        return $this->payload('unknown', ['no_signal'], 0.0, false, false, $this->signals(
            $intake,
            $telemetry,
            $qualityScore,
            $layoutScore,
            $failureCodes,
            $lowConfidenceKeys,
        ));
    }

    public function storeForIntake(BiodataIntake $intake): BiodataIntake
    {
        if ($intake->intake_locked) {
            return $intake;
        }

        $recommendation = $this->recommend($intake);
        $telemetry = $this->telemetry->forIntake($intake);

        $intake->forceFill([
            'routing_recommendation_json' => $recommendation,
            'routing_telemetry_json' => $telemetry,
        ])->save();

        return $intake->refresh();
    }

    /**
     * @param  list<string>  $reasonCodes
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function payload(
        string $recommendedAction,
        array $reasonCodes,
        float $confidence,
        bool $wouldSkipPaidVision,
        bool $wouldCallPaidVision,
        array $signals,
    ): array {
        return [
            'mode' => 'dry_run',
            'recommended_action' => $recommendedAction,
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'confidence' => round(max(0.0, min(1.0, $confidence)), 4),
            'would_skip_paid_vision' => $wouldSkipPaidVision,
            'would_call_paid_vision' => $wouldCallPaidVision,
            'signals' => $signals,
        ];
    }

    /**
     * @param  array<string, mixed>  $telemetry
     * @param  list<string>  $failureCodes
     * @param  list<string>  $lowConfidenceKeys
     * @return array<string, mixed>
     */
    private function signals(
        BiodataIntake $intake,
        array $telemetry,
        ?float $qualityScore,
        ?float $layoutScore,
        array $failureCodes,
        array $lowConfidenceKeys,
    ): array {
        $attempts = $this->ocrAttempts($intake);
        $qualitySummary = is_array($intake->quality_summary_json) ? $intake->quality_summary_json : [];
        $fieldConfidence = is_array($intake->field_confidence_json) ? $intake->field_confidence_json : [];
        $duplicateSignals = $this->duplicateSignals($intake, $attempts, $telemetry);

        return array_merge([
            'parse_status' => $intake->parse_status,
            'has_file' => trim((string) ($intake->file_path ?? '')) !== '',
            'has_parsed_json' => $this->hasNonEmptyArray($intake->parsed_json),
            'has_raw_ocr_text' => trim((string) ($intake->raw_ocr_text ?? '')) !== '',
            'has_quality_summary' => $qualitySummary !== [],
            'has_field_confidence' => $fieldConfidence !== [],
            'quality_score' => $qualityScore,
            'layout_score' => $layoutScore,
            'failure_codes' => $failureCodes,
            'low_confidence_fields' => $lowConfidenceKeys,
            'ocr_attempt_count' => $attempts->count(),
            'primary_ocr_attempt_exists' => $attempts->contains(
                fn (BiodataIntakeOcrAttempt $attempt): bool => (bool) $attempt->is_primary
            ),
            'cheap_ocr_attempt_count' => $telemetry['cheap_ocr_attempt_count'] ?? 0,
            'sarvam_attempt_count' => $telemetry['sarvam_attempt_count'] ?? 0,
            'failed_provider_count' => $telemetry['failed_provider_count'] ?? 0,
            'reuse_candidate_found' => (bool) ($telemetry['reuse_candidate_found'] ?? false),
            'identity_fingerprint_present' => $this->identityFingerprintPresent($intake),
            'normalized_text_hash_present' => $attempts->contains(
                fn (BiodataIntakeOcrAttempt $attempt): bool => trim((string) ($attempt->normalized_text_hash ?? '')) !== ''
            ),
            'image_hash_present' => $attempts->contains(
                fn (BiodataIntakeOcrAttempt $attempt): bool => trim((string) ($attempt->image_hash ?? '')) !== ''
            ),
        ], $duplicateSignals);
    }

    /**
     * @param  Collection<int, BiodataIntakeOcrAttempt>  $attempts
     * @param  array<string, mixed>  $telemetry
     * @return array<string, mixed>
     */
    private function duplicateSignals(BiodataIntake $intake, Collection $attempts, array $telemetry): array
    {
        $reusedAttempt = $attempts->first(
            fn (BiodataIntakeOcrAttempt $attempt): bool => $attempt->engine === BiodataIntakeOcrAttempt::ENGINE_REUSED_TRANSCRIPT
        );
        $contentHashPeerId = $this->contentHashPeerId($intake);
        $matchedHashType = null;
        $source = null;
        $matchType = null;
        $referenceId = null;

        if ($reusedAttempt instanceof BiodataIntakeOcrAttempt) {
            $source = 'reused_transcript_attempt';
            $matchType = $this->reusedAttemptMatchType($reusedAttempt);
            $referenceId = $this->duplicateReferenceFromAttempt($reusedAttempt, (int) $intake->id) ?? $contentHashPeerId;
            $matchedHashType = $this->matchedHashType($reusedAttempt) ?? ($contentHashPeerId !== null ? 'content_hash' : null);
        } elseif ($contentHashPeerId !== null) {
            $source = 'content_hash';
            $matchType = 'exact_content_hash';
            $referenceId = $contentHashPeerId;
            $matchedHashType = 'content_hash';
        } elseif (! empty($telemetry['reuse_candidate_found'])) {
            $source = 'telemetry';
            $matchType = 'reuse_candidate';
        }

        return [
            'duplicate_signal_source' => $source,
            'duplicate_match_type' => $matchType,
            'duplicate_reference_intake_id' => $referenceId,
            'matched_hash_type' => $matchedHashType,
        ];
    }

    private function contentHashPeerId(BiodataIntake $intake): ?int
    {
        $contentHash = trim((string) ($intake->content_hash ?? ''));
        if ($contentHash === '') {
            return null;
        }

        $peerId = BiodataIntake::query()
            ->whereKeyNot($intake->id)
            ->where('content_hash', $contentHash)
            ->oldest('id')
            ->value('id');

        return $peerId !== null ? (int) $peerId : null;
    }

    private function duplicateReferenceFromAttempt(BiodataIntakeOcrAttempt $attempt, int $currentIntakeId): ?int
    {
        $meta = is_array($attempt->engine_meta_json) ? $attempt->engine_meta_json : [];
        $candidate = $meta['reused_from_intake_id']
            ?? $meta['reused_source_intake_id']
            ?? $meta['source_intake_id']
            ?? null;

        if (! is_numeric($candidate)) {
            return null;
        }

        $referenceId = (int) $candidate;

        return $referenceId > 0 && $referenceId !== $currentIntakeId ? $referenceId : null;
    }

    private function reusedAttemptMatchType(BiodataIntakeOcrAttempt $attempt): string
    {
        $meta = is_array($attempt->engine_meta_json) ? $attempt->engine_meta_json : [];
        $reusedFrom = is_scalar($meta['reused_from'] ?? null) ? trim((string) $meta['reused_from']) : '';

        return $reusedFrom !== '' ? $reusedFrom : 'reused_transcript';
    }

    private function matchedHashType(BiodataIntakeOcrAttempt $attempt): ?string
    {
        if (trim((string) ($attempt->image_hash ?? '')) !== '') {
            return 'image_hash';
        }

        if (trim((string) ($attempt->normalized_text_hash ?? '')) !== '') {
            return 'normalized_text_hash';
        }

        return null;
    }

    private function identityFingerprintPresent(BiodataIntake $intake): bool
    {
        $rawText = (string) ($intake->raw_ocr_text ?? '');
        if (trim($rawText) !== '' && $this->identityFingerprint->fingerprintForProvider('openai', $rawText) !== null) {
            return true;
        }

        $parseInput = (string) ($intake->last_parse_input_text ?? '');

        return trim($parseInput) !== '' && $this->identityFingerprint->fingerprintForProvider('openai', $parseInput) !== null;
    }

    private function hasNonEmptyArray(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }

    /**
     * @return Collection<int, BiodataIntakeOcrAttempt>
     */
    private function ocrAttempts(BiodataIntake $intake): Collection
    {
        if ($intake->relationLoaded('ocrAttempts')) {
            return $intake->ocrAttempts;
        }

        return $intake->ocrAttempts()
            ->latest('id')
            ->get([
                'id',
                'intake_id',
                'engine',
                'normalized_text_hash',
                'image_hash',
                'engine_meta_json',
                'is_primary',
            ]);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needles
     */
    private function containsAny(array $haystack, array $needles): bool
    {
        return array_intersect($haystack, $needles) !== [];
    }

    /**
     * @param  array<string, mixed>  $fieldConfidence
     * @return list<string>
     */
    private function lowConfidenceKeys(array $fieldConfidence): array
    {
        $keys = [];

        foreach ($fieldConfidence as $key => $signal) {
            if (! is_array($signal)) {
                continue;
            }

            $score = $this->nullableFloat($signal['score'] ?? null);
            $present = $signal['present'] ?? null;
            $status = strtolower(trim((string) ($signal['status'] ?? '')));

            if ($score !== null && $score < self::LOW_CONFIDENCE_THRESHOLD) {
                $keys[] = (string) $key;

                continue;
            }

            if ($present === false || in_array($status, ['low', 'missing', 'unknown'], true)) {
                $keys[] = (string) $key;
            }
        }

        return array_values(array_unique($keys));
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 4);
    }
}
