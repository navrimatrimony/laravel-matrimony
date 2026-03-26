<?php

namespace App\Services\ShowcaseChat;

class ShowcaseProbabilityDecisionService
{
    /**
     * Deterministic decision helper (no storage, stable across retries).
     */
    public function passesPercent(int $percent, string $key): bool
    {
        $percent = max(0, min(100, $percent));
        if ($percent === 0) {
            return false;
        }
        if ($percent === 100) {
            return true;
        }

        $n = (int) (abs(crc32($key)) % 100) + 1; // 1..100
        return $n <= $percent;
    }
}

