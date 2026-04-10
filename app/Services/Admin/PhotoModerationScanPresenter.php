<?php

namespace App\Services\Admin;

/**
 * Read-only presentation helpers for stored {@see \App\Services\Image\PhotoModerationScanPayload} JSON.
 */
class PhotoModerationScanPresenter
{
    /**
     * @return list<array{class: string, max_score_pct: ?float, box_count: int, scores_sample: list<float>}>
     */
    public static function detectionSummary(?array $scan): array
    {
        if (! is_array($scan)) {
            return [];
        }
        $rows = $scan['detections'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $byClass = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $class = (string) ($row['class'] ?? $row['label'] ?? '');
            if ($class === '') {
                $class = '(unknown)';
            }
            $score = isset($row['score']) ? (float) $row['score'] : null;
            if (! isset($byClass[$class])) {
                $byClass[$class] = ['scores' => []];
            }
            if ($score !== null) {
                $byClass[$class]['scores'][] = $score;
            }
        }

        $out = [];
        foreach ($byClass as $class => $meta) {
            $scores = $meta['scores'];
            $max = $scores !== [] ? max($scores) : null;
            $out[] = [
                'class' => $class,
                'max_score_pct' => $max !== null ? round($max * 100, 2) : null,
                'box_count' => count($scores),
                'scores_sample' => array_slice(array_map(fn (float $s) => round($s * 100, 2), $scores), 0, 5),
            ];
        }

        usort($out, fn ($a, $b) => ($b['max_score_pct'] ?? 0) <=> ($a['max_score_pct'] ?? 0));

        return $out;
    }

    /**
     * @return array{confidence_pct: ?float, api_status: ?string, pipeline_safe: ?bool, detection_count: int}
     */
    public static function headline(?array $scan): array
    {
        if (! is_array($scan)) {
            return [
                'confidence_pct' => null,
                'api_status' => null,
                'pipeline_safe' => null,
                'detection_count' => 0,
            ];
        }

        $detections = $scan['detections'] ?? [];
        $count = is_array($detections) ? count($detections) : 0;

        $conf = PhotoModerationStoredScan::pipelineConfidence($scan);
        $confPct = $conf !== null ? round($conf * 100, 2) : null;

        $api = PhotoModerationStoredScan::apiStatus($scan);

        return [
            'confidence_pct' => $confPct,
            'api_status' => $api,
            'pipeline_safe' => isset($scan['pipeline_safe']) ? (bool) $scan['pipeline_safe'] : null,
            'detection_count' => $count,
        ];
    }

    /**
     * Multiline summary for admin tooltips (status, confidence, class: score lines).
     */
    public static function nudenetTooltipText(?array $scan): string
    {
        if (! is_array($scan)) {
            return "Status: —\nConfidence: —\n(no scan data)";
        }

        $lines = [];
        $api = PhotoModerationStoredScan::apiStatus($scan);
        $lines[] = 'Status: '.($api ?? '—');
        $conf = PhotoModerationStoredScan::pipelineConfidence($scan);
        $lines[] = 'Confidence: '.($conf !== null ? (string) round($conf, 4) : '—');

        $dets = $scan['detections'] ?? [];
        if (is_array($dets)) {
            foreach ($dets as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $class = (string) ($row['class'] ?? $row['label'] ?? '');
                if ($class === '') {
                    $class = '(unknown)';
                }
                $score = isset($row['score']) ? round((float) $row['score'], 4) : null;
                $lines[] = $class.': '.($score !== null ? (string) $score : '—');
            }
        }

        return implode("\n", $lines);
    }
}
