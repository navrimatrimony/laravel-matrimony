<?php

namespace App\Services;

/**
 * Phase 4.2: human-readable reasons from {@see RuleEngineService} match payloads only (no scoring).
 */
class ExplanationService
{
    public function explain(array $matchResult): string
    {
        $reasons = [];

        $breakdown = $matchResult['breakdown'] ?? [];

        if (($breakdown['matching_education'] ?? 0) > 0) {
            $reasons[] = 'शिक्षण जुळते';
        }

        if (($breakdown['matching_location'] ?? 0) > 0) {
            $reasons[] = 'स्थान जवळ आहे';
        }

        if (($breakdown['matching_caste'] ?? 0) > 0) {
            $reasons[] = 'समाज जुळतो';
        }

        if (($breakdown['matching_age'] ?? 0) > 0) {
            $reasons[] = 'वय योग्य आहे';
        }

        if (($matchResult['ai_boost'] ?? 0) > 0) {
            $reasons[] = 'तुमच्या आवडीप्रमाणे प्रोफाइल';
        }

        if ($reasons === []) {
            return 'हा प्रोफाइल तुमच्यासाठी योग्य असू शकतो';
        }

        return implode(' • ', $reasons);
    }
}
