<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleParseInputAssemblerInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;

/**
 * Step 3a skeleton — parse input assembly arrives in Step 3d.
 */
final class OcrEnsembleParseInputAssembler implements OcrEnsembleParseInputAssemblerInterface
{
    public function assemble(FieldResolutionEnvelope $envelope, string $primaryOcrText): string
    {
        // TODO(phase3-3d): structured header + deduplicated primary OCR body.
        unset($envelope);

        return $primaryOcrText;
    }
}
