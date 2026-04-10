<?php

namespace App\Services\Admin;

/**
 * Heuristic default reject reason for admin learning UX (admin may edit before submit).
 */
class PhotoModerationRejectReasonSuggest
{
    public static function fromScan(?array $scan): string
    {
        if (! is_array($scan)) {
            return 'Photo did not pass automated moderation review.';
        }

        $rows = $scan['detections'] ?? [];
        if (! is_array($rows) || $rows === []) {
            return 'Photo did not pass automated moderation review.';
        }

        $hasBreast = false;
        $bellyHigh = false;
        $riskyCount = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cls = strtoupper((string) ($row['class'] ?? $row['label'] ?? ''));
            $sc = isset($row['score']) ? (float) $row['score'] : 0.0;
            if (str_contains($cls, 'BREAST')) {
                $hasBreast = true;
            }
            if (str_contains($cls, 'BELLY') && $sc >= 0.5) {
                $bellyHigh = true;
            }
            if ($sc >= 0.4) {
                $riskyCount++;
            }
        }

        if ($hasBreast) {
            return 'Inappropriate exposure detected';
        }
        if ($bellyHigh) {
            return 'Revealing body exposure';
        }
        if ($riskyCount >= 2) {
            return 'Multiple risky detections';
        }

        return 'Photo did not pass automated moderation review.';
    }
}
