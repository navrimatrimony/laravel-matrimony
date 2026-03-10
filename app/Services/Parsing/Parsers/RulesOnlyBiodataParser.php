<?php

namespace App\Services\Parsing\Parsers;

use App\Services\BiodataParserService;
use App\Services\Parsing\Contracts\BiodataParserInterface;

/**
 * Rules-only parser.
 *
 * Wraps the existing BiodataParserService logic and ensures
 * the returned payload is in final SSOT-compatible shape.
 */
class RulesOnlyBiodataParser implements BiodataParserInterface
{
    public function __construct(
        protected BiodataParserService $inner,
    ) {
    }

    public function parse(string $rawText, array $context = []): array
    {
        // Existing BiodataParserService already normalizes + splits + extracts.
        // It also attempts to return a SSOT-like shape near the end.
        // We delegate and then ensure shape completeness in one place.
        $parsed = $this->inner->parse($rawText);

        return $this->ensureSsotShape($parsed);
    }

    /**
     * Ensure all expected top-level keys exist with safe defaults.
     */
    private function ensureSsotShape(array $parsed): array
    {
        $defaults = [
            'core' => [],
            'contacts' => [],
            'children' => [],
            'education_history' => [],
            'career_history' => [],
            'addresses' => [],
            'relatives' => [],
            'siblings' => [],
            'property_summary' => [],
            'property_assets' => [],
            'horoscope' => [],
            'preferences' => [],
            'extended_narrative' => [
                'narrative_about_me' => null,
                'narrative_expectations' => null,
                'additional_notes' => null,
            ],
            'confidence_map' => [],
        ];

        // Merge defaults without overwriting non-null structured sections.
        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $parsed) || $parsed[$key] === null) {
                $parsed[$key] = $default;
            }
        }

        // Make sure extended_narrative always has the 3 expected keys.
        if (!is_array($parsed['extended_narrative'])) {
            $parsed['extended_narrative'] = $defaults['extended_narrative'];
        } else {
            $parsed['extended_narrative'] = array_merge(
                $defaults['extended_narrative'],
                $parsed['extended_narrative']
            );
        }

        // Confidence map should always be an array.
        if (!is_array($parsed['confidence_map'])) {
            $parsed['confidence_map'] = [];
        }

        return $parsed;
    }
}

