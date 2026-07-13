<?php

namespace App\Services\Intake\OcrEnsemble\Support;

/**
 * Shared OCR text line utilities for production field extraction.
 */
final class OcrEnsembleFieldTextSupport
{
    /**
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        $parts = preg_split('/\R+/u', $text) ?: [];
        $lines = [];
        foreach ($parts as $part) {
            $line = trim(preg_replace('/\s+/u', ' ', (string) $part) ?? '');
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $labels
     */
    public static function labelValue(array $lines, array $labels): ?string
    {
        foreach ($lines as $index => $line) {
            foreach ($labels as $label) {
                $quoted = preg_quote($label, '/');
                if (preg_match('/(?:^|[\s,*•\-])(?:'.$quoted.')\s*(?::\s*-\s*|[:\-：]\s*|[८8]\s*|\s+)\s*(.+)$/ui', $line, $matches) === 1) {
                    $value = trim((string) ($matches[1] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
                if (preg_match('/(?:'.$quoted.')\s*[:：]?\s*$/ui', $line) === 1) {
                    $next = trim((string) ($lines[$index + 1] ?? ''));
                    if ($next !== '') {
                        return $next;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    public static function extractEducation(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/शिक्षण|शैक्षणिक|education/ui', $line) !== 1) {
                continue;
            }
            if (preg_match('/(?:शिक्षण|शैक्षणिक\s*पात्रता|education)\s*(?::\s*-\s*|[:\-：]\s*|[८8]\s*|\s+)\s*(.+)$/ui', $line, $m) === 1) {
                $value = trim((string) $m[1]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach ($lines as $line) {
            if (preg_match('/\b(?:B\.?\s*E\.?|BE|B\.?\s*Tech|B\.?\s*Com|M\.?\s*Sc|MBA|MBBS|BAMS|BDS|MCA|BCA|SSC|HSC|Diploma)\b/ui', $line) === 1) {
                return trim($line);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    public static function extractOccupation(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/नोकरी|नौकरी|व्यवसाय|occupation|profession|designation|job/ui', $line) !== 1) {
                continue;
            }
            if (preg_match('/(?:नोकरी|नौकरी|व्यवसाय|occupation|profession|designation|job)\s*(?::\s*-\s*|[:\-：]\s*|[८8]\s*|\s+)\s*(.+)$/ui', $line, $m) === 1) {
                $value = trim((string) $m[1]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    public static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_bool($value)) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    public static function normalizeGender(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $lower = strtolower(trim($value));

        return in_array($lower, ['male', 'female', 'unknown'], true) ? $lower : trim($value);
    }
}
