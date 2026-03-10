<?php

namespace App\Services\Parsing\Contracts;

/**
 * Contract for all biodata parsers (rules-only, AI-first, hybrid).
 *
 * All implementations MUST return the same SSOT-compatible parsed_json shape.
 */
interface BiodataParserInterface
{
    /**
     * @param  string  $rawText  Raw OCR text from biodata_intakes.raw_ocr_text
     * @param  array   $context  Optional context (eg. intake_id, parser_version)
     * @return array             Parsed JSON in final SSOT shape expected by preview.
     */
    public function parse(string $rawText, array $context = []): array;
}

