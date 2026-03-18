<?php

namespace App\Services\Ocr;

use App\Models\Caste;
use App\Models\OcrCorrectionPattern;
use App\Models\Religion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SSOT Day-27: Unified OCR Suggestion Engine
 * Provides consistent suggestion logic for all editable core fields.
 * 
 * Returns: { suggested_value: string|null, confidence: float, source: string, usage_count: int }
 */
class OcrSuggestionEngine
{
    /** Placeholder when no candidate can be extracted; approval must be blocked until user edits. */
    public const PLACEHOLDER_NOT_FOUND = 'âŸªNOT FOUND IN OCRâŸ«';

    /** Placeholder for required dropdown when no allowed value found in OCR; user must select. Approval blocked until selected. */
    public const PLACEHOLDER_SELECT_REQUIRED = 'âŸªSELECT REQUIREDâŸ«';

    private bool $debug;

    public function __construct()
    {
        $this->debug = config('app.debug', false) && config('ocr.suggestion_debug', false);
    }

    /**
     * Get suggestion for a field.
     * 
     * @param string $fieldKey Field key (e.g., 'gender', 'date_of_birth')
     * @param mixed $currentValue Current extracted value
     * @param string|null $rawSourceText Optional raw OCR text for context
     * @return array{suggested_value: string|null, confidence: float, source: string, usage_count: int}
     */
    public function getSuggestion(string $fieldKey, $currentValue, ?string $rawSourceText = null): array
    {
        // Normalize UI placeholder so pattern lookup sees real empty (no key mismatch)
        $currentValue = $this->normalizePlaceholder($currentValue);

        if ($currentValue === null || $currentValue === '') {
            // NEW: If current value is empty, try to infer from raw OCR text (only for safe fields)
            $rawSuggestion = $this->suggestFromRawText($fieldKey, $rawSourceText);

            if ($rawSuggestion['suggested_value'] !== null && $rawSuggestion['suggested_value'] !== '') {
                $this->logDebug(
                    $fieldKey,
                    $currentValue,
                    $rawSuggestion['suggested_value'],
                    'raw_text_inference',
                    $rawSuggestion['confidence'],
                    $rawSuggestion['source']
                );
                $rawSuggestion['usage_count'] = 0;
                return $rawSuggestion;
            }

            $this->logDebug($fieldKey, $currentValue, null, 'empty_value');
            return ['suggested_value' => null, 'confidence' => 0.0, 'source' => 'none', 'usage_count' => 0];
        }

        $currentValue = is_scalar($currentValue) ? trim((string) $currentValue) : '';
        $currentValue = $this->normalizePlaceholder($currentValue);
        if ($currentValue === '') {
            $this->logDebug($fieldKey, $currentValue, null, 'empty_after_trim');
            return ['suggested_value' => null, 'confidence' => 0.0, 'source' => 'none', 'usage_count' => 0];
        }

        // Step 1: Apply normalization (safe normalization only)
        $normalized = $this->normalizeValue($fieldKey, $currentValue);
        
        // If normalization changed the value, use normalized as suggestion
        if ($normalized !== $currentValue && $normalized !== null && $normalized !== '') {
            $suggested = $fieldKey === 'full_name' ? $this->sanitizeFullNameSuggestion($normalized) : $normalized;
            if ($suggested !== null && $suggested !== '') {
                $this->logDebug($fieldKey, $currentValue, $suggested, 'normalization', 0.70, 'normalization');
                return [
                    'suggested_value' => $suggested,
                    'confidence' => 0.70,
                    'source' => 'normalization',
                    'usage_count' => 0,
                ];
            }
        }

        // Step 2: Check baseline patterns (exact match on original value)
        $baselinePattern = $this->findPattern($fieldKey, $currentValue, 'frequency_rule');
        if ($baselinePattern) {
            $suggested = $baselinePattern->corrected_value;
            if ($fieldKey === 'full_name') {
                $suggested = $this->sanitizeFullNameSuggestion($suggested ?? '');
            }
            if ($suggested !== null && $suggested !== $currentValue) {
                $this->logDebug($fieldKey, $currentValue, $suggested, 'baseline_pattern', $baselinePattern->pattern_confidence, 'frequency_rule');
                return [
                    'suggested_value' => $suggested,
                    'confidence' => (float) $baselinePattern->pattern_confidence,
                    'source' => 'frequency_rule',
                    'usage_count' => (int) $baselinePattern->usage_count,
                ];
            }
        }

        // Step 3: Check baseline patterns on normalized value
        if ($normalized && $normalized !== $currentValue) {
            $baselinePatternNormalized = $this->findPattern($fieldKey, $normalized, 'frequency_rule');
            if ($baselinePatternNormalized) {
                $suggested = $baselinePatternNormalized->corrected_value;
                if ($fieldKey === 'full_name') {
                    $suggested = $this->sanitizeFullNameSuggestion($suggested ?? '');
                }
                if ($suggested !== null && $suggested !== $currentValue) {
                    $this->logDebug($fieldKey, $currentValue, $suggested, 'baseline_pattern_normalized', $baselinePatternNormalized->pattern_confidence, 'frequency_rule');
                    return [
                        'suggested_value' => $suggested,
                        'confidence' => (float) $baselinePatternNormalized->pattern_confidence,
                        'source' => 'frequency_rule',
                        'usage_count' => (int) $baselinePatternNormalized->usage_count,
                    ];
                }
            }
        }

        // Step 4: Check frequency patterns (exact match on original value)
        $frequencyPattern = $this->findPattern($fieldKey, $currentValue, 'frequency_rule');
        if ($frequencyPattern) {
            $suggested = $frequencyPattern->corrected_value;
            if ($fieldKey === 'full_name') {
                $suggested = $this->sanitizeFullNameSuggestion($suggested ?? '');
            }
            if ($suggested !== null && $suggested !== $currentValue) {
                $this->logDebug($fieldKey, $currentValue, $suggested, 'frequency_pattern', $frequencyPattern->pattern_confidence, 'frequency_rule');
                return [
                    'suggested_value' => $suggested,
                    'confidence' => (float) $frequencyPattern->pattern_confidence,
                    'source' => 'frequency_rule',
                    'usage_count' => (int) $frequencyPattern->usage_count,
                ];
            }
        }

        // Step 5: Check frequency patterns on normalized value
        if ($normalized && $normalized !== $currentValue) {
            $frequencyPatternNormalized = $this->findPattern($fieldKey, $normalized, 'frequency_rule');
            if ($frequencyPatternNormalized) {
                $suggested = $frequencyPatternNormalized->corrected_value;
                if ($fieldKey === 'full_name') {
                    $suggested = $this->sanitizeFullNameSuggestion($suggested ?? '');
                }
                if ($suggested !== null && $suggested !== $currentValue) {
                    $this->logDebug($fieldKey, $currentValue, $suggested, 'frequency_pattern_normalized', $frequencyPatternNormalized->pattern_confidence, 'frequency_rule');
                    return [
                        'suggested_value' => $suggested,
                        'confidence' => (float) $frequencyPatternNormalized->pattern_confidence,
                        'source' => 'frequency_rule',
                        'usage_count' => (int) $frequencyPatternNormalized->usage_count,
                    ];
                }
            }
        }

        // Day-28 safe fallback: even when current value exists, allow raw-text inference for select fields.
        // This is DISPLAY-ONLY suggestion behavior (Preview Suggestion Injection).
        if (in_array($fieldKey, ['gender', 'full_name', 'date_of_birth', 'religion', 'caste', 'sub_caste'], true)) {
            $rawSuggestion = $this->suggestFromRawText($fieldKey, $rawSourceText);
            $rawVal = $rawSuggestion['suggested_value'] ?? null;
            if ($fieldKey === 'full_name' && $rawVal !== null) {
                $rawVal = $this->sanitizeFullNameSuggestion((string) $rawVal);
            }
            if (
                $rawVal !== null
                && $rawVal !== ''
                && (string) $rawVal !== (string) $currentValue
            ) {
                $this->logDebug(
                    $fieldKey,
                    $currentValue,
                    $rawVal,
                    'raw_text_inference_override',
                    (float) ($rawSuggestion['confidence'] ?? 0.0),
                    (string) ($rawSuggestion['source'] ?? 'raw_text')
                );

                return [
                    'suggested_value' => $rawVal,
                    'confidence' => (float) ($rawSuggestion['confidence'] ?? 0.0),
                    'source' => (string) ($rawSuggestion['source'] ?? 'raw_text'),
                    'usage_count' => 0,
                ];
            }
        }

        $this->logDebug($fieldKey, $currentValue, null, 'no_match');
        return ['suggested_value' => null, 'confidence' => 0.0, 'source' => 'none', 'usage_count' => 0];
    }

