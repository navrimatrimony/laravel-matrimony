<?php

namespace App\Services\Parsing;

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Services\Parsing\Contracts\BiodataParserInterface;
use App\Services\Parsing\Parsers\RulesOnlyBiodataParser;
use App\Services\Parsing\Parsers\AiFirstBiodataParser;
use App\Services\Parsing\Parsers\HybridBiodataParser;
use Illuminate\Support\Facades\Log;

/**
 * Resolve which parser strategy to use for a given intake.
 *
 * Normalizes admin settings to canonical backend modes:
 * - rules_only
 * - ai_first_v1
 * - hybrid_v1
 */
class ParserStrategyResolver
{
    public const MODE_RULES_ONLY = 'rules_only';
    public const MODE_AI_FIRST_V1 = 'ai_first_v1';
    public const MODE_AI_FIRST_V2 = 'ai_first_v2';
    public const MODE_HYBRID_V1 = 'hybrid_v1';

    /**
     * Normalize raw admin setting or legacy value to canonical mode.
     */
    public function normalizeMode(?string $raw): string
    {
        $value = trim((string) $raw);
        $value = str_replace(' ', '_', strtolower($value));

        // Legacy aliases
        if ($value === '' || $value === 'rules_v1') {
            return self::MODE_RULES_ONLY;
        }
        if ($value === 'ai_v1' || $value === 'ai-first' || $value === 'ai_first') {
            return self::MODE_AI_FIRST_V1;
        }
        if (str_contains($value, 'hybrid')) {
            return self::MODE_HYBRID_V1;
        }

        // Canonical values from newer admin screens
        if ($value === self::MODE_RULES_ONLY) {
            return self::MODE_RULES_ONLY;
        }
        if ($value === self::MODE_AI_FIRST_V1) {
            return self::MODE_AI_FIRST_V1;
        }
        if ($value === self::MODE_AI_FIRST_V2) {
            return self::MODE_AI_FIRST_V2;
        }
        if ($value === self::MODE_HYBRID_V1) {
            return self::MODE_HYBRID_V1;
        }

        // Unknown: safe fallback to rules_only
        Log::warning('Unknown intake_active_parser; falling back to rules_only', [
            'raw' => $raw,
        ]);

        return self::MODE_RULES_ONLY;
    }

    /**
     * Determine active mode for parsing new intakes from AdminSetting.
     */
    public function resolveActiveMode(): string
    {
        $raw = AdminSetting::getValue('intake_active_parser', self::MODE_RULES_ONLY);

        return $this->normalizeMode($raw);
    }

    /**
     * Resolve parser implementation for a specific intake.
     *
     * If intake has parser_version set, prefer that. Otherwise, use current active mode.
     */
    public function resolveForIntake(BiodataIntake $intake): BiodataParserInterface
    {
        $mode = $intake->parser_version
            ? $this->normalizeMode($intake->parser_version)
            : $this->resolveActiveMode();

        return $this->makeParser($mode);
    }

    /**
     * Instantiate parser for canonical mode.
     */
    public function makeParser(string $mode): BiodataParserInterface
    {
        $mode = $this->normalizeMode($mode);

        return match ($mode) {
            self::MODE_AI_FIRST_V1, self::MODE_AI_FIRST_V2 => app(AiFirstBiodataParser::class),
            self::MODE_HYBRID_V1 => app(HybridBiodataParser::class),
            default => app(RulesOnlyBiodataParser::class),
        };
    }
}

