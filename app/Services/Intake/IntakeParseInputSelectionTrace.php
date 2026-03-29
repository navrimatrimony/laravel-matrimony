<?php

namespace App\Services\Intake;

/**
 * Groundwork for future OCR/rules learning: enrich parse_input_debug without new storage.
 * All data stays in existing intake.parse_input_debug.* cache payload.
 */
class IntakeParseInputSelectionTrace
{
    /**
     * @param  array<string, mixed>  $base
     * @param  list<array<string, mixed>>  $candidatesSummary
     * @return array<string, mixed>
     */
    public static function mergeLearningFields(
        array $base,
        ?string $winnerSourceKey,
        ?float $winnerQualityScore,
        ?float $winnerIdentityEvidenceScore,
        ?string $textProvenance,
        array $candidatesSummary,
        bool $forceFreshPaidExtraction,
    ): array {
        $base['learning_capture_v1'] = [
            'winner_source_key' => $winnerSourceKey,
            'winner_quality_score' => $winnerQualityScore,
            'winner_identity_evidence_score' => $winnerIdentityEvidenceScore,
            'text_provenance' => $textProvenance,
            'candidates_count' => count($candidatesSummary),
            'candidates_summary' => array_slice($candidatesSummary, 0, 12),
            'force_fresh_paid_extraction' => $forceFreshPaidExtraction,
            'note' => 'Safe preview/debug only; not used for profile mutation. Extend for analytics/training in a later phase.',
        ];

        return $base;
    }
}
