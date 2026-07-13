<?php

namespace App\Services\Intake\OcrEnsemble\Contracts;

use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;

interface OcrEnsembleFieldVoterInterface
{
    /**
     * @param  array<string, string|null>  $normalizedByEngine
     */
    public function voteField(string $fieldKey, array $normalizedByEngine, string $voteMode): FieldResolutionFieldRecord;
}
