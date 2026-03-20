<?php

namespace App\Services\Ocr;

/**
 * Conservative OCR cleanup for Marathi biodata text. Used only on the parse path;
 * never written to raw_ocr_text (SSOT).
 */
class OcrPostProcessor
{
    /**
     * Longer keys first so multi-character fixes apply before single-token swaps.
     *
     * @var array<string, string>
     */
    private const REPLACEMENTS = [
        'जन्मतारीस्य' => 'जन्म तारीख',
        'रात्री ०९ वा.45 मि' => 'रात्री 09 वा.45 मि.',
        'जन्प' => 'जन्म',
        'नांव' => 'नाव',
        'विठल' => 'विठ्ठल',
        'क.' => 'कु.',
    ];

    public function process(string $text): string
    {
        $text = str_replace(array_keys(self::REPLACEMENTS), array_values(self::REPLACEMENTS), $text);
        $text = $this->normalizeSpacing($text);
        $text = $this->dropNoiseLines($text);

        return trim($text);
    }

    private function normalizeSpacing(string $text): string
    {
        // Normalize line endings, collapse runs of spaces (incl. NBSP), trim each line.
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $out = [];
        foreach ($lines as $line) {
            $line = preg_replace('/[\x{00A0}\s]+/u', ' ', $line) ?? $line;
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }

    private function dropNoiseLines(string $text): string
    {
        $lines = explode("\n", $text);
        $kept = [];
        foreach ($lines as $line) {
            if ($this->isObviousNoiseLine($line)) {
                continue;
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    private function isObviousNoiseLine(string $line): bool
    {
        if ($line === '') {
            return true;
        }
        $len = mb_strlen($line, 'UTF-8');
        if ($len < 2) {
            return true;
        }

        // Mostly symbols / punctuation / digits with almost no letters.
        if (preg_match_all('/[\p{L}\p{M}]/u', $line, $m)) {
            $letterCount = count($m[0]);
        } else {
            $letterCount = 0;
        }

        if ($letterCount === 0) {
            return true;
        }

        $ratio = $letterCount / max(1, $len);

        return $ratio < 0.12 && $len >= 8;
    }
}
