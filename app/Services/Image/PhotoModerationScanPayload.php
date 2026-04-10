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

        $apiStatus = $raw['api_status'] ?? $raw['status'] ?? null;
        if (! is_string($apiStatus) || $apiStatus === '') {
            $apiStatus = (($nn['safe'] ?? false) === true) ? 'safe' : 'flagged';
        }

        $pipelineConf = null;
        if (array_key_exists('pipeline_confidence', $raw) && $raw['pipeline_confidence'] !== null && $raw['pipeline_confidence'] !== '') {
            $pipelineConf = round((float) $raw['pipeline_confidence'], 4);
        } elseif (array_key_exists('confidence', $raw) && $raw['confidence'] !== null && $raw['confidence'] !== '') {
            $pipelineConf = round((float) $raw['confidence'], 4);
        } elseif (isset($nn['confidence'])) {
            $pipelineConf = round((float) $nn['confidence'], 4);
        }

        return [
            'scanner' => 'nudenet',
            'captured_at' => now()->toIso8601String(),
            'pipeline_safe' => (bool) ($nn['safe'] ?? false),
            'pipeline_confidence' => $pipelineConf,
            'api_status' => strtolower(trim($apiStatus)),
            'detections' => $detections,
        ];
    }
}
