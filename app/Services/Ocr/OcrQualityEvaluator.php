<?php

namespace App\Services\Ocr;

/**
 * Lightweight heuristics for OCR weakness (parse-input text). Not persisted to DB.
 */
class OcrQualityEvaluator
{
    private const KEYWORDS = ['जन्म', 'नाव', 'शिक्षण', 'नोकरी'];

    /**
     * @return array{score: float, is_low: bool, reasons: array<int, string>}
     */
    public function evaluate(string $text): array
    {
        $text = trim($text);
        $reasons = [];
        $penalty = 0.0;

        $len = mb_strlen($text, 'UTF-8');
        if ($len < 200) {
            $reasons[] = 'text_short';
            $penalty += 0.35;
        }

        if ($len > 0) {
            if (preg_match_all('/[\p{L}\p{M}]/u', $text, $m)) {
                $letters = count($m[0]);
            } else {
                $letters = 0;
            }
            if (preg_match_all('/[^\p{L}\p{M}\s\n\r]/u', $text, $m2)) {
                $symbols = count($m2[0]);
            } else {
                $symbols = 0;
            }
            $symRatio = $symbols / max(1, $letters + $symbols);
            if ($symRatio > 0.35) {
                $reasons[] = 'high_symbol_ratio';
                $penalty += 0.25;
            }
        }

        $missingKw = [];
        foreach (self::KEYWORDS as $kw) {
            if ($kw !== '' && ! str_contains($text, $kw)) {
                $missingKw[] = $kw;
            }
        }
        if (count($missingKw) >= 3) {
            $reasons[] = 'missing_expected_keywords';
            $penalty += 0.2;
        } elseif (count($missingKw) === 2) {
            $reasons[] = 'missing_some_keywords';
            $penalty += 0.1;
        }

        $unknownHeavy = $this->tokenUnknownHeuristic($text);
        if ($unknownHeavy) {
            $reasons[] = 'many_short_tokens';
            $penalty += 0.1;
        }

        $score = max(0.0, min(1.0, 1.0 - $penalty));
        $isLow = $score < 0.45 || in_array('text_short', $reasons, true);

        return [
            'score' => round($score, 3),
            'is_low' => $isLow,
            'reasons' => $reasons,
        ];
    }

    private function tokenUnknownHeuristic(string $text): bool
    {
        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || count($parts) < 12) {
            return false;
        }
        $short = 0;
        foreach ($parts as $p) {
            $l = mb_strlen($p, 'UTF-8');
            if ($l >= 1 && $l <= 2) {
                $short++;
            }
        }

        return ($short / count($parts)) > 0.45;
    }
}
