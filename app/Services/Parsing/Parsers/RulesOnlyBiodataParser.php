<?php

namespace App\Services\Parsing\Parsers;

use App\Models\AdminSetting;
use App\Services\BiodataParserService;
use App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder;
use App\Services\Parsing\IntakeNormalizedDraftToParsedJsonMapper;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use App\Services\Parsing\MarathiOcrFieldRescueService;
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
        protected MarathiOcrFieldRescueService $marathiOcrFieldRescue,
    ) {
    }

    public function parse(string $rawText, array $context = []): array
    {
        if ($this->shouldUseNormalizedDraftParser($context)) {
            $draft = $this->draftBuilder->build($rawText, $context);
            $parsed = $this->draftMapper->map($draft);

            return $this->ensureSsotShape($this->applyMarathiOcrFieldRescue($rawText, $parsed));
        }

        // Existing BiodataParserService already normalizes + splits + extracts.
        // It also attempts to return a SSOT-like shape near the end.
        // We delegate and then ensure shape completeness in one place.
        $parsed = $this->inner->parse($rawText);

        return $this->ensureSsotShape($this->applyMarathiOcrFieldRescue($rawText, $parsed));
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
        return $this->normalizedDraftParserEnabled()
            && empty($context['legacy_rules_only']);
    }

    private function normalizedDraftParserEnabled(): bool
    {
        $fallback = (bool) config('intake.use_normalized_draft_parser', false);

        try {
            return AdminSetting::getBool('intake_use_normalized_draft_parser', $fallback);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * Ensure all expected top-level keys exist with safe defaults.
     */
    private function ensureSsotShape(array $parsed): array
    {
        return $this->skeleton->ensure($parsed);
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function applyMarathiOcrFieldRescue(string $rawText, array $parsed): array
    {
        $lines = preg_split('/\R/u', $rawText) ?: [];
        $lines = array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), $lines),
            static fn (string $line): bool => $line !== ''
        ));

        if ($lines === []) {
            return $parsed;
        }

        $core = is_array($parsed['core'] ?? null) ? $parsed['core'] : [];
        $parsed['core'] = $this->marathiOcrFieldRescue->rescueCoreFields($lines, $core);

        return $parsed;
    }
}
