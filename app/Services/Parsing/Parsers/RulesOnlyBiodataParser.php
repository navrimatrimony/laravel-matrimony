<?php

namespace App\Services\Parsing\Parsers;

use App\Services\BiodataParserService;
use App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder;
use App\Services\Parsing\IntakeNormalizedDraftToParsedJsonMapper;
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
        protected IntakeNormalizedBiodataDraftBuilder $draftBuilder,
        protected IntakeNormalizedDraftToParsedJsonMapper $draftMapper,
    ) {
    }

    public function parse(string $rawText, array $context = []): array
    {
        if ($this->shouldUseNormalizedDraftParser($context)) {
            $draft = $this->draftBuilder->build($rawText, $context);
            $parsed = $this->draftMapper->map($draft);

            return $this->ensureSsotShape($parsed);
        }

        // Existing BiodataParserService already normalizes + splits + extracts.
        // It also attempts to return a SSOT-like shape near the end.
        // We delegate and then ensure shape completeness in one place.
        $parsed = $this->inner->parse($rawText);

        return $this->ensureSsotShape($parsed);
    }

    /**
     * Rules-normalized text recovery for DOB (AI-first merge path).
     */
    public function recoverDateOfBirthFromNormalizedBiodataText(string $text): ?string
    {
        return $this->inner->recoverDateOfBirthFromNormalizedBiodataText($text);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function shouldUseNormalizedDraftParser(array $context): bool
    {
        return (bool) config('intake.use_normalized_draft_parser', false)
            && empty($context['legacy_rules_only']);
    }

    /**
     * Ensure all expected top-level keys exist with safe defaults.
     */
    private function ensureSsotShape(array $parsed): array
    {
        return $this->skeleton->ensure($parsed);
    }
}
