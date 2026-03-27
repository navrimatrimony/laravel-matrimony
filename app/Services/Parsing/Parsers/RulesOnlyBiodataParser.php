<?php

namespace App\Services\Parsing\Parsers;

use App\Services\BiodataParserService;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
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
        protected IntakeParsedSnapshotSkeleton $skeleton,
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
        return $this->skeleton->ensure($parsed);
    }
}

