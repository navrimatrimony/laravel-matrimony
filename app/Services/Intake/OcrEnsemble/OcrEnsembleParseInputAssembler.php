<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleParseInputAssemblerInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleParseInputAssemblySupport;
use App\Services\Ocr\OcrPostProcessor;

/**
 * Build parser-facing transcript: resolved structured header + deduplicated OCR body.
 */
final class OcrEnsembleParseInputAssembler implements OcrEnsembleParseInputAssemblerInterface
{
    public function __construct(
        private readonly OcrPostProcessor $ocrPostProcessor,
    ) {}

    public function assemble(FieldResolutionEnvelope $envelope, string $primaryOcrText): string
    {
        $header = $this->buildStructuredHeader($envelope);
        $body = $this->buildRemainingBody($envelope, $primaryOcrText);

        $parts = [];
        if ($header !== '') {
            $parts[] = $header;
        }
        if ($body !== '') {
            $parts[] = $body;
        }

        return trim(implode("\n\n", $parts));
    }

    private function buildStructuredHeader(FieldResolutionEnvelope $envelope): string
    {
        $lines = [];
        foreach (OcrEnsembleParseInputAssemblySupport::HEADER_FIELDS as [$fieldKey, $label]) {
            $value = OcrEnsembleParseInputAssemblySupport::resolvedValue($envelope, $fieldKey);
            if ($value === null) {
                continue;
            }

            $display = OcrEnsembleParseInputAssemblySupport::formatFieldValueForParser($fieldKey, $value);
            if ($display === '') {
                continue;
            }

            $lines[] = $label.' : '.$display;
        }

        return implode("\n", $lines);
    }

    private function buildRemainingBody(FieldResolutionEnvelope $envelope, string $primaryOcrText): string
    {
        $normalized = OcrEnsembleParseInputAssemblySupport::normalizeBodyText($primaryOcrText);
        $normalized = $this->ocrPostProcessor->process($normalized);
        if ($normalized === '') {
            return '';
        }

        $resolvedFieldKeys = [];
        foreach (OcrEnsembleParseInputAssemblySupport::HEADER_FIELDS as [$fieldKey]) {
            if (OcrEnsembleParseInputAssemblySupport::resolvedValue($envelope, $fieldKey) !== null) {
                $resolvedFieldKeys[] = $fieldKey;
            }
        }

        $kept = [];
        foreach (OcrEnsembleParseInputAssemblySupport::lines($normalized) as $line) {
            if ($this->shouldDropBodyLine($line, $resolvedFieldKeys)) {
                continue;
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * @param  list<string>  $resolvedFieldKeys
     */
    private function shouldDropBodyLine(string $line, array $resolvedFieldKeys): bool
    {
        foreach ($resolvedFieldKeys as $fieldKey) {
            if (OcrEnsembleParseInputAssemblySupport::lineMatchesResolvedField($fieldKey, $line)) {
                return true;
            }
        }

        return false;
    }
}
