<?php

namespace App\Services\Intake\OcrEnsemble\Support;

use App\Services\Ocr\OcrNormalize;

/**
 * Production Phase 3 primary mobile selection when OCR contains multiple phone numbers.
 */
class OcrEnsembleMobileSelector
{
    /**
     * @param  list<string>  $lines
     */
    public function selectPrimary(array $lines, ?string $fallback = null): ?string
    {
        $scores = [];

        foreach ($lines as $index => $line) {
            if ($this->isFooterNoise($line)) {
                continue;
            }

            foreach ($this->extractPhones($line) as $phone) {
                $snippet = $this->localSnippetAroundPhone($line, $phone);
                $lineScore = $this->snippetContextScore($snippet);
                // Page-level boosts only when the line is not a huge OCR dump.
                if (mb_strlen($line, 'UTF-8') < 220) {
                    $lineScore += $this->pageContextBoost($lines, $index, $line);
                }
                if ($lineScore < -50) {
                    continue;
                }
                $scores[$phone] = max($scores[$phone] ?? PHP_INT_MIN, $lineScore);
            }

            $nextLine = $lines[$index + 1] ?? null;
            if (! is_string($nextLine) || $this->hasRelationContext($nextLine) || $this->hasNonContactPhoneContext($nextLine)) {
                continue;
            }

            if ($this->lineHasMobileLabel($line) && ! $this->hasRelationContext($line)) {
                $labelScore = $this->snippetContextScore($line) + 5;
                if (mb_strlen($line, 'UTF-8') < 220) {
                    $labelScore += $this->pageContextBoost($lines, $index, $line);
                }
                foreach ($this->extractPhones($nextLine) as $phone) {
                    if ($labelScore < -50) {
                        continue;
                    }
                    $scores[$phone] = max($scores[$phone] ?? PHP_INT_MIN, $labelScore);
                }
            }
        }

        if ($scores === []) {
            return $this->validPhone($fallback) ? $fallback : null;
        }

        arsort($scores);

        return array_key_first($scores);
    }

    /**
     * Score a short window around a phone so megapage OCR lines
     * (birth + family + mobile glued) do not zero out candidate contact.
     */
    private function snippetContextScore(string $snippet): int
    {
        $score = 0;

        if ($this->lineHasDirectContactLabel($snippet) && ! $this->hasRelationContext($snippet)) {
            $score += 50;
        } elseif ($this->lineHasMobileLabel($snippet) && ! $this->hasRelationContext($snippet)) {
            $score += 35;
        }

        if ($this->hasRelationContext($snippet)) {
            $score -= 100;
        }

        if ($this->hasNonContactPhoneContext($snippet) && ! $this->lineHasMobileLabel($snippet)) {
            $score -= 40;
        }

        if (preg_match('/(?:वडील|आई|मामा|भाऊ|बहिण|बहीण|काका|मावशी)\s+.*(?:मोबाईल|मोबाइल|संपर्क)/ui', $snippet) === 1) {
            $score -= 90;
        }

        // Unlabeled orphan digit strings (suchak overlay stickers) are weaker than मो.नं. lines.
        $compact = preg_replace('/\s+/u', '', OcrNormalize::normalizeDigits($snippet)) ?? '';
        if ($this->lineHasMobileLabel($snippet) === false
            && preg_match('/^[0-9+\-\/,:]{10,}$/u', $compact) === 1) {
            $score -= 25;
        }

        return $score;
    }

    /**
     * @param  list<string>  $lines
     */
    private function pageContextBoost(array $lines, int $index, string $line): int
    {
        $score = 0;

        if ($this->hasCandidateNameLabelNearby($lines, $index)) {
            $score += 25;
        }

        if ($this->isBeforeFamilySection($lines, $index)) {
            $score += 15;
        }

        if ($this->hasNearbyParentContext($lines, $index) && ! $this->lineHasDirectContactLabel($line)) {
            $score -= 80;
        }

        return $score;
    }

    private function localSnippetAroundPhone(string $line, string $phone): string
    {
        $normalizedLine = OcrNormalize::normalizeDigits($line);
        $pos = mb_strpos($normalizedLine, $phone);
        if ($pos === false) {
            return mb_strlen($normalizedLine, 'UTF-8') <= 160
                ? $normalizedLine
                : mb_substr($normalizedLine, 0, 160, 'UTF-8');
        }

        // Left-biased window: owning label is usually before the digits.
        // Looking far right on megapage OCR pulls in the next relative's मोबाईल
        // and falsely zeros a valid मो.नं. candidate.
        $start = max(0, $pos - 56);
        $after = 14;
        $tail = mb_substr(
            $normalizedLine,
            $pos + mb_strlen($phone, 'UTF-8'),
            40,
            'UTF-8'
        );
        // Allow a comma-twin secondary number immediately after; stop before relation labels.
        if (preg_match('/^([,\/\s]*[6-9]\d{9})?/u', $tail, $m) === 1 && ($m[0] ?? '') !== '') {
            $after = mb_strlen($m[0], 'UTF-8');
        }

        return mb_substr(
            $normalizedLine,
            $start,
            mb_strlen($phone, 'UTF-8') + ($pos - $start) + $after,
            'UTF-8'
        );
    }

