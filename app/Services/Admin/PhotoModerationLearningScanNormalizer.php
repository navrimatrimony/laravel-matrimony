<?php

namespace App\Services\Admin;

/**
 * Produces a compact, training-friendly snapshot from stored profile photo scan JSON.
 * Only keys: status, confidence, detections (class + score). Nulls omitted.
 */
class PhotoModerationLearningScanNormalizer
{
    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{status?: string, confidence?: float, detections: list<array{class: string, score: float}>}|null  Null if $raw is null/empty.
     */
    public static function normalize(?array $raw): ?array
    {
        if ($raw === null || $raw === []) {
            return null;
        }

        $status = $raw['status'] ?? $raw['api_status'] ?? null;
        if ($status !== null && $status !== '') {
            $status = (string) $status;
        } else {
            $status = null;
        }

        $confidence = $raw['confidence'] ?? $raw['pipeline_confidence'] ?? null;
        if ($confidence !== null && $confidence !== '') {
            $confidence = round((float) $confidence, 6);
        } else {
            $confidence = null;
        }

        $detections = [];
        $rawDets = $raw['detections'] ?? [];
        if (is_array($rawDets)) {
            foreach ($rawDets as $d) {
                if (! is_array($d)) {
                    continue;
                }
                $class = (string) ($d['class'] ?? $d['label'] ?? '');
                if ($class === '') {
                    continue;
                }
                if (! array_key_exists('score', $d) || $d['score'] === null) {
                    continue;
                }
                $detections[] = [
                    'class' => $class,
                    'score' => round((float) $d['score'], 6),
                ];
            }
        }

        $out = ['detections' => $detections];
        if ($status !== null && $status !== '') {
            $out['status'] = $status;
        }
        if ($confidence !== null) {
            $out['confidence'] = $confidence;
        }

        return $out;
    }
}
