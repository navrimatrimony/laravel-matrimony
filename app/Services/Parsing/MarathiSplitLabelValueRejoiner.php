<?php

declare(strict_types=1);

namespace App\Services\Parsing;

/**
 * Rejoin AI/OCR output where Marathi biodata labels appear in one vertical block
 * and values in a second block (often prefixed with ":"). The normalized draft builder
 * expects inline "label : value" lines.
 */
final class MarathiSplitLabelValueRejoiner
{
    private const MIN_LABEL_LINES = 5;

    /** @var list<string> */
    private const LABELS = [
        'मुलाचे नांव', 'मुलाचे नाव', 'मुलीचे नांव', 'मुलीचे नाव', 'वधूचे नांव', 'वधूचे नाव',
        'जन्म तारीख', 'जन्मतारीख', 'जन्म दिनांक', 'जन्म वेळ', 'जन्मवेळ', 'जन्म ठिकाण', 'जन्म स्थळ',
        'धर्म', 'जात', 'कास्ट', 'उंची', 'ऊंची', 'वर्ण', 'रंग', 'रक्तगट', 'रक्त गट',
        'शिक्षण', 'नोकरी', 'व्यवसाय', 'वडिलांचे नाव', 'वडिलांचे नांव', 'वडीलांचे नाव', 'वडीलांचे नांव',
        'पित्याचे नाव', 'आईचे नाव', 'आईचे नांव', 'मातेचे नाव', 'भाऊ', 'बहीण', 'बहिण',
        'चुलते', 'आत्या', 'मामा', 'आजोळ', 'इतर पाहुणे', 'इतर पाहूणे', 'इतर नातेवाईक', 'देवक', 'कुलदैवत',
    ];

    public static function rejoin(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $lines = preg_split('/\R/u', $text) ?: [];
        if (count($lines) < self::MIN_LABEL_LINES + 2) {
            return $text;
        }

        $splitAt = self::detectSplitIndex($lines);
        if ($splitAt === null) {
            return $text;
        }

        $labels = [];
        $prefixOut = [];
        for ($i = 0; $i < $splitAt; $i++) {
            $t = trim((string) $lines[$i]);
            if ($t === '' || self::isDecorativeHeading($t)) {
                if ($t !== '') {
                    $prefixOut[] = $t;
                }

                continue;
            }
            if (self::looksLikeLabelOnly($t)) {
                $labels[] = self::normalizeLabelLine($t);

                continue;
            }
            $prefixOut[] = $t;
        }

        if (count($labels) < self::MIN_LABEL_LINES) {
            return $text;
        }

        $valueLines = array_slice($lines, $splitAt);
        $segments = self::groupValueSegments($valueLines);
        if (count($segments) < self::MIN_LABEL_LINES) {
            return $text;
        }

        $paired = [];
        $labelCount = count($labels);
        for ($i = 0; $i < $labelCount; $i++) {
            $value = $segments[$i] ?? '';
            if ($value === '') {
                continue;
            }
            $paired[] = $labels[$i].' :- '.self::cleanValue($value);
        }

        if (count($segments) > $labelCount && $labelCount > 0) {
            $overflow = array_slice($segments, $labelCount);
            $lastIdx = array_key_last($paired);
            if ($lastIdx !== null) {
                $base = self::cleanValue($segments[$labelCount - 1]);
                $extra = implode("\n", array_map(self::cleanValue(...), $overflow));
                $paired[$lastIdx] = $labels[$labelCount - 1].' :- '.$base."\n".$extra;
            }
        }

        $tail = self::tailFromValueLines($valueLines);

        return trim(implode("\n", array_merge($prefixOut, $paired, $tail)));
    }

    /**
     * @param  list<string>  $lines
     */
    private static function detectSplitIndex(array $lines): ?int
    {
        $n = count($lines);
        $best = null;
        $bestScore = 0;

        for ($i = self::MIN_LABEL_LINES; $i < $n - 2; $i++) {
            $labelRun = 0;
            for ($j = $i - 1; $j >= 0; $j--) {
                $t = trim((string) $lines[$j]);
                if ($t === '' || self::isDecorativeHeading($t)) {
                    continue;
                }
                if (! self::looksLikeLabelOnly($t)) {
                    break;
                }
                $labelRun++;
            }
            if ($labelRun < self::MIN_LABEL_LINES) {
                continue;
            }

            $colonHits = 0;
            $checked = 0;
            for ($k = $i; $k < min($n, $i + 12); $k++) {
                $t = trim((string) $lines[$k]);
                if ($t === '') {
                    continue;
                }
                $checked++;
                if (preg_match('/^:\s*\S/u', $t) || self::looksLikeStandaloneValue($t)) {
                    $colonHits++;
                }
            }
            if ($checked === 0 || $colonHits < 3) {
                continue;
            }

            $score = $labelRun * 10 + $colonHits;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $i;
            }
        }