    /**
     * @param  list<string>  $lines
     */
    private function hasCandidateNameLabelNearby(array $lines, int $index): bool
    {
        for ($i = max(0, $index - 4); $i <= min(count($lines) - 1, $index + 1); $i++) {
            if ($this->hasCandidateNameLabel($lines[$i] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $lines
     */
    private function isBeforeFamilySection(array $lines, int $index): bool
    {
        for ($i = 0; $i < $index; $i++) {
            if ($this->isFamilySectionBoundary($lines[$i] ?? '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $lines
     */
    private function hasNearbyParentContext(array $lines, int $index): bool
    {
        for ($i = max(0, $index - 2); $i <= $index; $i++) {
            if ($this->hasRelationContext($lines[$i] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractPhones(string $line): array
    {
        $line = OcrNormalize::normalizeDigits($line);
        $phones = [];

        if (preg_match_all('/(?:\+?91[\s\-]*)?[6-9][0-9\s\-\/]{9,14}/u', $line, $matches)) {
            foreach ($matches[0] as $raw) {
                $phone = OcrNormalize::normalizePhone($raw);
                if ($this->validPhone($phone)) {
                    $phones[$phone] = $phone;
                }
            }
        }

        $phone = OcrNormalize::normalizePhone($line);
        if ($this->validPhone($phone)) {
            $phones[$phone] = $phone;
        }

        return array_values($phones);
    }

    private function validPhone(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[6-9]\d{9}$/', $value) === 1;
    }

    private function lineHasMobileLabel(string $line): bool
    {
        return preg_match('/मोबाईल|मोबाइल|मोबा\.?|मो\.?\s*नं\.?|मो\.|भ्रमणध्वनी|संपर्क|mobile|phone|contact/ui', $line) === 1;
    }

    private function lineHasDirectContactLabel(string $line): bool
    {
        return preg_match('/(?:मोबाईल|मोबाइल|मो\.?\s*नं\.?|संपर्क|mobile|phone|contact)/ui', $line) === 1;
    }

    private function hasRelationContext(string $value): bool
    {
        return preg_match('/(?:वडील|वडिलांचे|पित्याचे|आई|मातेचे|मामा|मावशी|माऊशी|आत्या|चुलते|काका|आजोळ|भाऊ|बहिण|बहीण|दाजी|जावई)(?:[\s:：\-–—.]|$)/u', $value) === 1
            || preg_match('/\b(?:father|mother|brother|sister|uncle|aunt)\b/ui', $value) === 1;
    }

    private function hasNonContactPhoneContext(string $line): bool
    {
        return preg_match('/(?:जन्म\s*तारीख|जन्मतारीख|जन्म\s*दिनांक|जन्म\s*वेळ|जन्मवेळ|जमीन|शेती|एकर|उत्पन्न|वेतन|पत्ता|पिन\s*कोड|pincode|pin\s*code|कुंडली|पत्रिका|नक्षत्र|रास|राशी|गण|नाडी|देवक|कुलदैवत)/ui', $line) === 1;
    }

    private function hasCandidateNameLabel(string $line): bool
    {
        if (preg_match('/(?:मुलाचे\s+नां?व|मुलीचे\s+नां?व|वधूचे\s+नां?व|वराचे\s+नां?व)/u', $line) === 1) {
            return true;
        }

        return preg_match('/(?:^|\s)नां?व(?:[\s:：\-–—.]|$)/u', $line) === 1
            && ! $this->hasRelationContext($line);
    }

    private function isFamilySectionBoundary(string $line): bool
    {
        return preg_match('/^\s*(?:कौटुंबिक\s+माहिती|कौटुंबिक\s+तपशील|वडील|वडिलांचे|पित्याचे|आई|आईचे|मातेचे|भाऊ|बहिण|बहीण|मुलाचे\s+भाऊ|मुलाची\s+बहीण|मुलाची\s+बहिण|मामा|मावशी|माऊशी|आत्या|चुलते|काका|आजोळ|नातेवाईक|इतर\s+नातेवाईक|पाहुणे)(?:[\s:：\-–—.]|$)/u', $line) === 1;
    }

    private function isFooterNoise(string $line): bool
    {
        return preg_match('/print|printing|shop|प्रिंट|छपाई/ui', $line) === 1;
    }
}
