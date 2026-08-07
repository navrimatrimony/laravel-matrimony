<?php

namespace App\Services\WhoViewed;

use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\ViewTrackingService;
use App\Support\SchemaPresence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily distinct-viewer trend for the member dashboard chart.
 *
 * Eligibility is deliberately identical to
 * ViewTrackingService::countEligibleDistinctViewersForTeaser() and
 * MemberQuickHubService::resolveWhoViewedCountForUser(): DISTINCT
 * viewer_profile_id, admin viewers excluded, suspended viewer profiles
 * excluded, blocked viewers excluded, self-views excluded. The chart total
 * must never contradict the who-viewed-me counter.
 */
final class ProfileViewTrendService
{
    public const DEFAULT_WINDOW_DAYS = 7;

    /**
     * Distinct viewers per day for the last $days days (including today),
     * oldest first, plus the totals for this window and the one before it.
     *
     * @return array{days: list<array{date: string, count: int}>, total: int, previous_total: int, window_days: int}
     */
    public function dailyTrend(MatrimonyProfile $owner, int $days = self::DEFAULT_WINDOW_DAYS): array
    {
        $days = max(1, min(31, $days));
        $today = Carbon::today();
        $windowStart = $today->copy()->subDays($days - 1);
        $previousStart = $windowStart->copy()->subDays($days);

        $counts = $this->distinctViewersByDate($owner, $previousStart, $today);

        $current = [];
        $total = 0;
        for ($i = 0; $i < $days; $i++) {
            $date = $windowStart->copy()->addDays($i)->toDateString();
            $count = (int) ($counts[$date] ?? 0);
            $total += $count;
            $current[] = ['date' => $date, 'count' => $count];
        }

        $previousTotal = 0;
        for ($i = 0; $i < $days; $i++) {
            $date = $previousStart->copy()->addDays($i)->toDateString();
            $previousTotal += (int) ($counts[$date] ?? 0);
        }

        return [
            'days' => $current,
            'total' => $total,
            'previous_total' => $previousTotal,
            'window_days' => $days,
        ];
    }

    /**
     * One grouped query over the whole 2x window. Days with no rows are simply
     * absent from the map; the caller fills them with 0.
     *
     * @return array<string, int>
     */
    private function distinctViewersByDate(MatrimonyProfile $owner, Carbon $from, Carbon $to): array
    {
        if (! SchemaPresence::hasTable('profile_views')) {
            return [];
        }

        $ownerId = (int) $owner->id;
        $blocked = ViewTrackingService::getBlockedProfileIds($ownerId);
        $vpTable = (new MatrimonyProfile)->getTable();
        $uTable = (new User)->getTable();

        $query = DB::table('profile_views')
            ->join("{$vpTable} as vp", 'vp.id', '=', 'profile_views.viewer_profile_id')
            ->join("{$uTable} as u", 'u.id', '=', 'vp.user_id')
            ->where('profile_views.viewed_profile_id', $ownerId)
            ->where('profile_views.viewer_profile_id', '!=', $ownerId)
            ->where('profile_views.created_at', '>=', $from->copy()->startOfDay())
            ->where('profile_views.created_at', '<=', $to->copy()->endOfDay())
            ->where(function ($q): void {
                $q->whereNull('u.is_admin')->orWhere('u.is_admin', false);
            })
            ->where(function ($q): void {
                $q->whereNull('vp.is_suspended')->orWhere('vp.is_suspended', false);
            });

        if ($blocked->isNotEmpty()) {
            $query->whereNotIn('profile_views.viewer_profile_id', $blocked->all());
        }

        $rows = $query
            ->selectRaw('DATE(profile_views.created_at) as view_date, COUNT(DISTINCT profile_views.viewer_profile_id) as c')
            ->groupBy('view_date')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->view_date] = (int) $row->c;
        }

        return $out;
    }
}