        return $best;
    }

    private static function normalizeLabelLine(string $line): string
    {
        $t = trim(preg_replace('/^#+\s*/u', '', $line) ?? $line);
        $t = preg_replace('/[\s:\-–—]+$/u', '', $t) ?? $t;
        $t = preg_replace('/\{([^}]+)\}/u', '$1', $t) ?? $t;

        return trim($t);
    }

    private static function looksLikeLabelOnly(string $line): bool
    {
        $t = self::normalizeLabelLine($line);
        if ($t === '' || mb_strlen($t) > 72) {
            return false;
        }
        if (preg_match('/^:\s*\S/u', $t)) {
            return false;
        }
        if (self::looksLikeStandaloneValue($t)) {
            return false;
        }
        if (preg_match('/^(.+?)\s*[:\-–—]\s*(.+)$/u', $t, $m) && mb_strlen(trim($m[2])) > 1) {
            return false;
        }

        foreach (self::LABELS as $label) {
            if ($t === $label) {
                return true;
            }
        }

        return (bool) preg_match(
            '/^(?:मुलाच(?:े|ी)|मुलीच(?:े|ी)|वधूच(?:े|ी)|जन्म|धर्म|जात|उंची|ऊंची|वर्ण|रक्त|शिक्षण|नोकरी|व्यवसाय|'
            .'वडिल|वडील|पित्य|आई|मात|भाऊ|बहिण|बहीण|चुल|आत्या|मामा|आजोळ|देवक|कुल|इतर|पाह)/u',
            $t
        ) && ! preg_match('/(?:चि\.|कु\.|श्री\.|डॉ\.|B\.Com|O\+ve|[०-९0-9]{1,2}[\/\.][०-९0-9])/ui', $t);
    }

    private static function looksLikeStandaloneValue(string $line): bool
    {
        $t = trim($line);
        if (preg_match('/^:\s*\S/u', $t)) {
            return true;
        }
        if (preg_match('/[०-९0-9]{1,2}[\/\.\-][०-९0-9]{1,2}[\/\.\-][०-९0-9]{2,4}/u', $t)) {
            return true;
        }
        if (preg_match('/^(?:O|A|B|AB)\s*[+\-]/ui', $t)) {
            return true;
        }
        if (preg_match('/^(?:B\.Com|B\.A|BE|MBA|M\.Sc)/ui', $t)) {
            return true;
        }
        if (preg_match('/^(?:चि\.|कु\.|श्री\.|डॉ\.|कै\.|सौ\.)/u', $t)) {
            return true;
        }

        return (bool) preg_match('/^(?:नाही|NA|Nil)\b/ui', $t);
    }

    /**
     * @param  list<string>  $valueLines
     * @return list<string>
     */
    private static function groupValueSegments(array $valueLines): array
    {
        $segments = [];
        $current = null;

        foreach ($valueLines as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            if (self::isHoroscopeOrFooterBoundary($t)) {
                break;
            }
            if (preg_match('/^:\s*(.*)$/u', $t, $m)) {
                if ($current !== null && $current !== '') {
                    $segments[] = $current;
                }
                $current = trim($m[1]);

                continue;
            }
            if ($current === null) {
                if (self::looksLikeStandaloneValue($t)) {
                    $current = $t;
                }

                continue;
            }
            $current = trim($current."\n".$t);
        }

        if ($current !== null && $current !== '') {
            $segments[] = $current;
        }

        return $segments;
    }

    /**
     * @param  list<string>  $valueLines
     * @return list<string>
     */
    private static function tailFromValueLines(array $valueLines): array
    {
        $tail = [];
        $started = false;
        foreach ($valueLines as $line) {
            $t = trim($line);
            if ($t === '') {
                if ($started) {
                    $tail[] = '';
                }

                continue;
            }
            if (! $started && self::isHoroscopeOrFooterBoundary($t)) {
                $started = true;
            }
            if ($started) {
                $tail[] = $line;
            }
        }

        return $tail;
    }

    private static function cleanValue(string $value): string
    {
        $v = trim(preg_replace('/^:\s*/u', '', $value) ?? $value);

        return preg_replace('/\h+/u', ' ', $v) ?? $v;
    }

    private static function isDecorativeHeading(string $line): bool
    {
        $t = trim(preg_replace('/^#+\s*/u', '', $line) ?? $line);

        return (bool) preg_match('/^(?:\|\|.*\|\||श्री\s*(?:गणेश|गजानन|सिध्दनाथ)|परिचय\s*(?:पत्र|पञ्जक)|बायोडाटा)$/u', $t);
    }

    private static function isHoroscopeOrFooterBoundary(string $line): bool
    {
        return (bool) preg_match('/^(?:\|\|?\s*जन्म\s*लग्न|\[\^|श्री\s*गुरुकृपा|-\s*राशी\s*:)/u', trim($line));
    }
}
