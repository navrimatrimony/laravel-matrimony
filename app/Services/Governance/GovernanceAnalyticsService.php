<?php

namespace App\Services\Governance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5 Day-24: Governance observability — read-only analytics.
 * Uses Query Builder only. No update/insert/lifecycle change.
 */
class GovernanceAnalyticsService
{
    /**
     * Mutation counts by source (and optionally by date range).
     *
     * @param  \Carbon\Carbon|null  $from
     * @param  \Carbon\Carbon|null  $to
     * @return array<int, array{source: string, count: int}>
     */
    public function getMutationCounts(?\Carbon\Carbon $from = null, ?\Carbon\Carbon $to = null): array
    {
        if (!Schema::hasTable('profile_change_history')) {
            return [];
        }
        $query = DB::table('profile_change_history')
            ->select('source', DB::raw('COUNT(*) as count'))
            ->groupBy('source')
            ->orderByDesc('count');
        if ($from !== null) {
            $query->where('changed_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('changed_at', '<=', $to);
        }
        return $query->get()->map(fn ($row) => ['source' => $row->source, 'count' => (int) $row->count])->all();
    }

    /**
     * Conflict metrics: pending vs resolved, by field_type.
     * Optional date filter on detected_at.
     *
     * @param  \Carbon\Carbon|null  $from
     * @param  \Carbon\Carbon|null  $to
     * @return array{total: int, pending: int, resolved: int, by_field_type: array<string, array{pending: int, resolved: int}>}
     */
    public function getConflictMetrics(?\Carbon\Carbon $from = null, ?\Carbon\Carbon $to = null): array
    {
        if (!Schema::hasTable('conflict_records')) {
            return ['total' => 0, 'pending' => 0, 'resolved' => 0, 'by_field_type' => []];
        }
        $totalsQuery = DB::table('conflict_records')
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN resolution_status = 'PENDING' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN resolution_status != 'PENDING' THEN 1 ELSE 0 END) as resolved");
        if ($from !== null) {
            $totalsQuery->where('detected_at', '>=', $from);
        }
        if ($to !== null) {
            $totalsQuery->where('detected_at', '<=', $to);
        }
        $totals = $totalsQuery->first();
        $byTypeQuery = DB::table('conflict_records')
            ->select('field_type')
            ->selectRaw("SUM(CASE WHEN resolution_status = 'PENDING' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN resolution_status != 'PENDING' THEN 1 ELSE 0 END) as resolved")
            ->groupBy('field_type');
        if ($from !== null) {
            $byTypeQuery->where('detected_at', '>=', $from);
        }
        if ($to !== null) {
            $byTypeQuery->where('detected_at', '<=', $to);
        }
        $byType = $byTypeQuery
            ->get()
            ->keyBy('field_type')
            ->map(fn ($row) => ['pending' => (int) $row->pending, 'resolved' => (int) $row->resolved])
            ->all();
        return [
            'total' => (int) ($totals->total ?? 0),
            'pending' => (int) ($totals->pending ?? 0),
            'resolved' => (int) ($totals->resolved ?? 0),
            'by_field_type' => $byType,
        ];
    }

    /**
     * Profiles with highest mutation (change history) count.
     *
     * @param  int  $limit
     * @return array<int, array{profile_id: int, mutation_count: int, full_name: string|null}>
     */
    public function getHighMutationProfiles(int $limit = 20): array
    {
        if (!Schema::hasTable('profile_change_history')) {
            return [];
        }
        $profileIds = DB::table('profile_change_history')
            ->select('profile_id', DB::raw('COUNT(*) as mutation_count'))
            ->groupBy('profile_id')
            ->orderByDesc('mutation_count')
            ->limit($limit)
            ->pluck('mutation_count', 'profile_id');
        if ($profileIds->isEmpty()) {
            return [];
        }
        $names = Schema::hasTable('matrimony_profiles')
            ? DB::table('matrimony_profiles')->whereIn('id', $profileIds->keys())->pluck('full_name', 'id')->all()
            : [];
        return $profileIds->map(fn ($count, $id) => [
            'profile_id' => (int) $id,
            'mutation_count' => (int) $count,
            'full_name' => $names[$id] ?? null,
        ])->values()->all();
    }

    /**
     * Count of duplicate_detection conflict records.
     */
    public function getDuplicateConflictCount(): int
    {
        if (!Schema::hasTable('conflict_records')) {
            return 0;
        }
        return (int) DB::table('conflict_records')
            ->where('field_name', 'duplicate_detection')
            ->count();
    }
}