    /**
     * Get up to 3 candidate suggestions for a field (for multi-candidate UI).
     * Each candidate: ['value' => string, 'confidence' => float, 'source' => string].
     *
     * @return array<int, array{value: string, confidence: float, source: string}>
     */
    public function getCandidates(string $fieldKey, $currentValue, ?string $rawSourceText = null): array
    {
        $currentValue = $this->normalizePlaceholder($currentValue);
        $currentValue = is_scalar($currentValue) ? trim((string) $currentValue) : '';
        $currentValue = $this->normalizePlaceholder($currentValue) ?? '';

        $byValue = [];

        if ($fieldKey === 'primary_contact_number' && $rawSourceText !== null && $rawSourceText !== '') {
            foreach ($this->getPhoneCandidatesFromRawText($rawSourceText) as $c) {
                $v = (string) $c['value'];
                if ($v !== '' && (!isset($byValue[$v]) || $byValue[$v]['confidence'] < (float) $c['confidence'])) {
                    $byValue[$v] = ['value' => $v, 'confidence' => (float) $c['confidence'], 'source' => (string) ($c['source'] ?? 'raw_text')];
                }
            }
        } else {
            $raw = $this->suggestFromRawText($fieldKey, $rawSourceText);
            if (isset($raw['suggested_value']) && $raw['suggested_value'] !== null && $raw['suggested_value'] !== '') {
                $v = (string) $raw['suggested_value'];
                if ($fieldKey === 'full_name') {
                    $v = $this->sanitizeFullNameSuggestion($v) ?? $v;
                }
                if ($v !== '') {
                    $byValue[$v] = ['value' => $v, 'confidence' => (float) ($raw['confidence'] ?? 0), 'source' => (string) ($raw['source'] ?? 'raw_text')];
                }
            }
        }

        $one = $this->getSuggestion($fieldKey, $currentValue, $rawSourceText);
        if (isset($one['suggested_value']) && $one['suggested_value'] !== null && $one['suggested_value'] !== '') {
            $v = (string) $one['suggested_value'];
            $c = (float) ($one['confidence'] ?? 0);
            $s = (string) ($one['source'] ?? 'none');
            if (!isset($byValue[$v]) || $byValue[$v]['confidence'] < $c) {
                $byValue[$v] = ['value' => $v, 'confidence' => $c, 'source' => $s];
            }
        }

        if ($fieldKey === 'religion' && $rawSourceText !== null && $rawSourceText !== '') {
            foreach ($this->matchReligionAllowedList($rawSourceText) as $c) {
                $v = (string) $c['value'];
                if ($v !== '' && (!isset($byValue[$v]) || $byValue[$v]['confidence'] < (float) $c['confidence'])) {
                    $byValue[$v] = ['value' => $v, 'confidence' => (float) $c['confidence'], 'source' => (string) ($c['source'] ?? 'allowed_list')];
                }
            }
        }

        if ($fieldKey === 'caste' && $rawSourceText !== null && $rawSourceText !== '') {
            foreach ($this->matchCasteAllowedList($rawSourceText) as $c) {
                $v = (string) $c['value'];
                if ($v !== '' && (!isset($byValue[$v]) || $byValue[$v]['confidence'] < (float) $c['confidence'])) {
                    $byValue[$v] = ['value' => $v, 'confidence' => (float) $c['confidence'], 'source' => (string) ($c['source'] ?? 'allowed_list_db')];
                }
            }
        }

        $normalized = $this->normalizeValue($fieldKey, $currentValue);
        $patternKeys = array_unique(array_filter([$currentValue, $normalized]));
        foreach (['frequency_rule', 'frequency_rule'] as $source) {
            foreach ($patternKeys as $wrong) {
                if ($wrong === '') {
                    continue;
                }
                $p = $this->findPattern($fieldKey, $wrong, $source);
                if ($p && $p->corrected_value !== null && (string) $p->corrected_value !== (string) $currentValue) {
                    $v = $fieldKey === 'full_name' ? ($this->sanitizeFullNameSuggestion((string) $p->corrected_value) ?? $p->corrected_value) : $p->corrected_value;
                    $v = (string) $v;
                    if ($fieldKey === 'caste') {
                        $v = $this->resolveCasteToCanonical($v) ?? $v;
                    }
                    if ($v !== '' && (!isset($byValue[$v]) || $byValue[$v]['confidence'] < (float) $p->pattern_confidence)) {
                        $byValue[$v] = ['value' => $v, 'confidence' => (float) $p->pattern_confidence, 'source' => $source];
                    }
                }
            }
        }

        if ($fieldKey === 'caste') {
            $byValue = array_filter($byValue, function ($entry) {
                $canonical = $this->resolveCasteToCanonical((string) $entry['value']);
                return $canonical !== null && $canonical === (string) $entry['value'];
            });
        }

        uasort($byValue, static fn ($a, $b) => $b['confidence'] <=> $a['confidence']);
        return array_values(array_slice($byValue, 0, 3));
    }

