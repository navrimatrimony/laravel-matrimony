<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Governance\GovernanceAnalyticsService;
use App\Services\Governance\MutationAuditAggregator;
use App\Services\Governance\SuspiciousChangeDetector;
use Carbon\Carbon;
use Illuminate\Http\Request;

class GovernanceDashboardController extends Controller
{
    /**
     * Phase-5 Day-24: Governance observability dashboard (read-only).
     * Date filter: 7d, 30d, or custom (from/to). No writes.
     */
    public function index(
        Request $request,
        GovernanceAnalyticsService $analytics,
        MutationAuditAggregator $aggregator,
        SuspiciousChangeDetector $detector
    ) {
        $to = Carbon::now();
        $from = Carbon::now()->subDays(30);
        $period = $request->input('period', '30d');

        if ($period === '7d') {
            $from = Carbon::now()->subDays(7);
        } elseif ($period === 'custom') {
            $fromInput = $request->input('from');
            $toInput = $request->input('to');
            if ($fromInput) {
                $from = Carbon::parse($fromInput)->startOfDay();
            }
            if ($toInput) {
                $to = Carbon::parse($toInput)->endOfDay();
            }
        } else {
            $from = Carbon::now()->subDays(30);
        }

        $mutationCounts = $analytics->getMutationCounts($from, $to);
        $conflictMetrics = $analytics->getConflictMetrics($from, $to);
        $highMutationProfiles = $analytics->getHighMutationProfiles(20);
        $duplicateConflictCount = $analytics->getDuplicateConflictCount();

        $incomeSpikes = $detector->detectIncomeSpike(2.0, 30, $from, $to);
        $casteFlips = $detector->detectCasteFlipAfterSeriousIntent(30, $from, $to);
        $dobAfterActive = $detector->detectDobChangeAfterActive(30, $from, $to);
        $frequentContactChanges = $detector->detectFrequentContactChanges(3, 30, $from, $to);

        $highRiskProfileIds = [];
        foreach ([$incomeSpikes, $casteFlips, $dobAfterActive, $frequentContactChanges] as $list) {
            foreach ($list as $row) {
                $id = $row['profile_id'] ?? null;
                if ($id !== null) {
                    $highRiskProfileIds[$id] = true;
                }
            }
        }
        $highRiskProfileCount = count($highRiskProfileIds);

        $totalMutations = (int) array_sum(array_column($mutationCounts, 'count'));
        $conflictPending = (int) ($conflictMetrics['pending'] ?? 0);

        $profileDateGroups = $aggregator->groupByProfileAndDate($from, $to, 100);
        $batchSummaries = $aggregator->summarizeBatchChanges($from, $to, 100);

        return view('admin.governance.dashboard', compact(
            'mutationCounts',
            'conflictMetrics',
            'highMutationProfiles',
            'duplicateConflictCount',
            'incomeSpikes',
            'casteFlips',
            'dobAfterActive',
            'frequentContactChanges',
            'profileDateGroups',
            'batchSummaries',
            'totalMutations',
            'conflictPending',
            'highRiskProfileCount',
            'from',
            'to',
            'period'
        ));
    }
}
