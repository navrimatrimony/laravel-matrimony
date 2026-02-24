<?php

namespace App\Services\Governance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5 Day-24: Read-only aggregation of profile_change_history.
 * No writes. Query Builder only.
 */
class MutationAuditAggregator
{
    /**
     * Group change history by profile_id and date(changed_at).
     *
     * @param  \Carbon\Carbon|null  $from
     * @param  \Carbon\Carbon|null  $to
     * @param  int  $limit
     * @return array<int, array{profile_id: int, change_date: string, change_count: int}>
     */
    public function groupByProfileAndDate(?\Carbon\Carbon $from = null, ?\Carbon\Carbon $to = null, int $limit = 200): array
    {
        if (!Schema::hasTable('profile_change_history')) {
            return [];
        }
        $driver = DB::connection()->getDriverName();
        $dateExpr = $driver === 'mysql'
            ? 'DATE(profile_change_history.changed_at)'
            : "date(profile_change_history.changed_at)";
        $query = DB::table('profile_change_history')
            ->select('profile_id', DB::raw("{$dateExpr} as change_date"), DB::raw('COUNT(*) as change_count'))
            ->groupBy('profile_id', DB::raw($dateExpr))
            ->orderByDesc('change_date')
            ->orderByDesc('change_count')
            ->limit($limit);
        if ($from !== null) {
            $query->where('changed_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('changed_at', '<=', $to);
        }
        return $query->get()->map(fn ($row) => [
            'profile_id' => (int) $row->profile_id,
            'change_date' => $row->change_date,
            'change_count' => (int) $row->change_count,
        ])->all();
    }

    /**
     * Summarize changes by batch (mutation_batch_id if column exists, else by profile_id + source).
     * MySQL ONLY_FULL_GROUP_BY safe: no DATE() in GROUP BY; MIN(changed_at) used for ordering only.
     *
     * @param  \Carbon\Carbon|null  $from
     * @param  \Carbon\Carbon|null  $to
     * @param  int  $limit
     * @return array<int, array{batch_id: int|string, profile_id: int|null, source: string|null, change_count: int}>
     */
    public function summarizeBatchChanges(?\Carbon\Carbon $from = null, ?\Carbon\Carbon $to = null, int $limit = 100): array
    {
        if (!Schema::hasTable('profile_change_history')) {
            return [];
        }

        $hasBatchId = Schema::hasColumn('profile_change_history', 'mutation_batch_id');

        if ($hasBatchId) {

            $query = DB::table('profile_change_history')
                ->select(
                    'mutation_batch_id as batch_id',
                    'profile_id',
                    'source',
                    DB::raw('COUNT(*) as change_count'),
                    DB::raw('MIN(changed_at) as first_change_at')
                )
                ->whereNotNull('mutation_batch_id')
                ->groupBy('mutation_batch_id', 'profile_id', 'source')
                ->orderByDesc('first_change_at')
                ->limit($limit);

        } else {

            $query = DB::table('profile_change_history')
                ->select(
                    DB::raw("CONCAT(profile_id, '-', DATE(MIN(changed_at))) as batch_id"),
                    'profile_id',
                    'source',
                    DB::raw('COUNT(*) as change_count'),
                    DB::raw('MIN(changed_at) as first_change_at')
                )
                ->groupBy('profile_id', 'source')
                ->orderByDesc('first_change_at')
                ->limit($limit);
        }

        if ($from !== null) {
            $query->where('changed_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('changed_at', '<=', $to);
        }

        return $query->get()->map(fn ($row) => [
            'batch_id' => $row->batch_id,
            'profile_id' => $row->profile_id !== null ? (int) $row->profile_id : null,
            'source' => $row->source ?? null,
            'change_count' => (int) $row->change_count,
        ])->all();
    }
}
