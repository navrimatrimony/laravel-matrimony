<?php

namespace App\Services\Admin;

use App\Models\AdminSetting;
use App\Models\PhotoLearningDataset;

class ModerationLearningAnalyticsService
{
    public function __construct(
        private readonly float $defaultThreshold = 0.5,
    ) {}

    /**
     * Baseline score threshold for suggestion logic (AdminSetting; not auto-applied).
     */
    public function getNsfwThresholdBaseline(): float
    {
        $v = AdminSetting::getValue('moderation_nsfw_score_min', '');
        if ($v === null || $v === '') {
            return $this->defaultThreshold;
        }
        $f = (float) $v;

        return ($f > 0 && $f <= 1.0) ? $f : $this->defaultThreshold;
    }

    /**
     * Per detection class: totals and avg score (skips null JSON and rows with no detections).
     *
     * @return array<string, array{total: int, approved: int, rejected: int, review: int, avg_score: float}>
     */
    public function getClassStats(): array
    {
        $rows = PhotoLearningDataset::query()
            ->whereNotNull('moderation_scan_json')
            ->get(['moderation_scan_json', 'final_decision']);

        $byClass = [];

        foreach ($rows as $row) {
            $json = $row->moderation_scan_json;
            if (! is_array($json)) {
                continue;
            }
            $dets = $json['detections'] ?? [];
            if (! is_array($dets) || $dets === []) {
                continue;
            }

            $decision = strtolower((string) ($row->final_decision ?? ''));

            foreach ($dets as $d) {
                if (! is_array($d)) {
                    continue;
                }
                $class = (string) ($d['class'] ?? '');
                if ($class === '') {
                    continue;
                }
                if (! array_key_exists('score', $d) || $d['score'] === null) {
                    continue;
                }
                $score = (float) $d['score'];

                if (! isset($byClass[$class])) {
                    $byClass[$class] = [
                        'total' => 0,
                        'approved' => 0,
                        'rejected' => 0,
                        'review' => 0,
                        'score_sum' => 0.0,
                    ];
                }

                $byClass[$class]['total']++;
                $byClass[$class]['score_sum'] += $score;

                match ($decision) {
                    'approved' => $byClass[$class]['approved']++,
                    'rejected' => $byClass[$class]['rejected']++,
                    'review' => $byClass[$class]['review']++,
                    default => null,
                };
            }
        }

        $out = [];
        foreach ($byClass as $class => $agg) {
            $n = (int) $agg['total'];
            $out[$class] = [
                'total' => $n,
                'approved' => (int) $agg['approved'],
                'rejected' => (int) $agg['rejected'],
                'review' => (int) $agg['review'],
                'avg_score' => $n > 0 ? round($agg['score_sum'] / $n, 4) : 0.0,
            ];
        }
        ksort($out);

        return $out;
    }

    /**
     * Suggest tuning when admins often approve despite scores above the configured baseline.
     *
     * @param  array<string, array{total: int, approved: int, rejected: int, review: int, avg_score: float}>  $classStats
     * @return array<string, string>
     */
    public function getThresholdSuggestions(array $classStats): array
    {
        $threshold = $this->getNsfwThresholdBaseline();
        $suggestions = [];

        foreach ($classStats as $class => $stats) {
            $total = (int) ($stats['total'] ?? 0);
            $approved = (int) ($stats['approved'] ?? 0);
            if ($total < 1) {
                $suggestions[$class] = '—';

                continue;
            }

            $approvedPct = ($approved / $total) * 100.0;
            $avgScore = (float) ($stats['avg_score'] ?? 0.0);

            if ($approvedPct > 70.0 && $avgScore > $threshold) {
                $suggestions[$class] = 'Increase threshold for this class';
            } else {
                $suggestions[$class] = '—';
            }
        }

        return $suggestions;
    }

    /**
     * Row counts by final_decision (all rows with usable JSON + detections for quality bar).
     *
     * @return array{approved: int, rejected: int, review: int}
     */
    public function getDecisionCountsQualityFiltered(): array
    {
        $counts = ['approved' => 0, 'rejected' => 0, 'review' => 0];

        $rows = PhotoLearningDataset::query()
            ->whereNotNull('moderation_scan_json')
            ->get(['moderation_scan_json', 'final_decision']);

        foreach ($rows as $row) {
            $json = $row->moderation_scan_json;
            if (! is_array($json)) {
                continue;
            }
            $dets = $json['detections'] ?? [];
            if (! is_array($dets) || $dets === []) {
                continue;
            }

            $decision = strtolower((string) ($row->final_decision ?? ''));
            if (isset($counts[$decision])) {
                $counts[$decision]++;
            }
        }

        return $counts;
    }
}
