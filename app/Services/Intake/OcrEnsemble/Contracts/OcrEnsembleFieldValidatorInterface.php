<?php

namespace App\Services\Intake\OcrEnsemble\Contracts;

interface OcrEnsembleFieldValidatorInterface
{
    /**
     * @param  array<string, string|null>  $normalizedByEngine
     * @return array{passed: bool, code: string, detail: string|null, winning_engine: string|null, final: string|null}
     */
    public function validateField(string $fieldKey, array $normalizedByEngine): array;
}
