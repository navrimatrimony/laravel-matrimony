<?php

namespace App\Services\Parsing\Parsers;

use App\Services\Parsing\Contracts\BiodataParserInterface;
use Illuminate\Support\Facades\Log;

/**
 * Hybrid parser: rules-first, then AI can supplement missing sections.
 *
 * Initial implementation is conservative: we currently delegate to rules-only
 * parser to avoid over-eager AI guesses. The class exists so that parser
 * modes can be wired without breaking behaviour, and can be enhanced later
 * to selectively call AI for low-confidence fields.
 */
class HybridBiodataParser implements BiodataParserInterface
{
    public function __construct(
        protected RulesOnlyBiodataParser $rulesParser,
        protected AiFirstBiodataParser $aiParser,
    ) {
    }

    public function parse(string $rawText, array $context = []): array
    {
        // Phase-1: use rules-only parse (deterministic, safe).
        $rules = $this->rulesParser->parse($rawText, $context);

        // For now we do NOT automatically merge AI content, to avoid
        // unexpected mutations; this can be enabled later with strict
        // confidence-aware merge rules.
        Log::info('Hybrid parser currently delegating to rules-only output', [
            'intake_id' => $context['intake_id'] ?? null,
        ]);

        return $rules;
    }
}

