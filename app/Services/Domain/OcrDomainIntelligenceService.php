<?php

namespace App\Services\Domain;

use App\Services\ControlledOptions\ControlledOptionEngine;
use App\Services\Ocr\OcrNormalize;
use App\Services\Ocr\OcrSuggestionEngine;

/**
 * Domain-aware OCR enhancement layer.
 *
 * - Works on the parse-input string only (never writes to raw_ocr_text).
 * - Uses existing engines (ControlledOptionEngine + OcrSuggestionEngine) to clean values early.
 * - Conservative and segment-based to avoid cross-field contamination.
 */
class OcrDomainIntelligenceService
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $horoscopeLabelKeywords = [
        'horoscope.nadi' => ['नाडी', 'नाड'],
        'horoscope.gan' => ['गण'],
        'horoscope.rashi' => ['रास', 'राशी'],
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private array $coreLabelKeywords = [
        'full_name' => ['नाव', 'नांव'],
        'date_of_birth' => ['जन्म', 'जन्प'],
    ];

    /**
     * @var array<int, string>
     */
    private array $horoscopeFieldKeys = ['horoscope.nadi', 'horoscope.gan', 'horoscope.rashi'];

    public function __construct(
        private readonly ControlledOptionEngine $controlledOptionEngine,
        private readonly OcrSuggestionEngine $ocrSuggestionEngine,
    ) {}

    public function enhance(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $lines = explode("\n", $text);
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $out[] = $this->enhanceLine($line);
        }

        return implode("\n", $out);
    }

    private function enhanceLine(string $line): string
    {
        $rawTokens = preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($rawTokens) || $rawTokens === []) {
            return $line;
        }

        // Step 2: token-level normalization (digits + safe latin lowercasing)
        $tokens = [];
        foreach ($rawTokens as $t) {
            $t = $this->stripOuterPunctuation((string) $t);
            if ($t === '') {
                continue;
            }
            $tokens[] = $this->normalizeToken($t);
        }

        if ($tokens === []) {
            return $line;
        }

        // Step 3: field-aware detection (segment-based)
        $labelPositions = [];

        foreach ($tokens as $i => $tok) {
            foreach ($this->horoscopeLabelKeywords as $fieldKey => $keywords) {
                if ($this->tokenIsLabel($tok, $keywords)) {
                    $labelPositions[] = ['field' => $fieldKey, 'index' => (int) $i];
                    break;
                }
            }
        }

        // Also include core fields labels so name/DOB segments can use suggestion engine.
        foreach ($tokens as $i => $tok) {
            foreach ($this->coreLabelKeywords as $fieldKey => $keywords) {
                if ($this->tokenIsLabel($tok, $keywords)) {
                    $labelPositions[] = ['field' => $fieldKey, 'index' => (int) $i];
                    break;
                }
            }
        }

        if ($labelPositions === []) {
            // No known labels in this line — only return the digit/lowercasing-normalized line.
            return implode(' ', $tokens);
        }

        usort($labelPositions, fn ($a, $b) => $a['index'] <=> $b['index']);

        // Deduplicate labels at the same index (keep first entry).
        $seenIndex = [];
        $dedup = [];
        foreach ($labelPositions as $pos) {
            if (isset($seenIndex[$pos['index']])) {
                continue;
            }
            $seenIndex[$pos['index']] = true;
            $dedup[] = $pos;
        }
        $labelPositions = $dedup;

        $tokenCount = count($tokens);
        $outTokens = [];
        $cursor = 0;

        for ($li = 0; $li < count($labelPositions); $li++) {
            $label = $labelPositions[$li];
            $labelIndex = (int) $label['index'];
            if ($labelIndex < $cursor) {
                continue;
            }

            // Pre-label tokens (keep as-is)
            for ($k = $cursor; $k < $labelIndex; $k++) {
                $outTokens[] = $tokens[$k];
            }

            $fieldKey = (string) $label['field'];
            $outTokens[] = $tokens[$labelIndex];

            $segmentStart = $labelIndex + 1;
            $segmentEnd = $li + 1 < count($labelPositions)
                ? (int) $labelPositions[$li + 1]['index']
                : $tokenCount;

            $segmentTokens = array_slice($tokens, $segmentStart, max(0, $segmentEnd - $segmentStart));
            $enhancedSegment = $this->enhanceSegment($fieldKey, $segmentTokens, $line);
            foreach ($enhancedSegment as $t) {
                $outTokens[] = $t;
            }

            $cursor = $segmentEnd;
        }

        // Remaining tokens after last label
        for ($k = $cursor; $k < $tokenCount; $k++) {
            $outTokens[] = $tokens[$k];
        }

        return implode(' ', $outTokens);
    }

    /** @return array<int, string> */
    private function enhanceSegment(string $fieldKey, array $segmentTokens, string $rawLine): array
    {
        if ($segmentTokens === []) {
            return [];
        }

        if (in_array($fieldKey, $this->horoscopeFieldKeys, true)) {
            return $this->enhanceHoroscopeSegment($fieldKey, $segmentTokens);
        }

        if ($fieldKey === 'full_name') {
            return $this->enhanceCoreSuggestionSegment('full_name', $segmentTokens, $rawLine);
        }

        if ($fieldKey === 'date_of_birth') {
            return $this->enhanceCoreSuggestionSegment('date_of_birth', $segmentTokens, $rawLine);
        }

        return $segmentTokens;
    }

    /**
     * Token-level normalization for horoscope fields.
     * - Replace tokens/n-grams with ControlledOptionEngine canonical keys.
     * - Drop tokens/n-grams that strongly match other horoscope fields in the current segment.
     */
    private function enhanceHoroscopeSegment(string $fieldKey, array $segmentTokens): array
    {
        $out = [];
        $count = count($segmentTokens);

        $otherFields = array_values(array_filter($this->horoscopeFieldKeys, fn ($k) => $k !== $fieldKey));

        $j = 0;
        while ($j < $count) {
            $picked = false;

            // Try longer n-grams first so synonyms like 'राक्षस गण' resolve.
            for ($len = 3; $len >= 1; $len--) {
                if ($j + $len > $count) {
                    continue;
                }

                $ngramTokens = array_slice($segmentTokens, $j, $len);
                $ngram = trim(implode(' ', $ngramTokens));
                if ($ngram === '') {
                    continue;
                }

                $canonicalCurrent = $this->controlledOptionEngine->normalizeText($fieldKey, $ngram);
                if ($canonicalCurrent !== null) {
                    $out[] = $canonicalCurrent;
                    $j += $len;
                    $picked = true;
                    break;
                }

                // Cross-field contamination prevention.
                $matchedOther = false;
                foreach ($otherFields as $otherFieldKey) {
                    if ($this->controlledOptionEngine->normalizeText($otherFieldKey, $ngram) !== null) {
                        $matchedOther = true;
                        break;
                    }
                }

                if ($matchedOther) {
                    // Be conservative: drop only small-ish parts.
                    if ($len === 1 || mb_strlen($ngram, 'UTF-8') <= 15) {
                        $j += $len;
                        $picked = true;
                        break;
                    }
                }
            }

            if (! $picked) {
                $out[] = (string) $segmentTokens[$j];
                $j++;
            }
        }

        return $out;
    }

    /**
     * Suggestions for core fields (full_name, date_of_birth).
     * Only apply when suggestion confidence > 0.7.
     */
    private function enhanceCoreSuggestionSegment(string $fieldKey, array $segmentTokens, string $rawLine): array
    {
        $current = trim(implode(' ', $segmentTokens));
        if ($current === '') {
            return $segmentTokens;
        }

        $suggestion = $this->ocrSuggestionEngine->getSuggestion($fieldKey, $current, $rawLine);
        $suggested = isset($suggestion['suggested_value']) ? (string) $suggestion['suggested_value'] : '';
        $confidence = (float) ($suggestion['confidence'] ?? 0);

        $placeholderNotFound = OcrSuggestionEngine::PLACEHOLDER_NOT_FOUND;
        $placeholderSelectRequired = OcrSuggestionEngine::PLACEHOLDER_SELECT_REQUIRED;

        $isSafeSuggested = $suggested !== ''
            && $suggested !== $current
            && $confidence > 0.7
            && $suggested !== $placeholderNotFound
            && $suggested !== $placeholderSelectRequired
            && ! str_contains($suggested, 'NOT FOUND')
            && ! str_contains($suggested, 'SELECT REQUIRED');

        if (! $isSafeSuggested) {
            return $segmentTokens;
        }

        $parts = preg_split('/\s+/u', $suggested, -1, PREG_SPLIT_NO_EMPTY);
        return $parts ?: $segmentTokens;
    }

    private function normalizeToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return $token;
        }

        // digits
        $token = OcrNormalize::normalizeDigits($token);

        // Only lowercase if latin exists.
        if (preg_match('/[A-Za-z]/', $token) === 1) {
            $token = mb_strtolower($token, 'UTF-8');
        }

        return $token;
    }

    private function stripOuterPunctuation(string $token): string
    {
        // Remove punctuation/symbols only at the start/end.
        $token = preg_replace('/^[\p{P}\p{S}]+/u', '', $token) ?? $token;
        $token = preg_replace('/[\p{P}\p{S}]+$/u', '', $token) ?? $token;
        return trim($token);
    }

    private function tokenIsLabel(string $token, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if ($token === $kw) {
                return true;
            }
        }

        return false;
    }
}
