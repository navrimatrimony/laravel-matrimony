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

            $lineScore = $this->lineContextScore($lines, $index, $line);
            if ($lineScore < -50) {
                continue;
            }

            foreach ($this->extractPhones($line) as $phone) {
                $scores[$phone] = max($scores[$phone] ?? PHP_INT_MIN, $lineScore);
            }

            $nextLine = $lines[$index + 1] ?? null;
            if (! is_string($nextLine) || $this->hasRelationContext($nextLine) || $this->hasNonContactPhoneContext($nextLine)) {
                continue;
            }

            if ($this->lineHasMobileLabel($line) && ! $this->hasRelationContext($line)) {
                foreach ($this->extractPhones($nextLine) as $phone) {
                    $scores[$phone] = max($scores[$phone] ?? PHP_INT_MIN, $lineScore + 5);
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
     * @param  list<string>  $lines
     */
    private function lineContextScore(array $lines, int $index, string $line): int
    {
        $score = 0;

        if ($this->lineHasDirectContactLabel($line) && ! $this->hasRelationContext($line)) {
            $score += 50;
        } elseif ($this->lineHasMobileLabel($line) && ! $this->hasRelationContext($line)) {
            $score += 35;
        }

        if ($this->hasCandidateNameLabelNearby($lines, $index)) {
            $score += 25;
        }

        if ($this->isBeforeFamilySection($lines, $index)) {
            $score += 15;
        }

        if ($this->hasRelationContext($line)) {
            $score -= 100;
        }

        if ($this->hasNearbyParentContext($lines, $index) && ! $this->lineHasDirectContactLabel($line)) {
            $score -= 80;
        }

        if ($this->hasNonContactPhoneContext($line)) {
            $score -= 40;
        }

        if (preg_match('/(?:वडील|आई|मामा|भाऊ|बहिण|बहीण|काका|मावशी)\s+.*(?:मोबाईल|मोबाइल|संपर्क)/ui', $line) === 1) {
            $score -= 90;
        }

        return $score;
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
