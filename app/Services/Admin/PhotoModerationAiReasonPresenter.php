<?php

namespace App\Services\Admin;

/**
 * Human-readable "why" lines from stored NudeNet scan JSON (detections + api_status).
 */
final class PhotoModerationAiReasonPresenter
{
    /** @var list<string> */
    private const EXPLICIT_CLASSES = [
        'FEMALE_BREAST_EXPOSED',
        'FEMALE_GENITALIA_EXPOSED',
        'MALE_GENITALIA_EXPOSED',
        'ANUS_EXPOSED',
    ];

    /**
     * @return array{
     *   summary: string,
     *   top_two: list<array{class: string, score: float}>,
     *   risk_badge: string
     * }
     */
    public static function explain(?array $scan): array
    {
        $api = PhotoModerationStoredScan::apiStatus($scan);
        $conf = PhotoModerationStoredScan::pipelineConfidence($scan);
        $rows = self::detectionRows($scan);
        $topNonFace = self::topNonFaceScores($rows, 2);

        $riskBadge = self::riskBadge($api, $conf);

        if ($api === null && $rows === []) {
            return [
                'summary' => 'No scan data stored for this photo.',
                'top_two' => [],
                'risk_badge' => '—',
            ];
        }

        if ($api === 'unsafe') {
            $driver = self::pickDriverForUnsafe($rows, $topNonFace);

            return [
                'summary' => $driver !== null
                    ? 'Explicit content detected: '.$driver['class'].' ('.self::fmtScore($driver['score']).')'
                    : 'Explicit content detected (unsafe classification).',
                'top_two' => $topNonFace,
                'risk_badge' => $riskBadge,
            ];
        }

        if ($api === 'review') {
            $driver = $topNonFace[0] ?? null;

            return [
                'summary' => $driver !== null
                    ? 'High exposure detected: '.$driver['class'].' ('.self::fmtScore($driver['score']).')'
                    : 'Manual review recommended based on model output.',
                'top_two' => $topNonFace,
                'risk_badge' => $riskBadge,
            ];
        }

        if ($api === 'safe') {
            return [
                'summary' => 'No risky body parts detected. Only face or low-confidence detections.',
                'top_two' => $topNonFace,
                'risk_badge' => $riskBadge,
            ];
        }

        return [
            'summary' => 'Scan status unclear; see detections below.',
            'top_two' => $topNonFace,
            'risk_badge' => $riskBadge,
        ];
    }

    /**
     * @return list<array{class: string, score: float}>
     */
    private static function detectionRows(?array $scan): array
    {
        if ($scan === null) {
            return [];
        }
        $dets = $scan['detections'] ?? [];
        if (! is_array($dets)) {
            return [];
        }
        $out = [];
        foreach ($dets as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cls = strtoupper(trim((string) ($row['class'] ?? $row['label'] ?? '')));
            if ($cls === '') {
                continue;
            }
            if (! isset($row['score']) || ! is_numeric($row['score'])) {
                continue;
            }
            $out[] = ['class' => $cls, 'score' => (float) $row['score']];
        }

        return $out;
    }

    /**
     * Highest-scoring rows excluding FACE_* (up to $limit).
     *
     * @param  list<array{class: string, score: float}>  $rows
     * @return list<array{class: string, score: float}>
     */
    private static function topNonFaceScores(array $rows, int $limit): array
    {
        $filtered = array_values(array_filter(
            $rows,
            static fn (array $r): bool => ! str_starts_with($r['class'], 'FACE_')
        ));
        usort($filtered, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($filtered, 0, max(0, $limit));
    }

    /**
     * @param  list<array{class: string, score: float}>  $rows
     * @param  list<array{class: string, score: float}>  $topNonFace
     * @return array{class: string, score: float}|null
     */
    private static function pickDriverForUnsafe(array $rows, array $topNonFace): ?array
    {
        $explicit = [];
        foreach ($rows as $r) {
            if (in_array($r['class'], self::EXPLICIT_CLASSES, true)) {
                $explicit[] = $r;
            }
        }
        if ($explicit !== []) {
            usort($explicit, static fn ($a, $b) => $b['score'] <=> $a['score']);

            return $explicit[0];
        }

        return $topNonFace[0] ?? null;
    }

    private static function fmtScore(float $s): string
    {
        return rtrim(rtrim(number_format($s, 4, '.', ''), '0'), '.');
    }

    private static function riskBadge(?string $api, ?float $conf): string
    {
        if ($api === 'unsafe') {
            return 'HIGH RISK';
        }
        if ($api === 'review') {
            return 'MEDIUM RISK';
        }
        if ($api === 'safe' && $conf !== null) {
            if ($conf >= 0.75) {
                return 'LOW RISK';
            }
            if ($conf >= 0.5) {
                return 'MEDIUM RISK';
            }

            return 'MEDIUM RISK';
        }

        if ($api === 'safe') {
            return 'LOW RISK';
        }

        return '—';
    }
}
