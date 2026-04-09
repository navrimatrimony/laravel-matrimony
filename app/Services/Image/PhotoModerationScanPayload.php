<?php

namespace App\Services\Image;

/**
 * Compact JSON stored on profile / gallery rows so admins can see what NudeNet returned.
 */
class PhotoModerationScanPayload
{
    /**
     * @param  array{meta?: array{nudenet?: array<string, mixed>}}  $moderationResult
     * @return array<string, mixed>|null
     */
    public static function fromModerationResult(array $moderationResult): ?array
    {
        $nn = $moderationResult['meta']['nudenet'] ?? null;
        if (! is_array($nn)) {
            return null;
        }

        $raw = $nn['raw'] ?? [];
        if (! is_array($raw)) {
            $raw = [];
        }

        $detections = [];
        if (isset($raw['detections']) && is_array($raw['detections'])) {
            foreach (array_slice($raw['detections'], 0, 12) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $detections[] = [
                    'class' => (string) ($row['class'] ?? $row['label'] ?? ''),
                    'score' => isset($row['score']) ? round((float) $row['score'], 4) : null,
                ];
            }
        }

        $apiStatus = $raw['status'] ?? null;
        if (! is_string($apiStatus) || $apiStatus === '') {
            $apiStatus = (($nn['safe'] ?? false) === true) ? 'safe' : 'flagged';
        }

        return [
            'scanner' => 'nudenet',
            'captured_at' => now()->toIso8601String(),
            'pipeline_safe' => (bool) ($nn['safe'] ?? false),
            'pipeline_confidence' => isset($nn['confidence']) ? round((float) $nn['confidence'], 4) : null,
            'api_status' => $apiStatus,
            'detections' => $detections,
        ];
    }
}
