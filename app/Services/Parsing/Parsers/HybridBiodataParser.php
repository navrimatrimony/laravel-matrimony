<?php

namespace App\Services\Parsing\Parsers;

use App\Services\Parsing\Contracts\BiodataParserInterface;

/**
 * Hybrid processing mode: parse input text comes from admin-configured extraction (AI or Tesseract);
 * structured JSON uses admin-configured parser provider (OpenAI or Sarvam chat) via the same v2 path
 * as AI-first, including rules merge safeguards inside AiFirstBiodataParser.
 */
class HybridBiodataParser implements BiodataParserInterface
{
    public function __construct(
        protected AiFirstBiodataParser $aiParser,
    ) {}

    public function parse(string $rawText, array $context = []): array
    {
        return $this->aiParser->parse($rawText, array_merge($context, [
            'parser_mode' => 'ai_first_v2',
        ]));
    }
}
