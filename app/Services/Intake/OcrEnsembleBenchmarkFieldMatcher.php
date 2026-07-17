<?php

namespace App\Services\Intake;

use App\Services\Ocr\OcrNormalize;

class OcrEnsembleBenchmarkFieldMatcher
{
    public static function match(string $field, ?string $truth, ?string $prediction): bool
    {
        $truth = self::normalizeValue($field, $truth);
        $prediction = self::normalizeValue($field, $prediction);

        if ($truth === '') {
            return true;
        }

        if ($prediction === '') {
            return false;
        }

        if ($field === 'full_name') {
            if ($truth === $prediction) {
                return true;
            }
            similar_text($truth, $prediction, $percent);

            return $percent >= 92.0;
        }

        return $truth === $prediction;
    }

    private static function normalizeValue(string $field, ?string $value): string
    {
        $value = trim(OcrNormalize::normalizeDigits((string) ($value ?? '')));
        if ($value === '') {
            return '';
        }

        if ($field === 'primary_contact_number') {
            return preg_replace('/\D/u', '', $value) ?? '';
        }

        if (in_array($field, ['religion', 'caste', 'sub_caste'], true)) {
            return self::normalizeCommunityToken($field, $value);
        }

        if ($field === 'date_of_birth') {
            return $value;
        }

        $value = mb_strtolower($value, 'UTF-8');
        if ($field === 'full_name') {
            // Product Owner: Adv / Advocate / अॅड. / ॲड. are title forms, not name tokens.
            $value = preg_replace('/^(?:&\s*|adv\.?\s*|advocate\s+|अॅड\.?\s*|ॲड\.?\s*|अँड\.?\s*)+/u', '', $value) ?? $value;
            $value = preg_replace('/\s+(?:&|adv\.?|advocate|अॅड\.?|ॲड\.?|अँड\.?)\s+/u', ' ', $value) ?? $value;
        }
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;

        return trim($value);
    }

    private static function normalizeCommunityToken(string $field, string $value): string
    {
        $extractor = app(OcrEnsembleBenchmarkCommunityExtractor::class);
        if ($field === 'religion') {
            return strtolower((string) ($extractor->normalizeReligion($value) ?? $value));
        }
        if ($field === 'caste') {
            return strtolower((string) ($extractor->normalizeCaste($value) ?? $value));
        }

        $value = OcrNormalize::normalizeDigits($value);
        if (preg_match('/(\d{1,3})\s*(?:कुळी|क्‌ळी|कळी)/u', $value, $m)) {
            return ((int) $m[1]).' kuli';
        }

        return mb_strtolower(trim($value), 'UTF-8');
    }
}
