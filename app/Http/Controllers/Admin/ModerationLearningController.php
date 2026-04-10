<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ModerationLearningAnalyticsService;
use Illuminate\View\View;

class ModerationLearningController extends Controller
{
    public function index(ModerationLearningAnalyticsService $analytics): View
    {
        $classStats = $analytics->getClassStats();
        $suggestions = $analytics->getThresholdSuggestions($classStats);
        $thresholdBaseline = $analytics->getNsfwThresholdBaseline();
        $decisionCounts = $analytics->getDecisionCountsQualityFiltered();

        $rows = [];
        foreach ($classStats as $class => $stats) {
            $total = (int) ($stats['total'] ?? 0);
            $approved = (int) ($stats['approved'] ?? 0);
            $rejected = (int) ($stats['rejected'] ?? 0);
            $review = (int) ($stats['review'] ?? 0);
            $rows[] = [
                'class' => $class,
                'total' => $total,
                'approved_pct' => $total > 0 ? round(100 * $approved / $total, 1) : 0.0,
                'rejected_pct' => $total > 0 ? round(100 * $rejected / $total, 1) : 0.0,
                'review_pct' => $total > 0 ? round(100 * $review / $total, 1) : 0.0,
                'avg_score' => (float) ($stats['avg_score'] ?? 0),
                'suggestion' => $suggestions[$class] ?? '—',
            ];
        }

        $barTotal = ($decisionCounts['approved'] ?? 0) + ($decisionCounts['rejected'] ?? 0) + ($decisionCounts['review'] ?? 0);

        return view('admin.moderation-learning.index', [
            'rows' => $rows,
            'thresholdBaseline' => $thresholdBaseline,
            'decisionCounts' => $decisionCounts,
            'barTotal' => $barTotal,
        ]);
    }
}