    /**
     * Split combined "religion-caste" token (e.g. "à¤¹à¤¿à¤‚à¤¦à¥‚-à¤®à¤°à¤¾à¤ à¤¾") into religion and caste parts.
     * Returns ['religion' => string|null, 'caste' => string|null] or null if not split.
     */
    public function splitReligionCaste(string $combined): ?array
    {
        $combined = trim($combined);
        if ($combined === '' || !preg_match('/[\-\/]/u', $combined)) {
            return null;
        }
        $knownReligions = ['à¤¹à¤¿à¤‚à¤¦à¥‚', 'à¤¹à¤¿à¤‚à¤¦à¥', 'à¤®à¥à¤¸à¥à¤²à¤¿à¤®', 'à¤¬à¥Œà¤¦à¥à¤§', 'à¤œà¥ˆà¤¨', 'à¤–à¥à¤°à¤¿à¤¶à¥à¤šà¤¨', 'à¤‡à¤¸à¥à¤²à¤¾à¤®', 'Hindu', 'Muslim', 'Christian', 'Buddhist', 'Jain'];
        $parts = preg_split('/[\-\/]+/u', $combined);
        $parts = array_values(array_filter(array_map('trim', $parts)));
        if (count($parts) < 2) {
            return null;
        }
        $rel = null;
        $caste = null;
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            $isReligion = false;
            foreach ($knownReligions as $r) {
                if (stripos($p, $r) !== false || $p === $r) {
                    $rel = $p === 'à¤¹à¤¿à¤‚à¤¦à¥' ? 'à¤¹à¤¿à¤‚à¤¦à¥‚' : $p;
                    $isReligion = true;
                    break;
                }
            }
            if (!$isReligion && $p !== '') {
                $caste = $p;
            }
        }
        if ($rel !== null || $caste !== null) {
            return ['religion' => $rel, 'caste' => $caste];
        }
        return null;
    }

    /**
     * Normalize value based on field type (safe normalization only).
     */
    private function normalizeValue(string $fieldKey, string $value): ?string
    {
        // Apply safe normalization based on field type
        switch ($fieldKey) {
            case 'gender':
                return OcrNormalize::normalizeGender($value);

            case 'date_of_birth':
                // Only normalize digits, don't change format
                $normalized = OcrNormalize::normalizeDigits($value);
                return $normalized !== $value ? $normalized : null;

            case 'blood_group':
                return OcrNormalize::normalizeBloodGroup($value);

            case 'height':
                return OcrNormalize::normalizeHeight($value);

            case 'primary_contact_number':
                return OcrNormalize::normalizePhone($value);

            case 'full_name':
            case 'religion':
            case 'caste':
            case 'sub_caste':
                // Safe normalization: trim, collapse spaces. Preserve Devanagari vowel signs (à¥‚, à¤¾) - use \p{M}
                $normalized = trim($value);
                $normalized = preg_replace('/\s+/u', ' ', $normalized);
                // Remove only non-letter/non-number/non-mark at start/end (do NOT strip \p{M} or "à¤¹à¤¿à¤‚à¤¦à¥‚"/"à¤®à¤°à¤¾à¤ à¤¾" become "à¤¹à¤¿à¤‚à¤¦"/"à¤®à¤°à¤¾à¤ ")
                $normalized = preg_replace('/^[^\p{L}\p{N}\p{M}]+/u', '', $normalized);
                $normalized = preg_replace('/[^\p{L}\p{N}\p{M}]+$/u', '', $normalized);
                return $normalized !== $value ? $normalized : null;

            default:
                // Generic: trim and collapse spaces
                $normalized = trim($value);
                $normalized = preg_replace('/\s+/', ' ', $normalized);
                return $normalized !== $value ? $normalized : null;
        }
    }

    /**
     * Normalize UI placeholder to empty string for consistent pattern matching.
     */
    private function normalizePlaceholder($value): ?string
    {
        if ($value === null || $value === '') {
            return $value === null ? null : '';
        }
        if (!is_scalar($value)) {
            return '';
        }
        $s = trim((string) $value);
        if ($s === 'â€”' || $s === 'â€“' || $s === '-') {
            return '';
        }
        return $s;
    }

    /**
     * Strip trailing phone number from name line (OCR often appends contact on same line).
     * E.g. "चि. सुशांत शिवाजी पाटील पूजा सावत ९६८९८६८८६९" → "चि. सुशांत शिवाजी पाटील पूजा सावत"
     */
    private function stripTrailingPhoneFromName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }
        // Trailing 10+ digits (Devanagari or ASCII) possibly with spaces/dots
        if (preg_match('/\s+([0-9०-९\s\.\-]{10,})$/u', $value, $m)) {
            $value = trim(mb_substr($value, 0, -mb_strlen($m[0])));
        }
        return trim($value);
    }

    /**
     * Sanity filter for full_name suggestions: block garbage (latin noise, parentheses content).
     * Returns cleaned value or null if should not be suggested.
     */
    private function sanitizeFullNameSuggestion(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) < 2) {
            return null;
        }
        // Remove parenthesized content (e.g. "(à¤¶à¥‡à¤¤à¥€ )" - occupation/notes)
        $value = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return null;
        }
        // Block if contains obvious latin noise tokens (snp, random chunks)
        if (preg_match('/\b[a-z]{2,}\b/i', $value) && !preg_match('/^(à¤¶à¥à¤°à¥€|à¤¶à¥à¤°à¥€à¤®à¤¤à¥€|Mr|Mrs|Ms)\.?\s*$/ui', $value)) {
            $latinOnly = preg_replace('/[\p{Devanagari}\s\.\-]/u', '', $value);
            if (strlen($latinOnly) >= 2) {
                return null;
            }
        }
        // Allow Devanagari, spaces, dot, common prefixes; strip any remaining latin except single initials
        $cleaned = preg_replace('/\s+/u', ' ', $value);
        $cleaned = trim($cleaned);
        // Remove trailing single Latin character (OCR junk e.g. "à¤‡à¤¬à¥à¤°à¤¾à¤¹à¤¿à¤® à¤•à¤¾à¤¶à¥€à¤® à¤¦à¥‡à¤¸à¤¾à¤ˆ x")
        $cleaned = preg_replace('/\s+[a-zA-Z]\s*$/u', '', $cleaned);
        $cleaned = trim($cleaned);
        // Remove repeated punctuation and stray underscores
        $cleaned = preg_replace('/[\.\-_,]{2,}/u', '', $cleaned);
        $cleaned = trim($cleaned, " \t\n\r\0\x0B_.,-");
        return $cleaned !== '' ? $cleaned : null;
    }

    /**
     * Find pattern in database.
     */
    private function findPattern(string $fieldKey, string $wrongPattern, string $source): ?OcrCorrectionPattern
    {
        // Try "core.<key>" first (SSOT-safe), then "<key>"
        $pattern = OcrCorrectionPattern::where('field_key', 'core.' . $fieldKey)
            ->where('wrong_pattern', $wrongPattern)
            ->where('source', $source)
            ->where('is_active', true)
            ->first();

        if (!$pattern) {
            $pattern = OcrCorrectionPattern::where('field_key', $fieldKey)
                ->where('wrong_pattern', $wrongPattern)
                ->where('source', $source)
                ->where('is_active', true)
                ->first();
        }

        return $pattern;
    }

/**
 * NEW: Suggest value from raw OCR text when extracted current value is empty.
 * Only safe inference fields should be supported here.
 *
 * @return array{suggested_value: string|null, confidence: float, source: string}
 */
