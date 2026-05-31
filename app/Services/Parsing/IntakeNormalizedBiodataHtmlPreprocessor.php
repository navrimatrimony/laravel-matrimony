<?php

declare(strict_types=1);

namespace App\Services\Parsing;

use App\Services\BiodataParserService;

/**
 * Phase 3d-1: flatten HTML/table biodata for normalized draft line parsing.
 * Extracts DOM hints for draft meta only — hint applier is a later phase.
 */
final class IntakeNormalizedBiodataHtmlPreprocessor
{
    /**
     * @return array{
     *     text: string,
     *     table_hints: array<string, string>,
     *     has_structured_table: bool,
     *     post_table_body: string|null
     * }
     */
    public function prepare(string $rawText): array
    {
        $rawText = preg_replace('/^\x{FEFF}/u', '', $rawText) ?? $rawText;

        if (stripos($rawText, '<table') === false) {
            return [
                'text' => $rawText,
                'table_hints' => [],
                'has_structured_table' => false,
                'post_table_body' => null,
            ];
        }

        $tableHints = HtmlMarathiBiodataTableExtractor::extract($rawText);
        $flattened = BiodataParserService::flattenHtmlTableForBiodata($rawText);
        $text = BiodataParserService::stripIntakeHtmlNoise($flattened);

        return [
            'text' => $text,
            'table_hints' => $tableHints,
            'has_structured_table' => true,
            'post_table_body' => $this->extractPostTableBody($rawText),
        ];
    }

    /**
     * Non-table HTML/text after the last </table> (e.g. print-shop footer lines).
     * Phase 3d-1 stores only; footer phone filtering is a later phase.
     */
    private function extractPostTableBody(string $rawText): ?string
    {
        if (stripos($rawText, '<table') === false) {
            return null;
        }

        $rest = preg_replace('/<table\b[^>]*>.*?<\/table>/is', '', $rawText) ?? $rawText;
        $rest = BiodataParserService::stripIntakeHtmlNoise($rest);
        $rest = trim($rest);

        return $rest !== '' ? $rest : null;
    }
}
