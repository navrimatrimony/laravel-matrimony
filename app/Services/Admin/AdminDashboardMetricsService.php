<?php

namespace App\Services\Admin;

use App\Models\AbuseReport;
use App\Models\MatrimonyProfile;
use App\Models\ProfileBoost;
use App\Models\ProfilePhoto;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserActivity;
use App\Models\UserFlag;
use App\Models\UserReferral;
use App\Support\UserFeatureUsageKeys;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregated admin dashboard metrics (cached, set-based queries; no N+1).
 */
final class AdminDashboardMetricsService
{
    public const RANGE_TODAY = 'today';

    public const RANGE_7D = '7d';

    public const RANGE_30D = '30d';

    public const RANGE_MONTH = 'month';

    public const RANGE_YEAR = 'year';

    public const INSIGHT_INTEREST_TO_CHAT = 'interest_to_chat_low';

    public const INSIGHT_CHAT_TO_PAYMENT = 'chat_to_payment_low';

    public const INSIGHT_REVENUE_DROP = 'revenue_drop';

    public const INSIGHT_HIGH_RISK = 'high_risk_accounts';

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function resolveDateRange(string $range): array
    {
        $range = self::normalizeRange($range);
        $now = now();

        return match ($range) {
            self::RANGE_TODAY => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            self::RANGE_7D => [$now->copy()->subDays(7)->startOfDay(), $now->copy()],
            self::RANGE_30D => [$now->copy()->subDays(30)->startOfDay(), $now->copy()],
            self::RANGE_MONTH => [$now->copy()->startOfMonth()->startOfDay(), $now->copy()],
            self::RANGE_YEAR => [$now->copy()->startOfYear()->startOfDay(), $now->copy()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    public static function normalizeRange(string $range): string
    {
        return in_array($range, [
            self::RANGE_TODAY,
            self::RANGE_7D,
            self::RANGE_30D,
            self::RANGE_MONTH,
            self::RANGE_YEAR,
        ], true) ? $range : self::RANGE_TODAY;
    }

    public static function normalizeCompare(string $compare): string
    {
        return in_array($compare, [
            'none',
            'yesterday',
            'last_week_same_day',
            'last_week',
            'last_month',
        ], true) ? $compare : 'none';
    }

    /**
     * Resolves comparison windows using {@see resolveDateRange()} for the primary period (except
     * {@code last_week} / {@code last_month}, which use calendar week/month-to-date vs the aligned prior segment).
     *
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon}|null [currentStart, currentEnd, previousStart, previousEnd]
     */
    public static function resolveComparisonRange(string $range, string $compare): ?array
    {
        $compare = self::normalizeCompare($compare);
        if ($compare === 'none') {
            return null;
        }

        $now = now();

        return match ($compare) {
            'yesterday' => (function () use ($range): array {
                [$cStart, $cEnd] = self::resolveDateRange($range);
                [$pStart, $pEnd] = self::resolvePreviousPeriod($cStart, $cEnd);

                return [$cStart, $cEnd, $pStart, $pEnd];
            })(),
            'last_week_same_day' => (function () use ($range): array {
                [$cStart, $cEnd] = self::resolveDateRange($range);

                return [
                    $cStart,
                    $cEnd,
                    $cStart->copy()->subDays(7),
                    $cEnd->copy()->subDays(7),
                ];
            })(),
            'last_week' => (function () use ($now): array {
                $cStart = $now->copy()->startOfWeek();
                $cEnd = $now->copy();
                $pStart = $cStart->copy()->subWeek();
                $seconds = max(1, $cEnd->getTimestamp() - $cStart->getTimestamp());
                $pEnd = $pStart->copy()->addSeconds($seconds);

                return [$cStart, $cEnd, $pStart, $pEnd];
            })(),
            'last_month' => (function () use ($now): array {
                $cStart = $now->copy()->startOfMonth();
                $cEnd = $now->copy();
                $pStart = $cStart->copy()->subMonth();
                $seconds = max(1, $cEnd->getTimestamp() - $cStart->getTimestamp());
                $pEnd = $pStart->copy()->addSeconds($seconds);

                return [$cStart, $cEnd, $pStart, $pEnd];
            })(),
            default => null,
        };
    }

    /**
     * @return float|null null when the prior value is zero and change is undefined
     */
    public static function percentChange(float|int|null $previous, float|int|null $current): ?float
    {
        if ($previous === null || $current === null) {
            return null;
        }
        $p = (float) $previous;
        $c = (float) $current;
        if (abs($p) < 1e-9) {
            return abs($c) < 1e-9 ? 0.0 : null;
        }

        return round((($c - $p) / $p) * 100, 2);
    }

    private function cacheTtl(): int
    {
        return max(30, (int) config('admin_dashboard.cache_ttl', 90));
    }

    private function cacheKey(string $suffix): string
    {
        return 'admin.dashboard.v2.'.$suffix;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function remember(string $suffix, string $range, callable $callback): mixed
    {
        $range = self::normalizeRange($range);

        return Cache::remember($this->cacheKey($suffix.'.'.$range), $this->cacheTtl(), $callback);
    }

    public function getOverviewStats(string $range = self::RANGE_TODAY, string $compare = 'none'): array
    {
        $range = self::normalizeRange($range);
        $compare = self::normalizeCompare($compare);

        return Cache::remember(
            $this->cacheKey('overview.'.$range.'.'.$compare),
            $this->cacheTtl(),
            function () use ($range, $compare): array {
                $cmp = self::resolveComparisonRange($range, $compare);
                if ($cmp === null) {
                    [$cStart, $cEnd] = self::resolveDateRange($range);
                    $current = $this->computeOverviewStatsForWindow($cStart, $cEnd, $range);

                    return [
                        'current' => $current,
                        'previous' => null,
                        'change' => null,
                        'compare' => $compare,
                    ];
                }

                [$cStart, $cEnd, $pStart, $pEnd] = $cmp;
                $current = $this->computeOverviewStatsForWindow($cStart, $cEnd, $range);
                $previous = $this->computeOverviewStatsForWindow($pStart, $pEnd, $range);
                $change = $this->percentChangeForKeys($current, $previous, [
                    'total_users',
                    'active_users_today',
                    'new_registrations_today',
                    'paid_users_count',
                    'free_users_count',
                    'total_revenue',
                    'monthly_revenue',
                    'conversion_rate_percent',
                ]);

                return [
                    'current' => $current,
                    'previous' => $previous,
                    'change' => $change,
                    'compare' => $compare,
                ];
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computeOverviewStatsForWindow(Carbon $start, Carbon $end, string $rangeLabel): array
    {
        $registrationsInRange = User::query()->whereBetween('created_at', [$start, $end])->count();

        $activeInRange = User::query()
            ->whereNotNull('last_seen_at')
            ->whereBetween('last_seen_at', [$start, $end])
            ->count();

        $paidUsersInRange = $this->distinctPaidUsersWithSubscriptionCreatedBetween($start, $end);

        $freeUsersInRange = max(0, $registrationsInRange - $paidUsersInRange);

        $periodRevenue = $this->subscriptionRevenueSum($start, $end);

        $conversion = $registrationsInRange > 0
            ? round(($paidUsersInRange / $registrationsInRange) * 100, 2)
            : 0.0;

        return [
            'range' => $rangeLabel,
            'period_start' => $start->toIso8601String(),
            'period_end' => $end->toIso8601String(),
            'total_users' => $registrationsInRange,
            'active_users_today' => $activeInRange,
            'new_registrations_today' => $registrationsInRange,
            'paid_users_count' => $paidUsersInRange,
            'free_users_count' => $freeUsersInRange,
            'total_revenue' => round($periodRevenue, 2),
            'monthly_revenue' => round($periodRevenue, 2),
            'conversion_rate_percent' => $conversion,
            'cache_ttl_seconds' => $this->cacheTtl(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function getUserActivityStats(string $range = self::RANGE_TODAY, string $compare = 'none'): array
    {
        $range = self::normalizeRange($range);
        $compare = self::normalizeCompare($compare);

        return Cache::remember(
            $this->cacheKey('activity.'.$range.'.'.$compare),
            $this->cacheTtl(),
            function () use ($range, $compare): array {
                $cmp = self::resolveComparisonRange($range, $compare);
                if ($cmp === null) {
                    [$cStart, $cEnd] = self::resolveDateRange($range);
                    $current = $this->computeUserActivityStatsForWindow($cStart, $cEnd, $range);

                    return [
                        'current' => $current,
                        'previous' => null,
                        'change' => null,
                        'compare' => $compare,
                    ];
                }

                [$cStart, $cEnd, $pStart, $pEnd] = $cmp;
                $current = $this->computeUserActivityStatsForWindow($cStart, $cEnd, $range);
                $previous = $this->computeUserActivityStatsForWindow($pStart, $pEnd, $range);
                $change = $this->percentChangeForKeys($current, $previous, [
                    'daily_logins',
                    'profiles_created_today',
                    'interests_sent_today',
                    'chats_started_today',
                    'messages_sent_today',
                    'contact_views_today',
                ]);

                return [
                    'current' => $current,
                    'previous' => $previous,
                    'change' => $change,
                    'compare' => $compare,
                ];
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computeUserActivityStatsForWindow(Carbon $start, Carbon $end, string $rangeLabel): array
    {
        $dailyLogins = $this->sessionDistinctLoginCountBetween($start, $end);

        $profilesCreated = MatrimonyProfile::query()
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $interestsSent = DB::table('interests')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $chatsStarted = DB::table('conversations')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $messagesSent = DB::table('messages')
            ->whereBetween('sent_at', [$start, $end])
            ->count();

        $contactViews = 0;
        if (Schema::hasTable('profile_views')) {
            $contactViews = DB::table('profile_views')
                ->whereBetween('created_at', [$start, $end])
                ->count();
        }

        return [
            'range' => $rangeLabel,
            'daily_logins' => $dailyLogins,
            'profiles_created_today' => $profilesCreated,
            'interests_sent_today' => $interestsSent,
            'chats_started_today' => $chatsStarted,
            'messages_sent_today' => $messagesSent,
            'contact_views_today' => $contactViews,
            'note' => 'contact_views_today uses profile_views (who viewed whom). Contact unlock quota usage is in monetization block.',
            'cache_ttl_seconds' => $this->cacheTtl(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function getRevenueStats(string $range = self::RANGE_TODAY, string $compare = 'none'): array
    {
        $range = self::normalizeRange($range);
        $compare = self::normalizeCompare($compare);

        return Cache::remember(
            $this->cacheKey('revenue.'.$range.'.'.$compare),
            $this->cacheTtl(),
            function () use ($range, $compare): array {
                $cmp = self::resolveComparisonRange($range, $compare);
                if ($cmp === null) {
                    [$cStart, $cEnd] = self::resolveDateRange($range);
                    $current = $this->computeRevenueStatsForWindow($cStart, $cEnd, $range);

                    return [
                        'current' => $current,
                        'previous' => null,
                        'change' => null,
                        'compare' => $compare,
                    ];
                }

                [$cStart, $cEnd, $pStart, $pEnd] = $cmp;
                $current = $this->computeRevenueStatsForWindow($cStart, $cEnd, $range);
                $previous = $this->computeRevenueStatsForWindow($pStart, $pEnd, $range);
                $change = $this->percentChangeForKeys($current, $previous, [
                    'total_revenue',
                    'coupon_usage_count',
                    'referral_conversions',
                    'contact_unlock_count',
                    'boost_purchases',
                ]);

                return [
                    'current' => $current,
                    'previous' => $previous,
                    'change' => $change,
                    'compare' => $compare,
                ];
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computeRevenueStatsForWindow(Carbon $start, Carbon $end, string $rangeLabel): array
    {
        $revenueByPlan = $this->revenueGroupedByPlanBetween($start, $end);

        $couponUsage = 0;
        if (Schema::hasTable('subscriptions')) {
            $couponUsage = Subscription::query()
                ->whereBetween('created_at', [$start, $end])
                ->whereNotNull('coupon_id')
                ->count();
        }

        $referralConversions = 0;
        if (Schema::hasTable('user_referrals')) {
            $referralConversions = UserReferral::query()
                ->whereBetween('created_at', [$start, $end])
                ->where('reward_applied', true)
                ->count();
        }

        $contactUnlocks = 0;
        if (Schema::hasTable('user_feature_usages')) {
            $contactUnlocks = (int) DB::table('user_feature_usages')
                ->where('feature_key', UserFeatureUsageKeys::CONTACT_VIEW_LIMIT)
                ->whereBetween('updated_at', [$start, $end])
                ->sum('used_count');
        }

        $boostPurchases = Schema::hasTable('profile_boosts')
            ? ProfileBoost::query()->whereBetween('created_at', [$start, $end])->count()
            : 0;

        $totalRevenue = 0.0;
        foreach ($revenueByPlan as $row) {
            $totalRevenue += (float) ($row['revenue'] ?? 0);
        }

        return [
            'range' => $rangeLabel,
            'revenue_by_plan' => $revenueByPlan,
            'total_revenue' => round($totalRevenue, 2),
            'coupon_usage_count' => $couponUsage,
            'referral_conversions' => $referralConversions,
            'contact_unlock_count' => $contactUnlocks,
            'boost_purchases' => $boostPurchases,
            'cache_ttl_seconds' => $this->cacheTtl(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @param  list<string>  $keys
     * @return array<string, float|null>
     */
    private function percentChangeForKeys(array $current, array $previous, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = self::percentChange(
                isset($previous[$k]) ? (float) $previous[$k] : null,
                isset($current[$k]) ? (float) $current[$k] : null,
            );
        }

        return $out;
    }

    public function getFunnelStats(string $range = self::RANGE_TODAY): array
    {
        $range = self::normalizeRange($range);

        return $this->remember('funnel', $range, function () use ($range): array {
            [$start, $end] = self::resolveDateRange($range);

            $signups = User::query()->whereBetween('created_at', [$start, $end])->count();

            $profileCompleted = MatrimonyProfile::query()
                ->where('lifecycle_state', 'active')
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $interestUsers = $this->countDistinctUsersFromInterestsBetween($start, $end);
            $chatUsers = $this->countDistinctUsersFromConversationsBetween($start, $end);
            $paymentUsers = $this->countDistinctUsersWithPaidSubscriptionBetween($start, $end);

            $stages = [
                'signups' => $signups,
                'profile_completed_active' => $profileCompleted,
                'interest_sent' => $interestUsers,
                'chat_started' => $chatUsers,
                'payment_done' => $paymentUsers,
            ];

            $dropoffs = $this->funnelDropoffs($stages);

            return [
                'range' => $range,
                'stages' => $stages,
                'dropoff_percent' => $dropoffs,
                'definitions' => [
                    'profile_completed_active' => 'matrimony_profiles.lifecycle_state = active, created_at in range',
                    'interest_sent' => 'distinct profile owners who sent interest (interest.created_at in range)',
                    'chat_started' => 'distinct users who created conversation (conversation.created_at in range)',
                    'payment_done' => 'distinct users with non-pending subscription created in range',
                ],
                'cache_ttl_seconds' => $this->cacheTtl(),
                'generated_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Time-bucketed series for charts (max ~60 points). Cached separately.
     *
     * When {@code compare} is set, {@code previous} holds the prior window aligned to the same bucket layout.
     *
     * @return array{range: string, compare: string, dates: list<string>, registrations: list<int>, revenue: list<float>, interests: list<int>, engagements: list<int>, previous: array<string, mixed>|null, bucket_count: int, cache_ttl_seconds: int, generated_at: string}
     */
    public function getTimeSeriesData(string $range = self::RANGE_TODAY, string $compare = 'none'): array
    {
        $range = self::normalizeRange($range);
        $compare = self::normalizeCompare($compare);

        return Cache::remember(
            $this->cacheKey('timeseries.'.$range.'.'.$compare),
            $this->cacheTtl(),
            function () use ($range, $compare): array {
                $cmp = self::resolveComparisonRange($range, $compare);
                [$cStart, $cEnd] = $cmp === null
                    ? self::resolveDateRange($range)
                    : [$cmp[0], $cmp[1]];
                $buckets = $this->buildTimeBuckets($range, $cStart, $cEnd);
                $cur = $this->fillTimeSeriesForBuckets($range, $buckets);

                $base = [
                    'range' => $range,
                    'compare' => $compare,
                    'dates' => $cur['dates'],
                    'registrations' => $cur['registrations'],
                    'revenue' => $cur['revenue'],
                    'interests' => $cur['interests'],
                    'engagements' => $cur['engagements'],
                    'bucket_count' => count($buckets),
                    'cache_ttl_seconds' => $this->cacheTtl(),
                    'generated_at' => now()->toIso8601String(),
                ];

                if ($cmp === null) {
                    $base['previous'] = null;

                    return $base;
                }

                $pbuckets = $this->buildTimeBuckets($range, $cmp[2], $cmp[3]);
                $prev = $this->fillTimeSeriesForBuckets($range, $pbuckets);
                $n = min(count($cur['dates']), count($prev['dates']));
                $base['previous'] = [
                    'dates' => array_slice($prev['dates'], 0, $n),
                    'registrations' => array_slice($prev['registrations'], 0, $n),
                    'revenue' => array_slice($prev['revenue'], 0, $n),
                    'interests' => array_slice($prev['interests'], 0, $n),
                    'engagements' => array_slice($prev['engagements'], 0, $n),
                ];

                return $base;
            }
        );
    }

    /**
     * @param  list<array{0: Carbon, 1: Carbon}>  $buckets
     * @return array{dates: list<string>, registrations: list<int>, revenue: list<float>, interests: list<int>, engagements: list<int>}
     */
    private function fillTimeSeriesForBuckets(string $range, array $buckets): array
    {
        $dates = [];
        $registrations = [];
        $revenue = [];
        $interests = [];
        $engagements = [];

        foreach ($buckets as [$bStart, $bEnd]) {
            $dates[] = $bStart->format($range === self::RANGE_TODAY ? 'H:00' : 'Y-m-d');

            $registrations[] = User::query()->whereBetween('created_at', [$bStart, $bEnd])->count();

            $revenue[] = round($this->subscriptionRevenueSum($bStart, $bEnd), 2);

            $ic = (int) DB::table('interests')
                ->whereBetween('created_at', [$bStart, $bEnd])
                ->count();
            $interests[] = $ic;

            $msg = (int) DB::table('messages')->whereBetween('sent_at', [$bStart, $bEnd])->count();
            $conv = (int) DB::table('conversations')->whereBetween('created_at', [$bStart, $bEnd])->count();
            $engagements[] = $ic + $msg + $conv;
        }

        return [
            'dates' => $dates,
            'registrations' => $registrations,
            'revenue' => $revenue,
            'interests' => $interests,
            'engagements' => $engagements,
        ];
    }

    /**
     * Lightweight rule-based insights (reuses funnel, revenue, risk).
     * Per-admin follow-ups and suppression use {@code user_activities}; cache key includes user id + version bump.
     *
     * @return array{insights: list<array{type: string, message: string, suggestion?: string, insight_key: string, priority: string, meta: array{previous: float|int, current: float|int}, actions: list<array{label: string, url: string}>}>, range: string, cache_ttl_seconds: int, generated_at: string}
     */
    public function getInsights(string $range = self::RANGE_TODAY, ?int $userId = null): array
    {
        $range = self::normalizeRange($range);
        $userId = $userId ?? 0;
        $version = (int) Cache::get('admin.dashboard.insights_cache_version', 0);

        return Cache::remember(
            $this->cacheKey('insights.v4.'.$range.'.u'.$userId.'.v'.$version),
            $this->cacheTtl(),
            function () use ($range, $userId): array {
                $funnel = $this->getFunnelStats($range);
                $stages = $funnel['stages'] ?? [];
                $interestSent = (int) ($stages['interest_sent'] ?? 0);
                $chatStarted = (int) ($stages['chat_started'] ?? 0);
                $paymentDone = (int) ($stages['payment_done'] ?? 0);

                $insights = [];

                if ($interestSent > 0 && ($chatStarted / $interestSent) < 0.3) {
                    $ratio = $chatStarted / $interestSent;
                    $insights[] = [
                        'type' => 'warning',
                        'insight_key' => self::INSIGHT_INTEREST_TO_CHAT,
                        'message' => 'Low interest to chat conversion',
                        'suggestion' => 'Review chat access rules, response times, and nudges after interest is sent or accepted.',
                        'priority' => $ratio < 0.15 ? 'high' : 'medium',
                        'meta' => [
                            'previous' => $interestSent,
                            'current' => $chatStarted,
                        ],
                        'actions' => $this->insightActionsLowConversion(),
                    ];
                }

                if ($chatStarted > 0 && ($paymentDone / $chatStarted) < 0.2) {
                    $ratio = $paymentDone / $chatStarted;
                    $insights[] = [
                        'type' => 'warning',
                        'insight_key' => self::INSIGHT_CHAT_TO_PAYMENT,
                        'message' => 'Low chat to payment conversion',
                        'suggestion' => 'Surface plan benefits in chat, reduce checkout friction, and audit paywall timing.',
                        'priority' => $ratio < 0.1 ? 'high' : 'medium',
                        'meta' => [
                            'previous' => $chatStarted,
                            'current' => $paymentDone,
                        ],
                        'actions' => $this->insightActionsLowConversion(),
                    ];
                }

                [$start, $end] = self::resolveDateRange($range);
                $currentRevenue = $this->subscriptionRevenueSum($start, $end);
                [$prevStart, $prevEnd] = self::resolvePreviousPeriod($start, $end);
                $prevRevenue = $this->subscriptionRevenueSum($prevStart, $prevEnd);

                $minRev = (float) config('admin_dashboard.insights.min_revenue_threshold', 1000);

                if ($prevRevenue > $minRev && $prevRevenue > 0.01) {
                    $dropPercent = (($prevRevenue - $currentRevenue) / $prevRevenue) * 100;
                    if ($dropPercent > 30) {
                        $insights[] = [
                            'type' => 'warning',
                            'insight_key' => self::INSIGHT_REVENUE_DROP,
                            'message' => 'Revenue dropped significantly',
                            'suggestion' => 'Compare marketing, coupons, and pricing with the prior period; check failed payments.',
                            'priority' => 'high',
                            'meta' => [
                                'previous' => round($prevRevenue, 2),
                                'current' => round($currentRevenue, 2),
                            ],
                            'actions' => $this->insightActionsRevenueDrop(),
                        ];
                    }
                }

                $risk = $this->getRiskAlerts();
                $riskyCount = count($risk['alerts'] ?? []);
                $riskThreshold = max(0, (int) config('admin_dashboard.insights.risk_count_threshold', 10));
                if ($riskyCount > $riskThreshold) {
                    $insights[] = [
                        'type' => 'warning',
                        'insight_key' => self::INSIGHT_HIGH_RISK,
                        'message' => 'High suspicious activity',
                        'suggestion' => 'Prioritize flagged accounts, duplicate phones, and open abuse reports in moderation.',
                        'priority' => 'high',
                        'meta' => [
                            'previous' => $riskThreshold,
                            'current' => $riskyCount,
                        ],
                        'actions' => $this->insightActionsHighRisk(),
                    ];
                }

                $suppressed = $userId > 0 ? $this->suppressedInsightKeys($userId) : [];
                if ($suppressed !== []) {
                    $insights = array_values(array_filter($insights, function (array $row) use ($suppressed): bool {
                        $k = $row['insight_key'] ?? '';

                        return $k === '' || ! in_array($k, $suppressed, true);
                    }));
                }

                usort($insights, function (array $a, array $b): int {
                    return $this->insightPriorityRank($a['priority'] ?? 'low')
                        <=> $this->insightPriorityRank($b['priority'] ?? 'low');
                });

                $followUps = $userId > 0 ? $this->followUpInsightsForUser($userId) : [];
                $insights = array_merge($followUps, $insights);

                return [
                    'insights' => $insights,
                    'range' => $range,
                    'cache_ttl_seconds' => $this->cacheTtl(),
                    'generated_at' => now()->toIso8601String(),
                ];
            }
        );
    }

    /**
     * Scan completed post-action windows and append {@code admin_action_effect} rows (24–72h after click).
     */
    public function evaluatePendingAdminActionEffects(): int
    {
        if (! Schema::hasTable('user_activities')) {
            return 0;
        }

        $processedIds = UserActivity::query()
            ->where('type', 'admin_action_effect')
            ->get()
            ->map(fn ($row) => (int) (($row->meta ?? [])['related_click_id'] ?? 0))
            ->filter()
            ->all();
        $processedSet = array_flip($processedIds);

        $clicks = UserActivity::query()
            ->where('type', 'admin_action_click')
            ->where('created_at', '<=', now()->subHours(72))
            ->where('created_at', '>=', now()->subDays(90))
            ->orderBy('id')
            ->get();

        $n = 0;
        foreach ($clicks as $click) {
            if (isset($processedSet[$click->id])) {
                continue;
            }
            $payload = $this->evaluateAdminActionClickEffect($click);
            if ($payload === null) {
                continue;
            }
            UserActivity::query()->create([
                'user_id' => $click->user_id,
                'type' => 'admin_action_effect',
                'meta' => $payload,
                'created_at' => now(),
            ]);
            $processedSet[$click->id] = true;
            $n++;
        }

        if ($n > 0) {
            $v = (int) Cache::get('admin.dashboard.insights_cache_version', 0);
            Cache::put('admin.dashboard.insights_cache_version', $v + 1, now()->addYear());
        }

        return $n;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function evaluateAdminActionClickEffect(UserActivity $click): ?array
    {
        $meta = $click->meta ?? [];
        $insightKey = $meta['insight_key'] ?? null;
        if (! is_string($insightKey) || $insightKey === '' || str_starts_with($insightKey, 'follow_up')) {
            return null;
        }

        $t = $click->created_at instanceof Carbon ? $click->created_at->copy() : Carbon::parse((string) $click->created_at);
        if (now()->lt($t->copy()->addHours(72))) {
            return null;
        }

        $beforeStart = $t->copy()->subHours(48);
        $beforeEnd = $t->copy();
        $afterStart = $t->copy()->addHours(24);
        $afterEnd = $t->copy()->addHours(72);

        $metric = $this->resolveInsightMetric($insightKey);
        $eval = $this->computeMetricChange($metric, $beforeStart, $beforeEnd, $afterStart, $afterEnd);

        return [
            'related_click_id' => $click->id,
            'insight_key' => $insightKey,
            'action' => (string) ($meta['label'] ?? 'admin_action'),
            'metric' => $metric,
            'change_percent' => round($eval['change_percent'], 2),
            'improvement' => $eval['improvement'],
            'previous' => $eval['previous'],
            'current' => $eval['current'],
        ];
    }

    /**
     * @return list<string>
     */
    private function suppressedInsightKeys(int $userId): array
    {
        if (! Schema::hasTable('user_activities')) {
            return [];
        }

        $hours = max(1, (int) config('admin_dashboard.insights.suppression_hours', 48));

        $rows = UserActivity::query()
            ->where('type', 'admin_action_effect')
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subHours($hours))
            ->get();

        $keys = [];
        foreach ($rows as $row) {
            $m = $row->meta ?? [];
            if (empty($m['improvement']) || empty($m['insight_key'])) {
                continue;
            }
            $keys[] = (string) $m['insight_key'];
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function followUpInsightsForUser(int $userId): array
    {
        if (! Schema::hasTable('user_activities')) {
            return [];
        }

        $hours = max(1, (int) config('admin_dashboard.insights.follow_up_hours', 48));

        $rows = UserActivity::query()
            ->where('type', 'admin_action_effect')
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subHours($hours))
            ->orderByDesc('id')
            ->limit(12)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $m = $row->meta ?? [];
            if (empty($m['improvement'])) {
                continue;
            }
            $metric = (string) ($m['metric'] ?? 'revenue');
            $cp = abs((float) ($m['change_percent'] ?? 0));
            $message = $this->followUpMessage($metric, $cp);
            $out[] = [
                'type' => 'success',
                'insight_key' => 'follow_up_'.$row->id,
                'message' => $message,
                'suggestion' => 'Measured in the 24–72h window after your tracked action.',
                'priority' => 'low',
                'meta' => [
                    'previous' => $m['previous'] ?? 0,
                    'current' => $m['current'] ?? 0,
                ],
                'actions' => [],
                'effect_id' => $row->id,
            ];
        }

        return $out;
    }

    private function followUpMessage(string $metric, float $changePercent): string
    {
        $x = round($changePercent, 1);

        return match ($metric) {
            'revenue' => "Your action improved revenue by {$x}%.",
            'interest_to_chat_ratio' => "Your action improved interest-to-chat conversion by {$x}%.",
            'chat_to_payment_ratio' => "Your action improved chat-to-payment conversion by {$x}%.",
            default => "Your action improved this metric by {$x}%.",
        };
    }

    private function resolveInsightMetric(string $insightKey): string
    {
        return match ($insightKey) {
            self::INSIGHT_REVENUE_DROP, self::INSIGHT_HIGH_RISK => 'revenue',
            self::INSIGHT_INTEREST_TO_CHAT => 'interest_to_chat_ratio',
            self::INSIGHT_CHAT_TO_PAYMENT => 'chat_to_payment_ratio',
            default => 'revenue',
        };
    }

    /**
     * @return array{change_percent: float, improvement: bool, previous: float|int, current: float|int}
     */
    private function computeMetricChange(string $metric, Carbon $beforeStart, Carbon $beforeEnd, Carbon $afterStart, Carbon $afterEnd): array
    {
        if ($metric === 'revenue') {
            $before = $this->subscriptionRevenueSum($beforeStart, $beforeEnd);
            $after = $this->subscriptionRevenueSum($afterStart, $afterEnd);
            $change = $before > 0.01 ? (($after - $before) / $before) * 100.0 : ($after > 0 ? 100.0 : 0.0);

            return [
                'change_percent' => $change,
                'improvement' => $after > $before + 0.01,
                'previous' => round($before, 2),
                'current' => round($after, 2),
            ];
        }

        if ($metric === 'interest_to_chat_ratio') {
            $iB = $this->interestCountBetween($beforeStart, $beforeEnd);
            $cB = $this->chatCountBetween($beforeStart, $beforeEnd);
            $rB = $iB > 0 ? $cB / $iB : 0.0;
            $iA = $this->interestCountBetween($afterStart, $afterEnd);
            $cA = $this->chatCountBetween($afterStart, $afterEnd);
            $rA = $iA > 0 ? $cA / $iA : 0.0;
            $change = $rB > 0.0001 ? (($rA - $rB) / $rB) * 100.0 : ($rA > 0 ? 100.0 : 0.0);

            return [
                'change_percent' => $change,
                'improvement' => $rA > $rB + 0.0001,
                'previous' => round($rB * 100, 2),
                'current' => round($rA * 100, 2),
            ];
        }

        $chB = $this->chatCountBetween($beforeStart, $beforeEnd);
        $payB = $this->countDistinctUsersWithPaidSubscriptionBetween($beforeStart, $beforeEnd);
        $rB = $chB > 0 ? $payB / $chB : 0.0;
        $chA = $this->chatCountBetween($afterStart, $afterEnd);
        $payA = $this->countDistinctUsersWithPaidSubscriptionBetween($afterStart, $afterEnd);
        $rA = $chA > 0 ? $payA / $chA : 0.0;
        $change = $rB > 0.0001 ? (($rA - $rB) / $rB) * 100.0 : ($rA > 0 ? 100.0 : 0.0);

        return [
            'change_percent' => $change,
            'improvement' => $rA > $rB + 0.0001,
            'previous' => round($rB * 100, 2),
            'current' => round($rA * 100, 2),
        ];
    }

    private function interestCountBetween(Carbon $start, Carbon $end): int
    {
        return (int) DB::table('interests')->whereBetween('created_at', [$start, $end])->count();
    }

    private function chatCountBetween(Carbon $start, Carbon $end): int
    {
        return (int) DB::table('conversations')->whereBetween('created_at', [$start, $end])->count();
    }

    private function insightPriorityRank(string $priority): int
    {
        return match ($priority) {
            'high' => 0,
            'medium' => 1,
            'low' => 2,
            default => 3,
        };
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function insightActionsLowConversion(): array
    {
        return [
            ['label' => 'Create coupon', 'url' => route('admin.coupons.create')],
            ['label' => 'Edit plan pricing', 'url' => route('admin.plans.index')],
        ];
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function insightActionsRevenueDrop(): array
    {
        return [
            ['label' => 'Send notification', 'url' => route('admin.notifications.index')],
            ['label' => 'Create discount', 'url' => route('admin.coupons.index')],
        ];
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function insightActionsHighRisk(): array
    {
        return [
            ['label' => 'Review abuse reports', 'url' => route('admin.abuse-reports.index')],
            ['label' => 'Photo moderation queue', 'url' => route('admin.photo-moderation.index')],
        ];
    }

    /**
     * Previous window of equal length ending immediately before {@code $start}.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function resolvePreviousPeriod(Carbon $start, Carbon $end): array
    {
        $seconds = max(1, $end->getTimestamp() - $start->getTimestamp());
        $prevEnd = $start->copy()->subSecond();
        $prevStart = $prevEnd->copy()->subSeconds($seconds);

        return [$prevStart, $prevEnd];
    }

    /**
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function buildTimeBuckets(string $range, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $buckets = [];

        if ($range === self::RANGE_TODAY) {
            $dayStart = $rangeStart->copy()->startOfDay();
            for ($h = 0; $h < 24; $h++) {
                $bStart = $dayStart->copy()->addHours($h);
                $bEnd = $bStart->copy()->endOfHour();
                if ($bStart->gt($rangeEnd)) {
                    break;
                }
                if ($bEnd->gt($rangeEnd)) {
                    $bEnd = $rangeEnd->copy();
                }
                $buckets[] = [$bStart, $bEnd];
            }

            return $buckets !== [] ? $buckets : [[$rangeStart->copy(), $rangeEnd->copy()]];
        }

        $start = $rangeStart->copy()->startOfDay();
        $end = $rangeEnd->copy();
        $days = max(1, (int) $start->diffInDays($end) + 1);
        $step = max(1, (int) ceil($days / 60));

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $bEnd = $cursor->copy()->addDays($step)->subSecond();
            if ($bEnd->gt($end)) {
                $bEnd = $end->copy();
            }
            $buckets[] = [$cursor->copy(), $bEnd->copy()];
            $cursor->addDays($step)->startOfDay();
        }

        return $buckets !== [] ? $buckets : [[$rangeStart->copy(), $rangeEnd->copy()]];
    }

    public function getRiskAlerts(): array
    {
        return $this->remember('risk', 'global', function (): array {
            $maxInterest = max(1, (int) config('admin_dashboard.risk.max_interests_per_day', 30));

            $dupUsers = $this->duplicatePhoneUserIds();
            $noPhotoUsers = $this->noPublicPhotoUserIds();
            $highActivityUsers = $this->highInterestSenderUserIds($maxInterest);
            $reportedUsers = $this->openReportTargetUserIds();

            $scores = [];
            $flags = [];

            $add = function (array $ids, string $type, int $weight) use (&$scores, &$flags): void {
                foreach ($ids as $id) {
                    $uid = (int) $id;
                    $scores[$uid] = ($scores[$uid] ?? 0) + $weight;
                    $flags[$uid][] = $type;
                }
            };

            $add($dupUsers, 'duplicate_phone', 40);
            $add($noPhotoUsers, 'no_photo', 25);
            $add($highActivityUsers, 'high_activity', 25);
            $add($reportedUsers, 'reported', 35);

            $manual = [];
            if (Schema::hasTable('user_flags')) {
                $manual = UserFlag::query()
                    ->where('source', 'manual')
                    ->get(['user_id', 'type', 'score']);
                foreach ($manual as $row) {
                    $uid = (int) $row->user_id;
                    $scores[$uid] = ($scores[$uid] ?? 0) + (int) $row->score;
                    $flags[$uid][] = 'manual:'.$row->type;
                }
            }

            arsort($scores);

            $alerts = [];
            foreach ($scores as $userId => $score) {
                $alerts[] = [
                    'user_id' => $userId,
                    'risk_score' => min(100, (int) $score),
                    'flags' => array_values(array_unique($flags[$userId] ?? [])),
                ];
                if (count($alerts) >= 50) {
                    break;
                }
            }

            return [
                'thresholds' => [
                    'max_interests_per_day' => $maxInterest,
                ],
                'alerts' => $alerts,
                'cache_ttl_seconds' => $this->cacheTtl(),
                'generated_at' => now()->toIso8601String(),
            ];
        });
    }

    public function getLiveActions(): array
    {
        return $this->remember('live', 'global', function (): array {
            $todayStart = now()->startOfDay();

            $pendingPhotos = Schema::hasTable('profile_photos')
                ? ProfilePhoto::query()->whereEffectiveOutcome('pending')->count()
                : 0;

            $openReports = AbuseReport::query()->where('status', 'open')->count();

            $newUsersToday = User::query()->where('created_at', '>=', $todayStart)->count();

            $expiring = [];
            if (Schema::hasTable('subscriptions')) {
                $until = now()->copy()->addDays(2)->endOfDay();
                $expiring = Subscription::query()
                    ->with(['user:id,name,email,mobile', 'plan:id,name,slug,tier'])
                    ->where('status', Subscription::STATUS_ACTIVE)
                    ->whereNotNull('ends_at')
                    ->whereBetween('ends_at', [now(), $until])
                    ->orderBy('ends_at')
                    ->limit(40)
                    ->get()
                    ->map(fn (Subscription $s) => [
                        'subscription_id' => $s->id,
                        'user_id' => $s->user_id,
                        'user' => $s->user ? [
                            'id' => $s->user->id,
                            'name' => $s->user->name,
                            'email' => $s->user->email,
                            'mobile' => $s->user->mobile,
                        ] : null,
                        'plan' => $s->plan ? [
                            'id' => $s->plan->id,
                            'name' => $s->plan->name,
                            'slug' => $s->plan->slug,
                            'tier' => $s->plan->tier,
                        ] : null,
                        'ends_at' => $s->ends_at?->toIso8601String(),
                    ])
                    ->all();
            }

            return [
                'pending_photo_approvals' => $pendingPhotos,
                'reported_users_open' => $openReports,
                'new_users_today' => $newUsersToday,
                'expiring_subscriptions_next_2_days' => $expiring,
                'cache_ttl_seconds' => $this->cacheTtl(),
                'generated_at' => now()->toIso8601String(),
            ];
        });
    }

    private function distinctPaidUsersWithSubscriptionCreatedBetween(Carbon $start, Carbon $end): int
    {
        return (int) Subscription::query()
            ->where('status', '!=', Subscription::STATUS_PENDING)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COUNT(DISTINCT user_id) as c')
            ->value('c');
    }

    private function subscriptionRevenueSum(Carbon $from, Carbon $to): float
    {
        if (! Schema::hasTable('subscriptions') || ! Schema::hasTable('plans')) {
            return 0.0;
        }

        $q = DB::table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->leftJoin('plan_prices', 'plan_prices.id', '=', 'subscriptions.plan_price_id')
            ->where('subscriptions.status', '!=', Subscription::STATUS_PENDING)
            ->whereBetween('subscriptions.created_at', [$from, $to]);

        return (float) $q->sum(DB::raw(
            'COALESCE(plan_prices.price, plans.list_price_rupees, plans.price, 0)'
        ));
    }

    /**
     * @return array<int, array{name: string, slug: string|null, tier: int|null, revenue: float}>
     */
    private function revenueGroupedByPlanBetween(Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('subscriptions')) {
            return [];
        }

        $rows = DB::table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->leftJoin('plan_prices', 'plan_prices.id', '=', 'subscriptions.plan_price_id')
            ->where('subscriptions.status', '!=', Subscription::STATUS_PENDING)
            ->whereBetween('subscriptions.created_at', [$start, $end])
            ->groupBy('plans.id', 'plans.name', 'plans.slug', 'plans.tier')
            ->select([
                'plans.id',
                'plans.name',
                'plans.slug',
                'plans.tier',
                DB::raw('SUM(COALESCE(plan_prices.price, plans.list_price_rupees, plans.price, 0)) as revenue'),
            ])
            ->orderByDesc('revenue')
            ->get();

        return $rows->map(fn ($r) => [
            'plan_id' => (int) $r->id,
            'name' => (string) $r->name,
            'slug' => $r->slug,
            'tier' => isset($r->tier) ? (int) $r->tier : null,
            'revenue' => round((float) $r->revenue, 2),
        ])->all();
    }

    private function sessionDistinctLoginCountBetween(Carbon $start, Carbon $end): int
    {
        if (! Schema::hasTable('sessions')) {
            return 0;
        }

        $t0 = $start->getTimestamp();
        $t1 = $end->getTimestamp();

        return (int) DB::table('sessions')
            ->whereNotNull('user_id')
            ->whereBetween('last_activity', [$t0, $t1])
            ->distinct()
            ->count('user_id');
    }

    private function countDistinctUsersFromInterestsBetween(Carbon $start, Carbon $end): int
    {
        return (int) DB::table('interests')
            ->join('matrimony_profiles as sp', 'sp.id', '=', 'interests.sender_profile_id')
            ->whereNull('sp.deleted_at')
            ->whereBetween('interests.created_at', [$start, $end])
            ->selectRaw('COUNT(DISTINCT sp.user_id) as c')
            ->value('c');
    }

    private function countDistinctUsersFromConversationsBetween(Carbon $start, Carbon $end): int
    {
        return (int) DB::table('conversations')
            ->join('matrimony_profiles as p', 'p.id', '=', 'conversations.created_by_profile_id')
            ->whereNull('p.deleted_at')
            ->whereBetween('conversations.created_at', [$start, $end])
            ->selectRaw('COUNT(DISTINCT p.user_id) as c')
            ->value('c');
    }

    private function countDistinctUsersWithPaidSubscriptionBetween(Carbon $start, Carbon $end): int
    {
        return (int) Subscription::query()
            ->where('status', '!=', Subscription::STATUS_PENDING)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COUNT(DISTINCT user_id) as c')
            ->value('c');
    }

    /**
     * @param  array<string, int>  $stages
     * @return array<string, float|null>
     */
    private function funnelDropoffs(array $stages): array
    {
        $keys = ['signups', 'profile_completed_active', 'interest_sent', 'chat_started', 'payment_done'];
        $out = [];
        for ($i = 1; $i < count($keys); $i++) {
            $prev = $keys[$i - 1];
            $cur = $keys[$i];
            $p = $stages[$prev] ?? 0;
            $c = $stages[$cur] ?? 0;
            $label = $prev.'_to_'.$cur;
            if ($p <= 0) {
                $out[$label] = null;
            } else {
                $out[$label] = round((1 - min($c, $p) / $p) * 100, 2);
            }
        }

        return $out;
    }

    /**
     * @return array<int, int>
     */
    private function duplicatePhoneUserIds(): array
    {
        $dupMobiles = DB::table('users')
            ->select('mobile')
            ->whereNotNull('mobile')
            ->where('mobile', '!=', '')
            ->groupBy('mobile')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('mobile');

        if ($dupMobiles->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('mobile', $dupMobiles->all())
            ->pluck('id')
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function noPublicPhotoUserIds(): array
    {
        if (! Schema::hasTable('profile_photos')) {
            return MatrimonyProfile::query()
                ->where(function ($q): void {
                    $q->whereNull('profile_photo')->orWhere('profile_photo', '');
                })
                ->pluck('user_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return MatrimonyProfile::query()
            ->where(function ($q): void {
                $q->whereNull('profile_photo')->orWhere('profile_photo', '');
            })
            ->whereDoesntHave('photos', function ($q): void {
                $q->effectivelyApproved();
            })
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function highInterestSenderUserIds(int $maxPerDay): array
    {
        $todayStart = now()->startOfDay();

        $rows = DB::table('interests')
            ->select('sender_profile_id', DB::raw('COUNT(*) as c'))
            ->where('created_at', '>=', $todayStart)
            ->groupBy('sender_profile_id')
            ->having('c', '>', $maxPerDay)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $profileIds = $rows->pluck('sender_profile_id')->all();

        return MatrimonyProfile::query()
            ->whereIn('id', $profileIds)
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function openReportTargetUserIds(): array
    {
        return AbuseReport::query()
            ->where('abuse_reports.status', 'open')
            ->join('matrimony_profiles', 'matrimony_profiles.id', '=', 'abuse_reports.reported_profile_id')
            ->whereNull('matrimony_profiles.deleted_at')
            ->distinct()
            ->pluck('matrimony_profiles.user_id')
            ->filter()
            ->values()
            ->all();
    }
}
