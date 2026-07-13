<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleFieldVoterInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;

/**
 * Step 3a skeleton — voting logic arrives in Step 3c.
 */
final class OcrEnsembleFieldVoter implements OcrEnsembleFieldVoterInterface
{
    public function voteField(string $fieldKey, array $normalizedByEngine, string $voteMode): FieldResolutionFieldRecord
    {
        // TODO(phase3-3c): single-engine pass-through and future multi-engine vote.
        unset($fieldKey, $normalizedByEngine, $voteMode);

        return FieldResolutionFieldRecord::missingSkeleton('voter_not_implemented_step_3a');
    }
}
