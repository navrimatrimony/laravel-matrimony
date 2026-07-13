<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleFieldVoterInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleFieldVoteSupport;

/**
 * Lightweight eligibility filtering and engine selection before validator.
 */
final class OcrEnsembleFieldVoter implements OcrEnsembleFieldVoterInterface
{
    public function voteField(string $fieldKey, array $normalizedByEngine, string $voteMode): FieldResolutionFieldRecord
    {
        $eligible = OcrEnsembleFieldVoteSupport::filterEligible($fieldKey, $normalizedByEngine);
        $winner = OcrEnsembleFieldVoteSupport::pickWinner($fieldKey, $eligible, $voteMode);

        if ($winner['engine'] === null || $winner['value'] === null) {
            return new FieldResolutionFieldRecord(
                final: null,
                status: OcrEnsemblePhase3Constants::FIELD_STATUS_MISSING,
                source: OcrEnsemblePhase3Constants::FIELD_SOURCE_MISSING,
                winningEngine: null,
                confidence: null,
                reason: $winner['reason'],
                candidates: $this->candidateMap($normalizedByEngine),
                normalized: $normalizedByEngine,
                validator: [
                    'passed' => false,
                    'code' => 'pending_validation',
                    'detail' => null,
                ],
            );
        }

        $source = $voteMode === OcrEnsemblePhase3Constants::VOTE_MODE_SINGLE_ENGINE_PASS_THROUGH
            ? OcrEnsemblePhase3Constants::FIELD_SOURCE_SINGLE_ENGINE
            : OcrEnsemblePhase3Constants::FIELD_SOURCE_VOTE;

        return new FieldResolutionFieldRecord(
            final: $winner['value'],
            status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            source: $source,
            winningEngine: $winner['engine'],
            confidence: null,
            reason: $winner['reason'],
            candidates: $this->candidateMap($normalizedByEngine),
            normalized: $normalizedByEngine,
            validator: [
                'passed' => false,
                'code' => 'pending_validation',
                'detail' => null,
            ],
        );
    }

    /**
     * @param  array<string, string|null>  $normalizedByEngine
     * @return array<string, string|null>
     */
    private function candidateMap(array $normalizedByEngine): array
    {
        $candidates = [];
        foreach ($normalizedByEngine as $engineKey => $value) {
            if (is_string($engineKey)) {
                $candidates[$engineKey] = $value;
            }
        }

        return $candidates;
    }
}
