<?php

namespace App\Services;

use App\Models\BiodataIntake;
use App\Services\Parsing\ParserStrategyResolver;

/**
 * Thin facade over the parsing strategy layer.
 *
 * Used wherever we want to parse a single intake on-demand (eg. admin tools)
 * without duplicating strategy selection logic.
 *
 * IMPORTANT:
 * - Reads raw_ocr_text only.
 * - Does NOT modify the intake itself or any profile/lifecycle state.
 */
class ParseService
{
    public function __construct(
        protected ParserStrategyResolver $strategyResolver,
    ) {}

    /**
     * Parse raw OCR text for the given intake into a SSOT-compatible array.
     *
     * @return array Parsed JSON in final SSOT shape expected by preview.
     */
    public function parse(BiodataIntake $intake): array
    {
        $parser = $this->strategyResolver->resolveForIntake($intake);

        // Context can carry parser_version or other hints if needed later.
        return $parser->parse($intake->raw_ocr_text ?? '', [
            'intake_id' => $intake->id,
            'parser_version' => $intake->parser_version,
        ]);
    }
}
