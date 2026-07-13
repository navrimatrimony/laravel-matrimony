<?php

namespace App\Services\Intake;

use App\Services\Ocr\OcrNormalize;

/**
 * Benchmark-only community field parsing from OCR text (mirrors parser patterns, not the parser itself).
 */
class OcrEnsembleBenchmarkCommunityExtractor
{
    /**
     * @param  list<string>  $lines
     * @return array{religion: ?string, caste: ?string, sub_caste: ?string}
     */
    public function extract(array $lines): array
    {
        $religion = $this->normalizeReligion($this->labelValue($lines, ['धर्म', 'धम', 'Religion']));
        $jatiRaw = $this->labelValue($lines, ['जात', 'Caste', 'Community']);
        $subCaste = $this->normalizeSubCaste($this->labelValue($lines, ['पोटजात', 'उपजात', 'Sub caste', 'Sub-caste', 'Subcaste']));

        if ($jatiRaw !== null) {
            $parsed = $this->parseJatiLine($jatiRaw);
            $religion = $religion ?? $parsed['religion'];
            $caste = $parsed['caste'] ?? null;
            $subCaste = $subCaste ?? $parsed['sub_caste'];
        }

        foreach ($lines as $line) {
            if ($religion !== null && $caste !== null) {
                break;
            }
            if (preg_match('/हिंद[ुू]\s*[-–]\s*मराठा/ui', $line) === 1) {
                $religion = $religion ?? 'Hindu';
                $caste = $caste ?? 'Maratha';
            }
        }

        return [
            'religion' => $religion,
            'caste' => $caste,
            'sub_caste' => $subCaste,
        ];
    }

    /**
     * @return array{religion: ?string, caste: ?string, sub_caste: ?string}
     */
    private function parseJatiLine(string $raw): array
    {
        $v = OcrNormalize::normalizeDigits(trim($raw));
        $religion = null;
        $caste = null;
        $subCaste = null;

        if (preg_match('/हिंद[ुू]\s*[-–]\s*मराठा(?:\s*\(([०-९0-9]+)\s*(?:कुळी|क्‌ळी|कळी)\))?/ui', $v, $m)) {
            $religion = 'Hindu';
            $caste = 'Maratha';
            if (! empty($m[1])) {
                $subCaste = ((int) $m[1]).' कुळी';
            }
        } elseif (preg_match('/हिंद[ुू]\s*[-–]\s*(.+)$/ui', $v, $m)) {
            $religion = 'Hindu';
            $caste = $this->normalizeCaste(trim((string) $m[1]));
        } elseif (preg_match('/हिंद[ुू]/ui', $v)) {
            $religion = 'Hindu';
            $caste = $this->normalizeCaste(preg_replace('/हिंद[ुू]\s*[-–]?\s*/ui', '', $v) ?? $v);
        } else {
            $caste = $this->normalizeCaste($v);
        }

        if ($subCaste === null) {
            $subCaste = $this->extractKuli($v);
        }

        return [
            'religion' => $religion,
            'caste' => $caste,
            'sub_caste' => $subCaste,
        ];
    }

    private function extractKuli(string $value): ?string
    {
        if (preg_match('/(\d{1,3})\s*(?:कुळी|क्‌ळी|कळी)/u', OcrNormalize::normalizeDigits($value), $m)) {
            $count = (int) $m[1];
            if ($count > 0 && $count <= 200) {
                return $count.' कुळी';
            }
        }

        return null;
    }

  public function normalizeReligion(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = OcrNormalize::normalizeDigits(trim($value));
        if (preg_match('/हिंद[ुू]|hindu/i', $v)) {
            return 'Hindu';
        }
        if (preg_match('/मुस्लिम|muslim/i', $v)) {
            return 'Muslim';
        }
        if (preg_match('/जैन|jain/i', $v)) {
            return 'Jain';
        }
        if (preg_match('/बौद्ध|buddhist/i', $v)) {
            return 'Buddhist';
        }
        if (preg_match('/ख्रिश्चन|christian/i', $v)) {
            return 'Christian';
        }

        return trim($v);
    }

    public function normalizeCaste(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = OcrNormalize::normalizeDigits(trim($value));
        $v = str_replace('मटाठा', 'मराठा', $v);
        if (preg_match('/मराठा|maratha/i', $v)) {
            return 'Maratha';
        }
        if (preg_match('/कुणबी|kunbi/i', $v)) {
            return 'Kunbi';
        }
        if (preg_match('/मराठा/i', $v)) {
            return 'Maratha';
        }

        $tokens = preg_split('/[\s\-–:,]+/u', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            if (preg_match('/^(?:मराठा|maratha|कुणबी|kunbi)$/ui', $token)) {
                return ucfirst(strtolower($token)) === 'Maratha' || mb_stripos($token, 'मराठा') !== false ? 'Maratha' : trim($token);
            }
        }

        return trim($v);
    }

    private function normalizeSubCaste(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->extractKuli($value) ?? trim(OcrNormalize::normalizeDigits($value));
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $labels
     */
    private function labelValue(array $lines, array $labels): ?string
    {
        foreach ($lines as $index => $line) {
            foreach ($labels as $label) {
                $quoted = preg_quote($label, '/');
                if (preg_match('/(?:^|[\s,*•\-])(?:'.$quoted.')\s*(?::\s*-\s*|[:\-：]\s*|[८8]\s*|\s+)\s*(.+)$/ui', $line, $m) === 1) {
                    $value = trim((string) $m[1]);
                    if ($value !== '') {
                        return $this->truncateAtNextLabel($value);
                    }
                }
                if (preg_match('/(?:'.$quoted.')\s*[:：]?\s*$/ui', $line) === 1) {
                    $next = trim((string) ($lines[$index + 1] ?? ''));
                    if ($next !== '') {
                        return $this->truncateAtNextLabel($next);
                    }
                }
            }
        }

        return null;
    }

    private function truncateAtNextLabel(string $value): string
    {
        $stops = 'धर्म|जात|उपजात|पोटजात|जन्म|उंची|शिक्षण|नोकरी|व्यवसाय|मोबाईल|संपर्क';
        $parts = preg_split('/\s+(?:'.$stops.')\s*[:：]/ui', $value, 2);

        return trim((string) ($parts[0] ?? $value));
    }
}
