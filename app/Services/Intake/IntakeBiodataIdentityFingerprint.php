<?php

namespace App\Services\Intake;

use App\Services\AiVisionExtractionService;
use App\Services\Ocr\OcrNormalize;

/**
 * Conservative identity signals for paid-AI extraction reuse (cache keying).
 * Reuses OcrNormalize + light Marathi label patterns — no fuzzy name matching beyond normalization.
 */
class IntakeBiodataIdentityFingerprint
{
    /**
     * @return array{phone: string, dob: string, name_key: string, height_key: string, strong_count: int}
     */
    public function extractSignals(string $text): array
    {
        $norm = OcrNormalize::normalizeRawTextForParsing($text);
        $norm = AiVisionExtractionService::sanitizeTransientParseInputText($norm);

        $phone = $this->extractPrimaryIndianMobile($norm);
        $dob = $this->extractDobNormalized($norm);
        $nameKey = $this->extractCandidateNameKey($norm);
        $heightKey = $this->extractHeightKey($norm);

        $strong = 0;
        if ($phone !== '') {
            $strong++;
        }
        if ($dob !== '') {
            $strong++;
        }
        if (mb_strlen($nameKey, 'UTF-8') >= 4) {
            $strong++;
        }
        if ($heightKey !== '') {
            $strong++;
        }

        return [
            'phone' => $phone,
            'dob' => $dob,
            'name_key' => $nameKey,
            'height_key' => $heightKey,
            'strong_count' => $strong,
        ];
    }

    /**
     * Stable fingerprint for cache lookup, or null if reuse would be unsafe.
     */
    public function fingerprintForProvider(string $provider, string $text): ?string
    {
        $p = strtolower(trim($provider));
        if ($p === '') {
            return null;
        }
        $s = $this->extractSignals($text);
        if ($s['phone'] === '') {
            return null;
        }
        if ($s['dob'] === '' && mb_strlen($s['name_key'], 'UTF-8') < 4) {
            return null;
        }

        $payload = $p.'|'.$s['phone'].'|'.$s['dob'].'|'.$s['height_key'].'|'.$s['name_key'];

        return hash('sha256', $payload);
    }

    /**
     * Weighted evidence that two intakes refer to the same candidate (conservative).
     * Returns null when reuse would be unsafe (phone mismatch, conflicting dob/name, or insufficient evidence).
     *
     * Policy: normalized phone must match; then require (dob match when both present) and (name match when both present);
     * plus require at least one substantive agreement: both DOBs equal, or both names equal (≥4 chars).
     */
    public function identityReuseEvidenceScore(array $current, array $peer): ?float
    {
        $p1 = $current['phone'] ?? '';
        $p2 = $peer['phone'] ?? '';
        if ($p1 === '' || $p2 === '' || $p1 !== $p2) {
            return null;
        }

        $d1 = $current['dob'] ?? '';
        $d2 = $peer['dob'] ?? '';
        if ($d1 !== '' && $d2 !== '' && $d1 !== $d2) {
            return null;
        }

        $n1 = $current['name_key'] ?? '';
        $n2 = $peer['name_key'] ?? '';
        $n1Ok = mb_strlen((string) $n1, 'UTF-8') >= 4;
        $n2Ok = mb_strlen((string) $n2, 'UTF-8') >= 4;
        if ($n1Ok && $n2Ok && $n1 !== $n2) {
            return null;
        }

        $substantive = ($d1 !== '' && $d2 !== '' && $d1 === $d2)
            || ($n1Ok && $n2Ok && $n1 === $n2);
        if (! $substantive) {
            return null;
        }

        $score = 10.0;
        if ($d1 !== '' && $d2 !== '' && $d1 === $d2) {
            $score += 6.0;
        }
        if ($n1Ok && $n2Ok && $n1 === $n2) {
            $score += 4.0;
        }
        $h1 = $current['height_key'] ?? '';
        $h2 = $peer['height_key'] ?? '';
        if ($h1 !== '' && $h2 !== '' && $h1 === $h2) {
            $score += 2.0;
        }

        return round($score, 3);
    }

    private function extractPrimaryIndianMobile(string $norm): string
    {
        $digits = OcrNormalize::normalizeDigits($norm);
        if (preg_match_all('/\b([6-9]\d{9})\b/', $digits, $m)) {
            $candidates = array_values(array_unique($m[1]));
            if ($candidates !== []) {
                $c = $candidates[0];
                $n = OcrNormalize::normalizePhone($c);

                return $n !== null && $n !== '' ? $n : $c;
            }
        }

        return '';
    }

    private function extractDobNormalized(string $norm): string
    {
        $labels = ['जन्म तारीख', 'जन्मतारीख', 'जन्म दिनांक', 'जन्मतारिख', 'DOB', 'Date of Birth', 'Birth Date'];
        foreach ($labels as $label) {
            if (preg_match('/'.preg_quote($label, '/').'\s*[:\-–—.]+\s*([^\n]+)/ui', $norm, $m)) {
                $raw = trim($m[1]);
                $raw = preg_replace('/\s+/u', ' ', $raw);
                $n = OcrNormalize::normalizeDate($raw);
                if ($n !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $n)) {
                    return $n;
                }
            }
        }
        if (preg_match('/(\d{1,2}[\/\.\-]\d{1,2}[\/\.\-]\d{2,4})/u', $norm, $m)) {
            $n = OcrNormalize::normalizeDate($m[1]);

            return ($n !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $n)) ? $n : '';
        }

        return '';
    }

    private function extractCandidateNameKey(string $norm): string
    {
        $patterns = [
            '/(?:मुलीचे|मुलाचे|वधूचे)\s*(?:नाव|नांव)\s*[:\-–—.\s]+\s*(.+?)(?:\n|$)/u',
            '/(?:^|\n)\s*नाव\s*[:\-–—.]+\s*(.+?)(?:\n|$)/u',
            '/(?:^|\n)\s*नांव\s*[:\-–—.]+\s*(.+?)(?:\n|$)/u',
        ];
        foreach ($patterns as $pat) {
            if (preg_match($pat, $norm, $m)) {
                $n = trim($m[1]);
                $n = preg_replace('/^(कु\.?|कुं\.?|चि\.?|श्री\.?|सौ\.?|श्रीमती\.?)\s*/u', '', $n);
                $n = preg_replace('/\s+/u', ' ', $n);
                $n = trim($n, " \t.:;,");

                return mb_substr($n, 0, 120, 'UTF-8');
            }
        }

        return '';
    }

    private function extractHeightKey(string $norm): string
    {
        if (preg_match('/(?:उंची|ऊंची|Height)\s*[:\-–—.]+\s*([^\n]+)/ui', $norm, $m)) {
            $h = OcrNormalize::normalizeHeight(trim($m[1]));

            return $h !== null && $h !== '' ? (string) $h : '';
        }
        if (preg_match('/(\d{2,3})\s*(?:cm|सेमी|से\.मी)/ui', $norm, $m)) {
            return 'cm:'.$m[1];
        }

        return '';
    }
}