private function suggestFromRawText(string $fieldKey, ?string $rawSourceText): array
{
    if (!$rawSourceText) {
        return ['suggested_value' => null, 'confidence' => 0.0, 'source' => 'none'];
    }

    $text = OcrNormalize::normalizeRawText($rawSourceText);
    $textLower = mb_strtolower($text);

    switch ($fieldKey) {
        case 'full_name':
            $nameNegativeKeywords = ['à¤¨à¤¾à¤µà¤°à¤¸ à¤¨à¤¾à¤µ', 'à¤¨à¤¾à¤µà¤°à¥‡à¤¸ à¤¨à¤¾à¤µ', 'à¤œà¤¨à¥à¤®', 'à¤‰à¤‚à¤šà¥€', 'à¤¶à¤¿à¤•à¥à¤·à¤£', 'à¤¨à¥‹à¤•à¤°à¥€', 'à¤®à¥.à¤ªà¥‹.', 'à¤µà¤¡à¥€à¤²', 'à¤†à¤ˆ'];
            // Allow "नांव !-", "नांव :-", "नाव : -" etc. (OCR often adds ! or space before hyphen)
            $patterns = [
                '/à¤®à¥à¤²à¥€à¤šà¥‡\s*(à¤¨à¤¾à¤‚à¤µ|à¤¨à¤¾à¤µ)\s*[!:\-\s]*[:\-]\s*([^\r\n]+)/u',
                '/à¤®à¥à¤²à¤¾à¤šà¥‡\s*(à¤¨à¤¾à¤‚à¤µ|à¤¨à¤¾à¤µ)\s*[!:\-\s]*[:\-]\s*([^\r\n]+)/u',
                '/à¤®à¥à¤²à¥€à¤šà¥‡\s*(à¤¨à¤¾à¤‚à¤µ|à¤¨à¤¾à¤µ)\s*[:\-]*\s*([^\r\n]+)/u',
                '/à¤®à¥à¤²à¤¾à¤šà¥‡\s*(à¤¨à¤¾à¤‚à¤µ|à¤¨à¤¾à¤µ)\s*[:\-]*\s*([^\r\n]+)/u',
                '/à¤¨à¤¾à¤µ\s*:\s*-\s*([^\r\n]+)/u',
                '/à¤¨à¤¾à¤µ\s*:\s*([^\r\n]+)/u',
                '/à¤¨à¤¾à¤µ\s*[:\-]+\s*([^\r\n]+)/u',
                '/à¤•à¥\.?\s*([^\r\n]+)/u',
                '/\bà¤¨à¤¾à¤®\s*[:\-]*\s*([^\r\n]+)/u',
                '/\bName\s*[:\-]*\s*([^\r\n]+)/ui',
                '/Name\s*:\s*([^\r\n]+)/ui',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text, $m)) {
                    $name = trim((string) (end($m) ?? ''));
                    $name = preg_replace('/[_\-:]+/u', ' ', $name);
                    $name = preg_replace('/\s+/u', ' ', $name);
                    $name = trim($name);
                    $name = $this->stripTrailingPhoneFromName($name);

                    $reject = false;
                    foreach ($nameNegativeKeywords as $n) {
                        if (mb_strpos($name, $n) !== false || mb_strpos($m[0], $n) !== false) {
                            $reject = true;
                            break;
                        }
                    }
                    if ($reject || $name === '' || mb_strlen($name) < 2) {
                        continue;
                    }

                    $sanitized = $this->sanitizeFullNameSuggestion($name);
                    if ($sanitized !== null) {
                        return ['suggested_value' => $sanitized, 'confidence' => 0.78, 'source' => 'raw_text'];
                    }
                }
            }
            return ['suggested_value' => null, 'confidence' => 0.0, 'source' => 'none'];

        case 'date_of_birth':
            $dobLabelPattern = '/(?:DOB|Date\s+of\s+Birth|Birth\s+Date|à¤œà¤¨à¥à¤®\s*à¤¤à¤¿à¤¥à¤¿|à¤œà¤¨à¥à¤®\s*à¤¦à¤¿à¤¨à¤¾à¤‚à¤•|à¤œà¤¨à¥à¤®\s*à¤¤à¤¾à¤°à¥€à¤–|à¤œà¤¨à¥à¤®à¤¤à¤¾à¤°à¥€à¤–)\s*[:\-]*\s*([^\r\n]+)/ui';
            if (preg_match($dobLabelPattern, $text, $m)) {
                $v = $this->cleanExtractedLabelValue($m[1] ?? '');
                $v = preg_replace('/\b(à¤¸à¥‹à¤®à¤µà¤¾à¤°|à¤®à¤‚à¤—à¤³à¤µà¤¾à¤°|à¤¬à¥à¤§à¤µà¤¾à¤°|à¤—à¥à¤°à¥à¤µà¤¾à¤°|à¤¶à¥à¤•à¥à¤°à¤µà¤¾à¤°|à¤¶à¤¨à¤¿à¤µà¤¾à¤°|à¤°à¤µà¤¿à¤µà¤¾à¤°)\b/u', ' ', $v);
                $v = preg_replace('/\s*à¤¦à¤¿\.?\s*/u', ' ', $v);
                $v = trim(preg_replace('/\s+/u', ' ', $v));
                $v = OcrNormalize::normalizeDigits($v);
                $norm = OcrNormalize::normalizeDate($v);
                if ($norm !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $norm)) {
                    return ['suggested_value' => $norm, 'confidence' => 0.74, 'source' => 'raw_text'];
                }
                if (preg_match('/(\d{1,2})[\-\/\s\.]+(\d{1,2})[\-\/\s\.]+(\d{4})/', $v, $dm)) {
                    $d = str_pad($dm[1], 2, '0', STR_PAD_LEFT);
                    $mo = str_pad($dm[2], 2, '0', STR_PAD_LEFT);
                    $y = $dm[3];
                    if ((int) $d >= 1 && (int) $d <= 31 && (int) $mo >= 1 && (int) $mo <= 12) {
                        return ['suggested_value' => "{$y}-{$mo}-{$d}", 'confidence' => 0.72, 'source' => 'raw_text'];
                    }
                }
                if ($v !== '' && strlen($v) <= 30) {
                    return ['suggested_value' => $v, 'confidence' => 0.60, 'source' => 'raw_text'];
                }
            }
            if (preg_match('/(?:à¤œà¤¨à¥à¤®\s*à¤¤à¤¾à¤°à¥€à¤–|à¤œà¤¨à¥à¤®à¤¤à¤¾à¤°à¥€à¤–|à¤¦à¤¿\.)\s*[^\dà¥¦-à¥¯]*(?:à¤¸à¥‹à¤®à¤µà¤¾à¤°|à¤®à¤‚à¤—à¤³à¤µà¤¾à¤°|à¤¬à¥à¤§à¤µà¤¾à¤°|à¤—à¥à¤°à¥à¤µà¤¾à¤°|à¤¶à¥à¤•à¥à¤°à¤µà¤¾à¤°|à¤¶à¤¨à¤¿à¤µà¤¾à¤°|à¤°à¤µà¤¿à¤µà¤¾à¤°)?\s*[^\dà¥¦-à¥¯]*([à¥¦-à¥¯0-9]{1,2})[\s\.\-]+([à¥¦-à¥¯0-9]{1,2}|[^\s\r\n]+)[\s\.\-]+([à¥¦-à¥¯0-9]{4})/u', $text, $m)) {
                $d = OcrNormalize::normalizeDigits($m[1] ?? '');
                $mo = trim((string) ($m[2] ?? ''));
                $yr = OcrNormalize::normalizeDigits($m[3] ?? '');
                $monthMap = [
                    'à¤œà¤¾à¤¨à¥‡à¤µà¤¾à¤°à¥€' => '01', 'à¤«à¥‡à¤¬à¥à¤°à¥à¤µà¤¾à¤°à¥€' => '02', 'à¤®à¤¾à¤°à¥à¤š' => '03', 'à¤à¤ªà¥à¤°à¤¿à¤²' => '04',
                    'à¤®à¥‡' => '05', 'à¤œà¥‚à¤¨' => '06', 'à¤œà¥à¤²à¥ˆ' => '07', 'à¤‘à¤—à¤¸à¥à¤Ÿ' => '08',
                    'à¤¸à¤ªà¥à¤Ÿà¥‡à¤‚à¤¬à¤°' => '09', 'à¤‘à¤•à¥à¤Ÿà¥‹à¤¬à¤°' => '10', 'à¤¨à¥‹à¤µà¥à¤¹à¥‡à¤‚à¤¬à¤°' => '11', 'à¤¡à¤¿à¤¸à¥‡à¤‚à¤¬à¤°' => '12',
                ];
                $mm = $monthMap[$mo] ?? (preg_match('/^\d{1,2}$/', $mo) ? str_pad($mo, 2, '0', STR_PAD_LEFT) : null);
                $d = str_pad(preg_replace('/\D+/', '', $d), 2, '0', STR_PAD_LEFT);
                $yr = preg_replace('/\D+/', '', $yr);
                if ($mm && strlen($yr) === 4 && (int) $d >= 1 && (int) $d <= 31 && (int) $yr >= 1950 && (int) $yr <= 2015) {
                    return ['suggested_value' => "{$yr}-{$mm}-{$d}", 'confidence' => 0.76, 'source' => 'raw_text'];
                }
            }
            if (preg_match('/\b(\d{1,2})[\-\/\s\.]+(\d{1,2})[\-\/\s\.]+(\d{4})\b/', $text, $m)) {
                $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
                $y = $m[3];
                if ((int) $d >= 1 && (int) $d <= 31 && (int) $mo >= 1 && (int) $mo <= 12 && (int) $y >= 1950 && (int) $y <= 2015) {
                    return ['suggested_value' => "{$y}-{$mo}-{$d}", 'confidence' => 0.68, 'source' => 'raw_text'];
                }
            }
            // Marathi: "à¤œà¤¨à¥à¤® à¤¤à¤¾à¤°à¥€à¤– ... à¥¦à¥® à¤‘à¤—à¤¸à¥à¤Ÿ à¥§à¥¯à¥¯à¥­"
            if (preg_match('/à¤œà¤¨à¥à¤®\s*à¤¤à¤¾à¤°à¥€à¤–[^\dà¥¦-à¥¯]*([à¥¦-à¥¯0-9]{1,2})\s+([^\s\r\n]+)\s+([à¥¦-à¥¯0-9]{4})/u', $text, $m)) {
                $day = OcrNormalize::normalizeDigits($m[1] ?? '');
                $mon = trim((string) ($m[2] ?? ''));
                $yr  = OcrNormalize::normalizeDigits($m[3] ?? '');

                $monthMap = [
                    'à¤œà¤¾à¤¨à¥‡à¤µà¤¾à¤°à¥€' => '01', 'à¤«à¥‡à¤¬à¥à¤°à¥à¤µà¤¾à¤°à¥€' => '02', 'à¤®à¤¾à¤°à¥à¤š' => '03', 'à¤à¤ªà¥à¤°à¤¿à¤²' => '04',
                    'à¤®à¥‡' => '05', 'à¤œà¥‚à¤¨' => '06', 'à¤œà¥à¤²à¥ˆ' => '07', 'à¤‘à¤—à¤¸à¥à¤Ÿ' => '08',
                    'à¤¸à¤ªà¥à¤Ÿà¥‡à¤‚à¤¬à¤°' => '09', 'à¤‘à¤•à¥à¤Ÿà¥‹à¤¬à¤°' => '10', 'à¤¨à¥‹à¤µà¥à¤¹à¥‡à¤‚à¤¬à¤°' => '11', 'à¤¡à¤¿à¤¸à¥‡à¤‚à¤¬à¤°' => '12',
                ];

                $mm = $monthMap[$mon] ?? null;

                // Basic sanity
                $day = str_pad(preg_replace('/\D+/', '', (string) $day), 2, '0', STR_PAD_LEFT);
                $yr  = preg_replace('/\D+/', '', (string) $yr);

                if ($mm && strlen($yr) === 4 && (int) $day >= 1 && (int) $day <= 31) {
                    return ['suggested_value' => "{$yr}-{$mm}-{$day}", 'confidence' => 0.76, 'source' => 'raw_text'];
                }

                // If month not recognized, return a safe raw date string (still useful as suggestion)
                $rawDob = trim(($m[1] ?? '') . ' ' . ($m[2] ?? '') . ' ' . ($m[3] ?? ''));
                $rawDob = preg_replace('/\s+/u', ' ', $rawDob);
                if ($rawDob !== '') {
                    return ['suggested_value' => $rawDob, 'confidence' => 0.60, 'source' => 'raw_text'];
                }
            }
            return ['suggested_value' => null, 'confidence' => 0.0, 'source' => 'none'];
        case 'gender':
            // Day-28: Detect gender from contextual Marathi markers (stronger signal than direct words)
            // "à¤®à¥à¤²à¥€à¤šà¥‡ à¤¨à¤¾à¤‚à¤µ" / "à¤®à¥à¤²à¥€à¤šà¥‡ à¤¨à¤¾à¤µ" / "à¤•à¤¨à¥à¤¯à¤¾" -> Female
            // "à¤®à¥à¤²à¤¾à¤šà¥‡ à¤¨à¤¾à¤‚à¤µ" / "à¤®à¥à¤²à¤¾à¤šà¥‡ à¤¨à¤¾à¤µ" / "à¤ªà¥à¤¤à¥à¤°" -> Male
            if (preg_match('/à¤®à¥à¤²à¥€à¤šà¥‡\s*(à¤¨à¤¾à¤‚à¤µ|à¤¨à¤¾à¤µ)/u', $text) || preg_match('/\bà¤•à¤¨à¥à¤¯à¤¾\b/u', $text)) {
                return ['suggested_value' => 'Female', 'confidence' => 0.75, 'source' => 'raw_text'];
            }
            if (preg_match('/à¤®à¥à¤²à¤¾à¤šà¥‡\s*(à¤¨à¤¾à¤‚à¤µ|à¤¨à¤¾à¤µ)/u', $text) || preg_match('/\bà¤ªà¥à¤¤à¥à¤°\b/u', $text)) {
                return ['suggested_value' => 'Male', 'confidence' => 0.75, 'source' => 'raw_text'];
            }

            if (preg_match('/(?:Gender|Sex)\s*[:\-]*\s*(\w+)/ui', $text, $m)) {
                $g = trim($m[1] ?? '');
                if (stripos($g, 'male') !== false || $g === 'M') {
                    return ['suggested_value' => 'Male', 'confidence' => 0.74, 'source' => 'raw_text'];
                }
                if (stripos($g, 'female') !== false || $g === 'F') {
                    return ['suggested_value' => 'Female', 'confidence' => 0.74, 'source' => 'raw_text'];
                }
            }
            $patterns = [
                '/\bmale\b/u'   => 'Male',
                '/\bfemale\b/u' => 'Female',
                '/\b(m)\b/u'    => 'Male',
                '/\b(f)\b/u'    => 'Female',
                '/\bà¤ªà¥à¤°à¥à¤·\b/u'  => 'Male',
                '/\bà¤¸à¥à¤¤à¥à¤°à¥€\b/u' => 'Female',
            ];
            foreach ($patterns as $re => $val) {
                if (preg_match($re, $textLower)) {
                    return ['suggested_value' => $val, 'confidence' => 0.72, 'source' => 'raw_text'];
                }
            }
            return ['suggested_value' => null, 'confidence' => 0.0, 'source' => 'none'];

        case 'primary_contact_number':
            $phoneCandidates = $this->getPhoneCandidatesFromRawText($text);
            if ($phoneCandidates !== []) {
                $best = $phoneCandidates[0];
                return ['suggested_value' => $best['value'], 'confidence' => (float) $best['confidence'], 'source' => (string) ($best['source'] ?? 'raw_text')];
            }
            return ['suggested_value' => null, 'confidence' => 0.0, 'source' => 'none'];

        case 'religion':
            $parsed = $this->parseCasteLine($text);
            if ($parsed !== null && ($parsed['religion'] ?? '') !== '') {
                return ['suggested_value' => $parsed['religion'], 'confidence' => 0.78, 'source' => 'raw_text'];
            }
            if (preg_match('/(?:à¤§à¤°à¥à¤®|Religion)\s*[:\-]*\s*([^\r\n]+)/ui', $text, $m)) {
                $v = $this->cleanExtractedLabelValue($m[1] ?? '');
                $v = $this->stripParentheticals($v);
                if (preg_match('/[\-\/]/u', $v)) {
                    $split = $this->splitReligionCaste($v);
                    $v = $split['religion'] ?? $v;
                }
                if ($v !== '' && mb_strlen($v) <= 200) {
                    return ['suggested_value' => $v === 'à¤¹à¤¿à¤‚à¤¦à¥' ? 'à¤¹à¤¿à¤‚à¤¦à¥‚' : $v, 'confidence' => 0.72, 'source' => 'raw_text'];
                }
            }
            if (preg_match('/\b(à¤¹à¤¿à¤‚à¤¦à¥‚|à¤¬à¥Œà¤¦à¥à¤§|à¤œà¥ˆà¤¨|à¤®à¥à¤¸à¥à¤²à¤¿à¤®|à¤–à¥à¤°à¤¿à¤¶à¥à¤šà¤¨|à¤‡à¤¸à¥à¤²à¤¾à¤®|à¤¹à¤¿à¤‚à¤¦à¥|Hindu|Muslim|Christian|Buddhist|Jain)\b/ui', $text, $m)) {
                $v = $m[1];
                return ['suggested_value' => $v === 'à¤¹à¤¿à¤‚à¤¦à¥' ? 'à¤¹à¤¿à¤‚à¤¦à¥‚' : $v, 'confidence' => 0.72, 'source' => 'raw_text'];
            }
            return ['suggested_value' => null, 'confidence' => 0.0, 'source' => 'none'];

        case 'caste':
            $parsed = $this->parseCasteLine($text);
            if ($parsed !== null && ($parsed['caste'] ?? '') !== '') {
                $canonical = $this->resolveCasteToCanonical($parsed['caste']);
                if ($canonical !== null) {
                    return ['suggested_value' => $canonical, 'confidence' => 0.78, 'source' => 'raw_text'];
                }
            }
            if (preg_match('/(?:à¤œà¤¾à¤¤|à¤œà¤¾à¤¤à¥€|Caste|Community)\s*[:\-]*\s*([^\r\n]+)/ui', $text, $m)) {
                $lineVal = trim($m[1] ?? '');
                if ($lineVal !== '' && !$this->lineContainsNegativeKeyword($lineVal)) {
                    $v = $this->cleanExtractedLabelValue($lineVal);
                    $v = $this->stripParentheticals($v);
                    if (preg_match('/[\-\/]/u', $v)) {
                        $split = $this->splitReligionCaste($v);
                        $v = $split['caste'] ?? $v;
                    }
                    if ($v !== '' && mb_strlen($v) <= 200) {
                        $canonical = $this->resolveCasteToCanonical($v);
                        if ($canonical !== null) {
                            return ['suggested_value' => $canonical, 'confidence' => 0.72, 'source' => 'raw_text'];
                        }
                    }
                }
            }
            if (preg_match('/(?:^|[^\p{L}])(à¤®à¤°à¤¾à¤ à¤¾|à¤¬à¥à¤°à¤¾à¤¹à¥à¤®à¤£|à¤•à¥à¤·à¤¤à¥à¤°à¤¿à¤¯|à¤°à¤¾à¤œà¤ªà¥‚à¤¤|à¤•à¥à¤£à¤¬à¥€|à¤®à¤¾à¤²à¥€|à¤§à¤‚à¤—à¤°|à¤¤à¥‡à¤²à¥€|à¤²à¥‹à¤¹à¤¾à¤°|à¤¸à¥‹à¤¨à¤¾à¤°|à¤•à¥‹à¤²à¥€|à¤²à¤¿à¤‚à¤—à¤¾à¤¯à¤¤|à¤­à¤‚à¤¡à¤¾à¤°à¥€)(?:[^\p{L}]|$)/u', $text, $m)) {
                $canonical = $this->resolveCasteToCanonical($m[1]);
                if ($canonical !== null) {
                    return ['suggested_value' => $canonical, 'confidence' => 0.72, 'source' => 'raw_text'];
                }
            }
            return ['suggested_value' => null, 'confidence' => 0.0, 'source' => 'none'];

        case 'sub_caste':
            if (preg_match('/(?:à¤‰à¤ªà¤œà¤¾à¤¤|à¤‰à¤ª à¤œà¤¾à¤¤|à¤ªà¥‹à¤Ÿà¤œà¤¾à¤¤|Sub\s*[Cc]aste|Subcaste)\s*[:\-]*\s*([^\r\n]+)/ui', $text, $m)) {
                $v = $this->cleanExtractedLabelValue($m[1] ?? '');
                if ($v !== '' && mb_strlen($v) <= 200) {
                    return ['suggested_value' => $v, 'confidence' => 0.74, 'source' => 'raw_text'];
                }
            }
            $parsed = $this->parseCasteLine($text);
            if ($parsed !== null && ($parsed['sub_caste'] ?? '') !== '') {
                return ['suggested_value' => $parsed['sub_caste'], 'confidence' => 0.72, 'source' => 'raw_text'];
            }
            return ['suggested_value' => null, 'confidence' => 0.0, 'source' => 'none'];

        default:
            return ['suggested_value' => null, 'confidence' => 0.0, 'source' => 'none'];
    }
}

    /**
     * Clean value extracted after a label (trim, collapse spaces, preserve Devanagari marks).
     */
    private function cleanExtractedLabelValue(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value);
        $value = preg_replace('/^[^\p{L}\p{N}\p{M}]+/u', '', $value);
        $value = preg_replace('/[^\p{L}\p{N}\p{M}]+$/u', '', $value);
        return trim($value);
    }

    private function stripParentheticals(string $value): string
    {
        return trim(preg_replace('/\s*\([^)]*\)\s*/u', ' ', $value));
    }

    /** Negative keywords: lines or tokens containing these must not be used for caste extraction. */
    private function getCasteNegativeKeywords(): array
    {
        return [
            'à¤µà¤¡à¤¿à¤²à¤¾à¤‚à¤šà¥‡', 'à¤†à¤ˆà¤šà¥‡', 'à¤­à¤¾à¤Š', 'à¤¬à¤¹à¤¿à¤£', 'à¤®à¤¾à¤®à¤¾', 'à¤•à¤¾à¤•à¤¾', 'à¤¨à¤¾à¤¤à¥‡à¤µà¤¾à¤ˆà¤•', 'à¤ªà¤¤à¥à¤¤à¤¾', 'à¤®à¥‹à¤¬à¤¾à¤ˆà¤²', 'à¤«à¥‹à¤¨', 'à¤¶à¤¿à¤•à¥à¤·à¤£', 'à¤¨à¥‹à¤•à¤°à¥€',
            'Father', 'Mother', 'Brother', 'Sister', 'Address', 'Contact', 'Education', 'Occupation', 'Relatives',
        ];
    }

    private function lineContainsNegativeKeyword(string $line): bool
    {
        $lineLower = mb_strtolower($line, 'UTF-8');
        foreach ($this->getCasteNegativeKeywords() as $kw) {
            if (mb_strpos($lineLower, mb_strtolower($kw, 'UTF-8')) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse a caste line like "à¤œà¤¾à¤¤ : à¤¹à¤¿à¤‚à¤¦à¥‚ à¤®à¤°à¤¾à¤ à¤¾ {96 à¤•à¥à¤³à¥€}" or "à¤œà¤¾à¤¤: à¤¹à¤¿à¤‚à¤¦à¥‚ - à¤®à¤°à¤¾à¤ à¤¾ (96 à¤•à¥à¤³à¥€)".
     * Returns ['religion' => ?, 'caste' => ?, 'sub_caste' => ?] (any can be null).
     * Skips lines that contain negative keywords (e.g. à¤µà¤¡à¤¿à¤²à¤¾à¤‚à¤šà¥‡ à¤¨à¤¾à¤µ).
     */
    private function parseCasteLine(string $text): ?array
    {
        if (!preg_match_all('/(?:à¤œà¤¾à¤¤|à¤œà¤¾à¤¤à¥€|Caste|Community)\s*[:\-]*\s*([^\r\n]+)/ui', $text, $matches, PREG_SET_ORDER)) {
            return null;
        }
        $line = null;
        foreach ($matches as $m) {
            $candidateLine = trim($m[1] ?? '');
            if ($candidateLine !== '' && !$this->lineContainsNegativeKeyword($candidateLine)) {
                $line = $candidateLine;
                break;
            }
        }
        if ($line === null || $line === '') {
            return null;
        }

        $subCaste = null;
        if (preg_match('/[\{\ï¼ˆ]([^}\ï¼‰]+)[\}\ï¼‰]/u', $line, $b)) {
            $subCaste = $this->cleanExtractedLabelValue($b[1] ?? '');
            if ($subCaste === '') {
                $subCaste = null;
            }
        }
        $rest = preg_replace('/\s*[\{\ï¼ˆ][^}\ï¼‰]*[\}\ï¼‰]\s*/u', ' ', $line);
        $rest = trim(preg_replace('/\s+/u', ' ', $rest));

        $knownReligions = ['à¤¹à¤¿à¤‚à¤¦à¥‚', 'à¤¹à¤¿à¤‚à¤¦à¥', 'à¤®à¥à¤¸à¥à¤²à¤¿à¤®', 'à¤¬à¥Œà¤¦à¥à¤§', 'à¤œà¥ˆà¤¨', 'à¤–à¥à¤°à¤¿à¤¶à¥à¤šà¤¨', 'à¤‡à¤¸à¥à¤²à¤¾à¤®', 'Hindu', 'Muslim', 'Christian', 'Buddhist', 'Jain'];
        $tokens = preg_split('/[\s\-]+/u', $rest);
        $tokens = array_values(array_filter(array_map('trim', $tokens)));

        $religion = null;
        $caste = null;
        foreach ($tokens as $t) {
            if ($t === '') {
                continue;
            }
            foreach ($knownReligions as $r) {
                if (stripos($t, $r) !== false || $t === $r) {
                    $religion = $t === 'à¤¹à¤¿à¤‚à¤¦à¥' ? 'à¤¹à¤¿à¤‚à¤¦à¥‚' : $t;
                    break 2;
                }
            }
        }
        $negativeKeywords = $this->getCasteNegativeKeywords();
        foreach ($tokens as $t) {
            if ($t === '' || $t === $religion) {
                continue;
            }
            $tLower = mb_strtolower($t, 'UTF-8');
            $isNegative = false;
            foreach ($negativeKeywords as $kw) {
                if ($tLower === mb_strtolower($kw, 'UTF-8') || mb_strpos($tLower, mb_strtolower($kw, 'UTF-8')) !== false) {
                    $isNegative = true;
                    break;
                }
            }
            if (!$isNegative) {
                $caste = $t;
                break;
            }
        }

        return [
            'religion' => $religion,
            'caste' => $caste,
            'sub_caste' => $subCaste,
        ];
    }

    /**
     * Extract up to 3 phone candidates from raw text with scoring: prefer numbers near labels.
     *
     * @return list<array{value: string, confidence: float, source: string}>
     */
    private function getPhoneCandidatesFromRawText(string $text): array
    {
        $labelPattern = '/(?:à¤®à¥‹\.|à¤®à¥‹à¤¬à¤¾à¤ˆà¤²|à¤¸à¤‚à¤ªà¤°à¥à¤•|à¤«à¥‹à¤¨|Contact|Mobile|Phone|à¤«à¥‹à¤¨\s*à¤¨à¤‚à¤¬à¤°)\s*[:\-]*/ui';
        $labels = [];
        if (preg_match_all($labelPattern, $text, $labelMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($labelMatches[0] as $lm) {
                $labels[] = $lm[1];
            }
        }
        $nearLabelRadius = 50;

        $candidates = [];
        $seen = [];
        if (preg_match_all('/[6-9]\d{9}/u', $text, $numMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($numMatches[0] as $nm) {
                $num = $nm[0];
                $pos = $nm[1];
                $prefix = substr($text, max(0, $pos - 4), 4);
                $digitsOnly = preg_replace('/\D/', '', $prefix . $num);
                if (strlen($digitsOnly) >= 12 && substr($digitsOnly, 0, 2) === '91') {
                    $num = substr($digitsOnly, 2, 10);
                } elseif (strlen($num) > 10) {
                    $num = substr($num, 0, 10);
                }
                if (!preg_match('/^[6-9]\d{9}$/', $num) || isset($seen[$num])) {
                    continue;
                }
                $seen[$num] = true;

                $score = 0.65;
                foreach ($labels as $labelPos) {
                    $dist = abs($pos - $labelPos);
                    if ($dist <= $nearLabelRadius) {
                        $score = max($score, 0.82 - (int) ($dist / 80));
                        break;
                    }
                }
                $candidates[] = ['value' => $num, 'confidence' => $score, 'source' => 'raw_text', '_pos' => $pos];
            }
        }
        usort($candidates, static function ($a, $b) {
            $c = $b['confidence'] <=> $a['confidence'];
            if ($c !== 0) {
                return $c;
            }
            return ($a['_pos'] ?? 0) <=> ($b['_pos'] ?? 0);
        });
        $out = [];
        foreach (array_slice($candidates, 0, 3) as $c) {
            unset($c['_pos']);
            $out[] = $c;
        }
        return $out;
    }

    /**
     * Religion allowed-list: canonical label (DB display value) => synonyms for OCR match.
     * Only fill when raw text contains one of these; no guessing.
     */
    private function getReligionAllowedListMap(): array
    {
        return [
            'Hindu' => ['à¤¹à¤¿à¤‚à¤¦à¥‚', 'à¤¹à¤¿à¤¨à¥à¤¦à¥‚', 'à¤¹à¤¿à¤‚à¤¦à¥', 'Hindu'],
            'Muslim' => ['à¤®à¥à¤¸à¥à¤²à¤¿à¤®', 'Muslim', 'Islam', 'Islamic', 'à¤‡à¤¸à¥à¤²à¤¾à¤®'],
            'Christian' => ['à¤–à¥à¤°à¤¿à¤¶à¥à¤šà¤¨', 'Christian'],
            'Buddhist' => ['à¤¬à¥Œà¤¦à¥à¤§', 'Buddhist'],
            'Jain' => ['à¤œà¥ˆà¤¨', 'Jain'],
            'Sikh' => ['à¤¶à¥€à¤–', 'Sikh'],
        ];
    }

    /**
     * Search raw OCR text for any religion synonym from allowed list. Returns candidates with source=allowed_list.
     *
     * @return list<array{value: string, confidence: float, source: string}>
     */
    private function matchReligionAllowedList(string $rawText): array
    {
        $map = $this->getReligionAllowedListMap();
        $candidates = [];
        foreach ($map as $canonical => $synonyms) {
            foreach ($synonyms as $syn) {
                $syn = trim($syn);
                if ($syn === '') {
                    continue;
                }
                $pattern = preg_quote($syn, '/');
                if (preg_match('/\b' . $pattern . '\b/ui', $rawText)) {
                    $candidates[] = ['value' => $canonical, 'confidence' => 0.82, 'source' => 'allowed_list'];
                    break;
                }
                if (preg_match('/(?:^|[^\p{L}])' . $pattern . '(?:[^\p{L}]|$)/u', $rawText)) {
                    $candidates[] = ['value' => $canonical, 'confidence' => 0.82, 'source' => 'allowed_list'];
                    break;
                }
            }
        }
        return $candidates;
    }

    private const CASTE_CACHE_KEY = 'ocr_caste_canonical_map';
    private const CASTE_CACHE_TTL_SECONDS = 3600;

    /**
     * Caste synonym map (Devanagari / Hindi) => canonical DB label. Used when DB label is English.
     */
    private function getCasteSynonymMap(): array
    {
        return [
            'à¤®à¤°à¤¾à¤ à¤¾' => 'Maratha', 'à¤¬à¥à¤°à¤¾à¤¹à¥à¤®à¤£' => 'Brahmin', 'à¤•à¥à¤·à¤¤à¥à¤°à¤¿à¤¯' => 'Kshatriya', 'à¤µà¥ˆà¤¶à¥à¤¯' => 'Teli',
            'à¤°à¤¾à¤œà¤ªà¥‚à¤¤' => 'Rajput', 'à¤•à¥à¤£à¤¬à¥€' => 'Maratha', 'à¤®à¤¾à¤²à¥€' => 'Mali', 'à¤§à¤‚à¤—à¤°' => 'Dhangar',
            'à¤šà¤¾à¤‚à¤­à¤¾à¤°' => 'Chambhar', 'à¤¤à¥‡à¤²à¥€' => 'Teli', 'à¤²à¥‹à¤¹à¤¾à¤°' => 'Lohar', 'à¤¸à¥‹à¤¨à¤¾à¤°' => 'Sonar',
            'à¤¸à¥à¤¤à¤¾à¤°' => 'Sutar', 'à¤•à¥‹à¤²à¥€' => 'Koli', 'à¤²à¤¿à¤‚à¤—à¤¾à¤¯à¤¤' => 'Lingayat', 'à¤­à¤‚à¤¡à¤¾à¤°à¥€' => 'Bhandari',
            'à¤¸à¥à¤¨à¥à¤¨à¥€' => 'Sunni', 'à¤¶à¤¿à¤¯à¤¾' => 'Shia', 'à¤®à¤¹à¤¾à¤°' => 'Mahar', 'à¤¦à¤¿à¤—à¤‚à¤¬à¤°' => 'Digambar',
            'à¤¶à¥à¤µà¥‡à¤¤à¤¾à¤‚à¤¬à¤°' => 'Shwetambar', 'à¤œà¤¾à¤Ÿ' => 'Jat',
        ];
    }

    /**
     * Cached map: normalized lookup key => canonical caste label. From DB + synonyms.
     *
     * @return array<string, string> normalizedKey => canonicalLabel
     */
    private function getCasteAllowedListCache(): array
    {
        return Cache::remember(self::CASTE_CACHE_KEY, self::CASTE_CACHE_TTL_SECONDS, function () {
            $map = [];
            $castes = Caste::where('is_active', true)->get();
            foreach ($castes as $c) {
                $label = (string) $c->label;
                $key = (string) $c->key;
                $map[$this->normalizeCasteKey($label)] = $label;
                $map[$this->normalizeCasteKey($key)] = $label;
            }
            foreach ($this->getCasteSynonymMap() as $syn => $canonical) {
                $map[$this->normalizeCasteKey($syn)] = $canonical;
            }
            return $map;
        });
    }

    private function normalizeCasteKey(string $value): string
    {
        $v = trim(preg_replace('/\s+/u', ' ', $value));
        $v = preg_replace('/[^\p{L}\p{N}]/u', '', $v);
        return mb_strtolower($v, 'UTF-8');
    }

    /**
     * Resolve OCR-extracted caste value to canonical DB caste label, or null if not in allowed list.
     */
    public function resolveCasteToCanonical(string $ocrValue): ?string
    {
        $ocrValue = trim($ocrValue);
        if ($ocrValue === '') {
            return null;
        }
        $map = $this->getCasteAllowedListCache();
        $norm = $this->normalizeCasteKey($ocrValue);
        return $map[$norm] ?? null;
    }

    /** Cached: canonical caste label => list of search terms (label, key, synonyms). */
    private const CASTE_SEARCH_TERMS_CACHE_KEY = 'ocr_caste_search_terms';
    private const CASTE_SEARCH_TERMS_CACHE_TTL = 3600;

    private function getCasteSearchTermsByCanonical(): array
    {
        return Cache::remember(self::CASTE_SEARCH_TERMS_CACHE_KEY, self::CASTE_SEARCH_TERMS_CACHE_TTL, function () {
            $byCanonical = [];
            foreach (Caste::where('is_active', true)->get() as $c) {
                $label = (string) $c->label;
                $byCanonical[$label] = array_merge($byCanonical[$label] ?? [], [$label, $c->key]);
            }
            foreach ($this->getCasteSynonymMap() as $syn => $canonical) {
                $byCanonical[$canonical] = array_merge($byCanonical[$canonical] ?? [], [$syn]);
            }
            return $byCanonical;
        });
    }

    /**
     * Search raw text for caste terms that match DB; return candidates with canonical label, source=allowed_list_db.
     *
     * @return list<array{value: string, confidence: float, source: string}>
     */
    private function matchCasteAllowedList(string $rawText): array
    {
        $candidates = [];
        $termsByCanonical = $this->getCasteSearchTermsByCanonical();
        foreach ($termsByCanonical as $canonicalLabel => $terms) {
            $found = false;
            foreach (array_unique($terms) as $term) {
                if ($term === '') {
                    continue;
                }
                $pattern = preg_quote($term, '/');
                if (preg_match('/(?:^|[^\p{L}])' . $pattern . '(?:[^\p{L}]|$)/u', $rawText) || preg_match('/\b' . $pattern . '\b/ui', $rawText)) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $candidates[] = ['value' => $canonicalLabel, 'confidence' => 0.80, 'source' => 'allowed_list_db'];
            }
        }
        return $candidates;
    }

    /**
     * Get religion(s) for a canonical caste label (DB dependency). For casteâ†’religion fallback.
     *
     * @return array{religions: list<string>, single: bool}
     */
    public function getReligionFromCasteDependency(string $canonicalCasteLabel): array
    {
        $caste = Caste::where('is_active', true)->where('label', $canonicalCasteLabel)->first();
        if (! $caste) {
            $norm = $this->normalizeCasteKey($canonicalCasteLabel);
            $caste = Caste::where('is_active', true)->get()->first(fn ($c) => $this->normalizeCasteKey($c->key) === $norm);
        }
        if (!$caste || !$caste->religion_id) {
            return ['religions' => [], 'single' => false];
        }
        $religion = Religion::where('id', $caste->religion_id)->where('is_active', true)->first();
        if (!$religion) {
            return ['religions' => [], 'single' => false];
        }
        $label = (string) $religion->label;
        return ['religions' => [$label], 'single' => true];
    }

    /**
     * Debug logging (only when enabled).
     */
    private function logDebug(string $fieldKey, $currentValue, $suggestedValue, string $reason, float $confidence = 0.0, string $source = 'none'): void
    {
        if (!$this->debug) {
            return;
        }

        Log::debug('OCR Suggestion', [
            'field_key' => $fieldKey,
            'current_value' => $currentValue,
            'suggested_value' => $suggestedValue,
            'confidence' => $confidence,
            'source' => $source,
            'reason' => $reason,
        ]);
    }
}

