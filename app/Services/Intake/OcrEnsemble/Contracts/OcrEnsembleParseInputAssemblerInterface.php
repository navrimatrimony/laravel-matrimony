<?php

namespace App\Services\Intake\OcrEnsemble\Contracts;

use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;

interface OcrEnsembleParseInputAssemblerInterface
{
    public function assemble(FieldResolutionEnvelope $envelope, string $primaryOcrText): string;
}
