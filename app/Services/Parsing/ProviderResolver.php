<?php

namespace App\Services\Parsing;

use App\Models\AdminSetting;

/**
 * Resolves intake AI/OCR provider routing from AdminSetting (new + legacy keys).
 * Single place for extraction vs structured-parse provider selection.
 */
class ProviderResolver
{
    public const MODE_END_TO_END = 'end_to_end';

    public const MODE_HYBRID = 'hybrid';

    public const PROVIDER_OPENAI = 'openai';

    public const PROVIDER_SARVAM = 'sarvam';

    public const EXTRACTION_TESSERACT = 'tesseract';

    /**
     * intake_processing_mode or legacy-derived value.
     */
    public function processingMode(): string
    {
        $v = strtolower(trim((string) AdminSetting::getValue('intake_processing_mode', '')));
        if ($v === self::MODE_HYBRID) {
            return self::MODE_HYBRID;
        }
        if ($v === self::MODE_END_TO_END) {
            return self::MODE_END_TO_END;
        }

        $legacy = strtolower(trim(str_replace(' ', '_', (string) AdminSetting::getValue('intake_active_parser', ''))));
        if ($legacy === ParserStrategyResolver::MODE_HYBRID_V1) {
            return self::MODE_HYBRID;
        }

        return self::MODE_END_TO_END;
    }

    /**
     * Sarvam | OpenAI — used when intake_processing_mode = end_to_end.
     */
    public function primaryAiProvider(): string
    {
        $p = strtolower(trim((string) AdminSetting::getValue('intake_primary_ai_provider', '')));
        if ($p === self::PROVIDER_SARVAM || $p === self::PROVIDER_OPENAI) {
            return $p;
        }

        $av = strtolower(trim((string) AdminSetting::getValue('intake_ai_vision_provider', '')));
        if ($av === self::PROVIDER_SARVAM || $av === self::PROVIDER_OPENAI) {
            return $av;
        }

        // Legacy: INTAKE_AI_VISION_PROVIDER / config used before intake_primary_ai_provider existed.
        $visionCfg = strtolower(trim((string) config('intake.ai_vision_extract.provider', self::PROVIDER_OPENAI)));
        if ($visionCfg === self::PROVIDER_SARVAM || $visionCfg === self::PROVIDER_OPENAI) {
            return $visionCfg;
        }

        $cfg = strtolower(trim((string) config('intake.defaults.primary_ai_provider', self::PROVIDER_OPENAI)));
        if ($cfg === self::PROVIDER_SARVAM || $cfg === self::PROVIDER_OPENAI) {
            return $cfg;
        }

        return self::PROVIDER_OPENAI;
    }

    public function hybridExtractionProvider(): string
    {
        $p = strtolower(trim((string) AdminSetting::getValue('intake_hybrid_extraction_provider', '')));
        if (in_array($p, [self::PROVIDER_SARVAM, self::PROVIDER_OPENAI, self::EXTRACTION_TESSERACT], true)) {
            return $p;
        }

        $av = strtolower(trim((string) AdminSetting::getValue('intake_ai_vision_provider', '')));
        if ($av === self::PROVIDER_SARVAM || $av === self::PROVIDER_OPENAI) {
            return $av;
        }

        return self::PROVIDER_OPENAI;
    }

    public function hybridParserProvider(): string
    {
        $p = strtolower(trim((string) AdminSetting::getValue('intake_hybrid_parser_provider', '')));
        if ($p === self::PROVIDER_SARVAM || $p === self::PROVIDER_OPENAI) {
            return $p;
        }

        $av = strtolower(trim((string) AdminSetting::getValue('intake_ai_vision_provider', '')));
        if ($av === self::PROVIDER_SARVAM || $av === self::PROVIDER_OPENAI) {
            return $av;
        }

        return self::PROVIDER_OPENAI;
    }

    public function hybridOcrFallback(): string
    {
        $p = strtolower(trim((string) AdminSetting::getValue('intake_hybrid_ocr_fallback', '')));
        if ($p === 'off' || $p === self::EXTRACTION_TESSERACT) {
            return $p;
        }

        $ocr = strtolower(trim((string) AdminSetting::getValue('intake_ocr_provider', self::EXTRACTION_TESSERACT)));
        if ($ocr === 'off') {
            return 'off';
        }

        return self::EXTRACTION_TESSERACT;
    }

    /**
     * Provider for text→JSON structured extraction (OpenAI chat vs Sarvam chat).
     */
    public function structuredParserProvider(): string
    {
        if ($this->processingMode() === self::MODE_HYBRID) {
            return $this->hybridParserProvider();
        }

        return $this->primaryAiProvider();
    }

    /**
     * True when ParseIntakeJob should use AiVisionExtractionService for raw text (file → text).
     */
    public function parseJobUsesAiVisionExtraction(): bool
    {
        $mode = strtolower(trim(str_replace(' ', '_', (string) AdminSetting::getValue('intake_active_parser', ''))));
        if ($mode === ParserStrategyResolver::MODE_AI_VISION_EXTRACT_V1) {
            return true;
        }
        if ($mode === ParserStrategyResolver::MODE_HYBRID_V1) {
            $ex = $this->hybridExtractionProvider();

            return $ex === self::PROVIDER_SARVAM || $ex === self::PROVIDER_OPENAI;
        }

        return false;
    }

    /**
     * openai | sarvam — for AiVisionExtractionService when AI file transcription runs.
     */
    public function visionTranscriptionProvider(): string
    {
        if ($this->processingMode() === self::MODE_END_TO_END) {
            return $this->primaryAiProvider();
        }
        if ($this->processingMode() === self::MODE_HYBRID) {
            $ex = $this->hybridExtractionProvider();
            if ($ex === self::PROVIDER_SARVAM || $ex === self::PROVIDER_OPENAI) {
                return $ex;
            }
        }

        $av = strtolower(trim((string) AdminSetting::getValue('intake_ai_vision_provider', '')));
        if ($av === self::PROVIDER_SARVAM || $av === self::PROVIDER_OPENAI) {
            return $av;
        }

        $cfg = strtolower(trim((string) config('intake.ai_vision_extract.provider', self::PROVIDER_OPENAI)));
        if ($cfg === self::PROVIDER_SARVAM || $cfg === self::PROVIDER_OPENAI) {
            return $cfg;
        }

        return self::PROVIDER_OPENAI;
    }
}
