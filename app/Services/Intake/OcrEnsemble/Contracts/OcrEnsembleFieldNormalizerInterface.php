<?php

namespace App\Services\Intake\OcrEnsemble\Contracts;

interface OcrEnsembleFieldNormalizerInterface
{
    /**
     * @param  array<string, string|null>  $candidatesByEngine
     * @return array<string, string|null>
     */
    public function normalizeField(string $fieldKey, array $candidatesByEngine): array;
}
