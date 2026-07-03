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
        private readonly IntakeDuplicateFieldMatchEvaluator $fieldMatchEvaluator,
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
        $signals = $this->signals(
            $intake,
            $telemetry,
            $qualityScore,
            $layoutScore,
            $failureCodes,
            $lowConfidenceKeys,
        );

        $reasonCodes = [];
        if (! empty($signals['duplicate_detected'])) {
            $reasonCodes[] = 'duplicate_detected';

            if (! empty($signals['duplicate_reuse_eligible'])) {
                $reasonCodes[] = 'duplicate_reuse_eligible';

                return $this->payload('reuse_previous', $reasonCodes, 0.9, true, false, $signals);
            }

            if (! empty($signals['duplicate_field_mismatch_codes'])) {
                $reasonCodes[] = 'duplicate_field_mismatch';
            }

            if (! empty($signals['backfilled_quality_not_trusted'])) {
                $reasonCodes[] = 'backfilled_quality_not_trusted';
            } elseif (($signals['duplicate_reference_has_verifiable_ocr_evidence'] ?? null) === false) {
                $reasonCodes[] = 'reference_lacks_verifiable_ocr_evidence';
            }

            $reasonCodes[] = 'duplicate_detected_but_untrusted';

            return $this->payload('manual_review', $reasonCodes, 0.42, false, false, $signals);
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

            return $this->payload('cheap_ocr_only', array_values(array_unique($reasonCodes)), min(0.95, $qualityScore), true, false, $signals);
        }

        if ($qualityIsLow) {
            $reasonCodes[] = 'low_quality_cheap_ocr';
        }

        if (in_array('empty_text', $reasonCodes, true)) {
            $action = $hasFile && ! $providerFailureSignaled ? 'call_sarvam' : 'manual_review';

            return $this->payload($action, array_values(array_unique($reasonCodes)), 0.72, false, $action === 'call_sarvam', $signals);
        }

        if ($providerFailureSignaled) {
            return $this->payload('manual_review', array_values(array_unique($reasonCodes)), 0.68, false, false, $signals);
        }

        if (array_intersect($reasonCodes, [
            'parser_no_fields',
            'two_column_layout_suspected',
            'field_confidence_low',
            'low_quality_cheap_ocr',
        ]) !== []) {
            return $this->payload('call_sarvam', array_values(array_unique($reasonCodes)), 0.7, false, true, $signals);
        }

        return $this->payload('unknown', $this->noSignalReasonCodes($signals), 0.0, false, false, $signals);
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
            'content_hash_present' => trim((string) ($intake->content_hash ?? '')) !== '',
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
        $base = [
            'duplicate_detected' => false,
            'duplicate_reuse_eligible' => false,
            'duplicate_reuse_trust' => 'unknown',
            'duplicate_signal_source' => null,
            'duplicate_match_type' => null,
            'duplicate_reference_intake_id' => null,
            'matched_hash_type' => null,
            'duplicate_reference_has_parsed_json' => false,
            'duplicate_reference_has_reviewed_snapshot' => false,
            'duplicate_reference_has_primary_ocr_attempt' => false,
            'duplicate_reference_has_sarvam_attempt' => false,
            'duplicate_reference_has_verifiable_ocr_evidence' => false,
            'duplicate_reference_quality_source' => 'unknown',
            'duplicate_reference_ocr_attempt_count' => null,
            'duplicate_reference_sarvam_attempt_count' => null,
            'backfilled_quality_not_trusted' => false,
            'duplicate_reference_quality_score' => null,
            'duplicate_reference_locked' => null,
            'duplicate_reference_is_self_or_circular' => false,
            'duplicate_reference_reason' => null,
        ] + $this->fieldMatchEvaluator->emptyEvaluation();
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

        if ($source === null) {
            return $base;
        }

        $trustSignals = $this->duplicateReferenceTrustSignals(
            $intake,
            $referenceId,
            $source,
            $matchType,
            $reusedAttempt,
        );

        return array_merge($base, [
            'duplicate_detected' => true,
            'duplicate_signal_source' => $source,
            'duplicate_match_type' => $matchType,
            'duplicate_reference_intake_id' => $referenceId,
            'matched_hash_type' => $matchedHashType,
        ], $trustSignals);
    }

    private function contentHashPeerId(BiodataIntake $intake): ?int
    {
        $contentHash = trim((string) ($intake->content_hash ?? ''));
        if ($contentHash === '') {
            return null;
        }

        $baseQuery = BiodataIntake::query()
            ->whereKeyNot($intake->id)
            ->where('content_hash', $contentHash);

        $peerId = (clone $baseQuery)
            ->where('id', '<', (int) $intake->id)
            ->latest('id')
            ->value('id');

        if ($peerId === null) {
            $peerId = (clone $baseQuery)
                ->oldest('id')
                ->value('id');
        }

        return $peerId !== null ? (int) $peerId : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function duplicateReferenceTrustSignals(
        BiodataIntake $intake,
        ?int $referenceId,
        ?string $source,
        ?string $matchType,
        ?BiodataIntakeOcrAttempt $reusedAttempt,
    ): array {
        $currentId = (int) $intake->id;
        if ($referenceId === null) {
            return [
                'duplicate_reuse_trust' => 'missing_reference',
                'duplicate_reference_reason' => 'duplicate_signal_without_reference',
            ];
        }

        $reference = BiodataIntake::query()
            ->whereKey($referenceId)
            ->first([
                'id',
                'parsed_json',
                'approval_snapshot_json',
                'raw_ocr_text',
                'quality_summary_json',
                'routing_recommendation_json',
                'intake_locked',
            ]);

        if (! $reference instanceof BiodataIntake) {
            return [
                'duplicate_reuse_trust' => 'missing_reference',
                'duplicate_reference_reason' => 'reference_intake_missing',
            ];
        }

        $referenceAttempts = $reference->ocrAttempts()
            ->get([
                'id',
                'engine',
                'status',
                'quality_score',
                'normalized_text_hash',
                'image_hash',
                'is_primary',
            ]);
        $referenceHasParsedJson = $this->hasNonEmptyArray($reference->parsed_json);
        $referenceHasRawOcrText = trim((string) ($reference->raw_ocr_text ?? '')) !== '';
        $referenceHasReviewedSnapshot = $this->hasNonEmptyArray($reference->approval_snapshot_json);
        $referenceOcrAttemptCount = $referenceAttempts->count();
        $referenceSarvamAttemptCount = $referenceAttempts
            ->where('engine', BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION)
            ->count();
        $referenceHasPrimaryOcr = $referenceAttempts->contains(
            fn (BiodataIntakeOcrAttempt $attempt): bool => (bool) $attempt->is_primary
        );
        $referenceHasSarvam = $referenceAttempts->contains(
            fn (BiodataIntakeOcrAttempt $attempt): bool => $attempt->engine === BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION
                && $attempt->status === BiodataIntakeOcrAttempt::STATUS_SUCCESS
        );
        $referenceQualityScore = $this->referenceQualityScore($reference, $referenceAttempts);
        $referenceAttemptQualityScore = $this->referenceAttemptQualityScore($referenceAttempts);
        $referenceHasAcceptableQualityAttempt = $referenceAttemptQualityScore !== null
            && $referenceAttemptQualityScore >= self::HIGH_QUALITY_THRESHOLD;
        $referenceHasVerifiableEvidence = $referenceHasReviewedSnapshot
            || $referenceHasPrimaryOcr
            || $referenceHasSarvam
            || $referenceHasAcceptableQualityAttempt;
        $referenceQualitySource = $this->referenceQualitySource(
            $reference,
            $referenceHasReviewedSnapshot,
            $referenceHasPrimaryOcr,
            $referenceHasSarvam,
            $referenceHasAcceptableQualityAttempt,
        );
        $backfilledQualityNotTrusted = $referenceQualitySource === 'backfilled'
            && ! $referenceHasVerifiableEvidence;
        $referenceIsSelfOrCircular = $referenceId === $currentId
            || $referenceId > $currentId
            || $this->referencePointsBackToCurrent($reference, $currentId);
        $fieldMatch = $this->fieldMatchEvaluator->evaluate($intake, $reference);

        $signals = [
            'duplicate_reference_has_parsed_json' => $referenceHasParsedJson,
            'duplicate_reference_has_reviewed_snapshot' => $referenceHasReviewedSnapshot,
            'duplicate_reference_has_primary_ocr_attempt' => $referenceHasPrimaryOcr,
            'duplicate_reference_has_sarvam_attempt' => $referenceHasSarvam,
            'duplicate_reference_has_verifiable_ocr_evidence' => $referenceHasVerifiableEvidence,
            'duplicate_reference_quality_source' => $referenceQualitySource,
            'duplicate_reference_ocr_attempt_count' => $referenceOcrAttemptCount,
            'duplicate_reference_sarvam_attempt_count' => $referenceSarvamAttemptCount,
            'backfilled_quality_not_trusted' => $backfilledQualityNotTrusted,
            'duplicate_reference_quality_score' => $referenceQualityScore,
            'duplicate_reference_locked' => (bool) $reference->intake_locked,
            'duplicate_reference_is_self_or_circular' => $referenceIsSelfOrCircular,
        ] + $fieldMatch;

        if ($referenceIsSelfOrCircular) {
            return array_merge($signals, [
                'duplicate_reuse_trust' => 'circular',
                'duplicate_reference_reason' => $referenceId === $currentId
                    ? 'self_reference'
                    : 'reference_is_not_previous_or_points_back',
            ]);
        }

        if (! $referenceHasParsedJson && ! $referenceHasReviewedSnapshot) {
            return array_merge($signals, [
                'duplicate_reuse_trust' => 'weak',
                'duplicate_reference_reason' => 'reference_missing_parsed_or_reviewed_snapshot',
            ]);
        }

        $hasParsedJsonWithStrongOcrEvidence = $referenceHasParsedJson && (
            $referenceHasPrimaryOcr
            || $referenceHasSarvam
            || $referenceHasAcceptableQualityAttempt
        );
        $hasSafeExistingReuseEvidence = $source === 'reused_transcript_attempt'
            && $reusedAttempt instanceof BiodataIntakeOcrAttempt
            && in_array($matchType, [
                'identity_fingerprint_cache',
                'historical_paid_transcript',
                'intake_parse_input_cache',
                'duplicate_upload_reused_paid_transcript',
                'duplicate_upload_reused_raw_ocr_text',
                'reused_transcript',
            ], true);

        if ($referenceHasReviewedSnapshot) {
            return $this->trustedDuplicateSignals($signals, 'reference_has_reviewed_snapshot');
        }

        if ($hasParsedJsonWithStrongOcrEvidence) {
            return $this->trustedDuplicateSignals($signals, 'reference_parsed_with_strong_ocr_evidence');
        }

        if ($hasSafeExistingReuseEvidence && $referenceHasVerifiableEvidence) {
            return $this->trustedDuplicateSignals($signals, 'safe_existing_reuse_evidence');
        }

        if (
            $referenceHasParsedJson
            && $referenceHasRawOcrText
            && $referenceQualityScore !== null
            && $referenceOcrAttemptCount === 0
            && $referenceSarvamAttemptCount === 0
            && ! $referenceHasReviewedSnapshot
        ) {
            return array_merge($signals, [
                'duplicate_reuse_trust' => 'weak',
                'duplicate_reference_reason' => 'reference_parsed_with_backfilled_quality_only',
            ]);
        }

        return array_merge($signals, [
            'duplicate_reuse_trust' => 'weak',
            'duplicate_reference_reason' => $referenceHasVerifiableEvidence
                ? 'reference_lacks_trusted_evidence'
                : 'reference_lacks_verifiable_ocr_evidence',
        ]);
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function trustedDuplicateSignals(array $signals, string $trustedReason): array
    {
        if (! empty($signals['duplicate_field_match_eligible'])) {
            return array_merge($signals, [
                'duplicate_reuse_eligible' => true,
                'duplicate_reuse_trust' => 'trusted',
                'duplicate_reference_reason' => $trustedReason,
            ]);
        }

        return array_merge($signals, [
            'duplicate_reuse_eligible' => false,
            'duplicate_reuse_trust' => 'field_mismatch',
            'duplicate_reference_reason' => 'duplicate_field_match_failed',
        ]);
    }

    /**
     * @param  Collection<int, BiodataIntakeOcrAttempt>  $attempts
     */
    private function referenceQualityScore(BiodataIntake $reference, Collection $attempts): ?float
    {
        $qualitySummary = is_array($reference->quality_summary_json) ? $reference->quality_summary_json : [];
        $summaryScore = $this->nullableFloat($qualitySummary['score'] ?? null);
        if ($summaryScore !== null) {
            return $summaryScore;
        }

        return $this->referenceAttemptQualityScore($attempts);
    }

    /**
     * @param  Collection<int, BiodataIntakeOcrAttempt>  $attempts
     */
    private function referenceAttemptQualityScore(Collection $attempts): ?float
    {
        $attempt = $attempts->first(
            fn (BiodataIntakeOcrAttempt $attempt): bool => $attempt->quality_score !== null
        );

        return $this->nullableFloat($attempt?->quality_score);
    }

    private function referenceQualitySource(
        BiodataIntake $reference,
        bool $referenceHasReviewedSnapshot,
        bool $referenceHasPrimaryOcr,
        bool $referenceHasSarvam,
        bool $referenceHasAcceptableQualityAttempt,
    ): string
    {
        if ($referenceHasReviewedSnapshot) {
            return 'reviewed';
        }

        if ($referenceHasPrimaryOcr || $referenceHasSarvam || $referenceHasAcceptableQualityAttempt) {
            return 'attempt';
        }

        $qualitySummary = is_array($reference->quality_summary_json) ? $reference->quality_summary_json : [];

        return $this->nullableFloat($qualitySummary['score'] ?? null) !== null ? 'backfilled' : 'unknown';
    }

    private function referencePointsBackToCurrent(BiodataIntake $reference, int $currentId): bool
    {
        $recommendation = is_array($reference->routing_recommendation_json)
            ? $reference->routing_recommendation_json
            : [];
        $signals = is_array($recommendation['signals'] ?? null) ? $recommendation['signals'] : [];
        $referencePointsTo = $signals['duplicate_reference_intake_id'] ?? null;

        return is_numeric($referencePointsTo) && (int) $referencePointsTo === $currentId;
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

        return $referenceId > 0 ? $referenceId : null;
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
     * @param  array<string, mixed>  $signals
     * @return list<string>
     */
    private function noSignalReasonCodes(array $signals): array
    {
        $reasonCodes = ['no_signal'];
        $hasParsedJson = (bool) ($signals['has_parsed_json'] ?? false);
        $hasRawOcrText = (bool) ($signals['has_raw_ocr_text'] ?? false);

        if (! $hasParsedJson || ! $hasRawOcrText) {
            return $reasonCodes;
        }

        if (empty($signals['has_quality_summary']) && ($signals['quality_score'] ?? null) === null) {
            $reasonCodes[] = 'legacy_intake_missing_quality_signals';
        }

        if ((int) ($signals['ocr_attempt_count'] ?? 0) === 0) {
            $reasonCodes[] = 'legacy_intake_missing_ocr_attempts';
        }

        if (
            empty($signals['content_hash_present'])
            && empty($signals['normalized_text_hash_present'])
            && empty($signals['image_hash_present'])
        ) {
            $reasonCodes[] = 'legacy_intake_missing_hashes';
        }

        return array_values(array_unique($reasonCodes));
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
