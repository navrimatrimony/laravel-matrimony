<?php

namespace App\Services\Intake\OcrEnsemble\Support;

use App\Services\Ocr\OcrNormalize;

/**
 * Production Phase 3 community field parsing from OCR text (mirrors parser patterns, not the parser itself).
 */
class OcrEnsembleCommunityExtractor
{
    /**
     * @param  list<string>  $lines
     * @return array{religion: ?string, caste: ?string, sub_caste: ?string}
     */
    public function extract(array $lines): array
    {
        $religion = $this->normalizeReligion($this->labelValue($lines, ['धर्म', 'धम', 'Religion']));
        $caste = null;
        $jatiRaw = $this->labelValue($lines, ['जात', 'जाति', 'कुल', 'कुळ', 'Caste', 'Community']);
        $subCaste = $this->normalizeSubCaste($this->labelValue($lines, ['पोटजात', 'उपजात', 'Sub caste', 'Sub-caste', 'Subcaste']));

        if ($jatiRaw !== null) {
            $parsed = $this->parseJatiLine($jatiRaw);
            $religion = $religion ?? $parsed['religion'];
            $caste = $parsed['caste'] ?? null;
            $subCaste = $subCaste ?? $parsed['sub_caste'];
        }

        // Compound OCR label धर्म-जात with mangled religion but clear Maratha caste.
        if ($religion === null) {
            foreach ($lines as $line) {
                if (preg_match('/धर्म\s*[-–]?\s*जात/ui', $line) === 1
                    && preg_match('/मराठा|maratha/ui', $line) === 1) {
                    $religion = 'Hindu';
                    $caste = $caste ?? 'Maratha';
                    break;
                }
            }
        }

        foreach ($lines as $line) {
            if ($religion !== null && $caste !== null) {
                break;
            }
            $glued = $this->parseGluedJatiHindu($line);
            if ($glued['religion'] !== null) {
                $religion = $religion ?? $glued['religion'];
                $caste = $caste ?? $glued['caste'];
                $subCaste = $subCaste ?? $glued['sub_caste'];
            }
            if (preg_match('/हिंद[ुू]\s*[-–]\s*मराठा/ui', $line) === 1) {
                $religion = $religion ?? 'Hindu';
                $caste = $caste ?? 'Maratha';
            }
        }

        if ($religion === null) {
            $religion = $this->inferReligionFromLooseTokens($lines);
        }

        if ($religion === null) {
            $religion = $this->inferReligionFromEnglishCast($lines);
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
    /**
     * Megapage OCR often glues जाति+हिंदू without colon: जातिहंदू मराठा (96 कुळी)
     *
     * @return array{religion: ?string, caste: ?string, sub_caste: ?string}
     */
    private function parseGluedJatiHindu(string $line): array
    {
        $v = OcrNormalize::normalizeDigits($line);
        if (preg_match(
            '/जात[िी]?ह(?:ि)?ंद[ुू]\s*[-–]?\s*(मराठा|maratha)?(?:\s*\(([०-९0-9]+)\s*(?:कुळी|क्‌ळी|कळी)\))?/ui',
            $v,
            $m
        ) !== 1) {
            return ['religion' => null, 'caste' => null, 'sub_caste' => null];
        }

        $sub = null;
        if (! empty($m[2])) {
            $sub = ((int) $m[2]).' कुळी';
        }

        return [
            'religion' => 'Hindu',
            'caste' => ! empty($m[1]) ? 'Maratha' : null,
            'sub_caste' => $sub,
        ];
    }

    /**
     * @param  list<string>  $lines
     */
    private function inferReligionFromLooseTokens(array $lines): ?string
    {
        foreach ($lines as $line) {
            $v = OcrNormalize::normalizeDigits($line);
            // OCR often drops matra: हहंद / हंदू near जात / गुरव / मराठा.
            if (preg_match('/जात\s*[:：\-–—.]+\s*ह[ह]?ंद[ुू]?/ui', $v) === 1
                || preg_match('/(?:धर्म|Religion)\s*[:：\-–—.]+\s*(?:हिंद|ह[ह]?ंद|Hindu)/ui', $v) === 1) {
                return 'Hindu';
            }
            if (preg_match('/\bHindu\b/ui', $v) === 1) {
                return 'Hindu';
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function inferReligionFromEnglishCast(array $lines): ?string
    {
        foreach ($lines as $index => $line) {
            if (preg_match('/\bCast\b\s*[:\-–—\s]*$/iu', trim($line)) === 1) {
                $next = trim((string) ($lines[$index + 1] ?? ''));
                $religion = $this->religionFromEnglishCastValue($next);
                if ($religion !== null) {
                    return $religion;
                }
            }

            if (preg_match('/\bCast\b\s*[:\-–—\s]+([A-Za-z][A-Za-z]+)/iu', $line, $matches) !== 1) {
                continue;
            }

            $religion = $this->religionFromEnglishCastValue(trim((string) ($matches[1] ?? '')));
            if ($religion !== null) {
                return $religion;
            }
        }

        return null;
    }

    private function religionFromEnglishCastValue(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/\bJain\b/i', $value)) {
            return 'Jain';
        }
        if (preg_match('/\bMuslim\b/i', $value)) {
            return 'Muslim';
        }
        if (preg_match('/\bChristian\b/i', $value)) {
            return 'Christian';
        }
        if (preg_match('/\bBuddhist\b/i', $value)) {
            return 'Buddhist';
        }
        if (preg_match('/\b(?:Ezhava|Maratha|Brahmin|Kunbi|Yadav|Lingayat|Gurav|Patil|Nair)\b/i', $value)) {
            return 'Hindu';
        }

        return null;
    }

    private function parseJatiLine(string $raw): array
    {
        $v = OcrNormalize::normalizeDigits(trim($raw));
        $religion = null;
        $caste = null;
        $subCaste = null;

        // OCR corruption: हहंद / हंद for हिंदू before caste dash.
        $v = preg_replace('/ह[ह]?ंद[ुू]?/ui', 'हिंदू', $v) ?? $v;

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
        if (preg_match('/हिंद[ुू]|ह[ह]?ंद[ुू]?|hindu/i', $v)) {
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

        // Never keep OCR garbage as a "religion" master value.
        return null;
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
