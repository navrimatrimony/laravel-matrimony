<?php

namespace App\Services\Admin;

/**
 * Normalized reads for {@see \App\Models\ProfilePhoto::$moderation_scan_json}.
 * Prefer api_status + pipeline_confidence; fall back to legacy status + confidence.
 */
final class PhotoModerationStoredScan
{
    /**
     * @return array<string, mixed>|null
     */
    public static function asArray(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim === '' || $trim === 'null') {
                return null;
            }
            $decoded = json_decode($trim, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    public static function apiStatus(?array $scan): ?string
    {
        if ($scan === null) {
            return null;
        }
        $s = $scan['api_status'] ?? $scan['status'] ?? null;
        if ($s === null || $s === '') {
            return null;
        }

        return strtolower(trim((string) $s));
    }

    public static function pipelineConfidence(?array $scan): ?float
    {
        if ($scan === null) {
            return null;
        }
        foreach (['pipeline_confidence', 'confidence'] as $key) {
            if (! array_key_exists($key, $scan)) {
                continue;
            }
            $v = $scan[$key];
            if ($v === null || $v === '') {
                continue;
            }
            if (! is_numeric($v)) {
                continue;
            }

            return (float) $v;
        }

        return null;
    }
}
